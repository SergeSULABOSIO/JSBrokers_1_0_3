<?php

namespace App\Tests\Workspace;

use App\Entity\ConditionPartage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * LE BLOC « RISQUES CIBLÉS » NE PARAÎT QUE S'IL A UN OBJET — EN CRÉATION COMME EN ÉDITION.
 *
 * Le critère sur le risque a trois valeurs : ne cibler AUCUN risque, ne partager QUE sur
 * certains, ou ne PAS partager sur certains. Dans le premier cas la liste des risques n'a
 * rien à désigner.
 *
 * La règle est DÉCLARÉE dans le canvas, pas codée dans un contrôleur dédié : le dialogue
 * possède déjà un moteur de visibilité conditionnelle (dialog-instance_controller). Ce
 * test exige donc la déclaration dans le HTML rendu, à l'endroit exact que ce moteur lit.
 *
 * ── DEUX INCIDENTS, DEUX ASSERTIONS ─────────────────────────────────────────────────
 * 1. Un contrôleur sur mesure ciblait `[name$="[critereRisque]"]`. Or les FormTypes du
 *    workspace rendent `getBlockPrefix()` VIDE : le champ s'appelle « critereRisque »,
 *    sans crochets. Le sélecteur ne matchait jamais — sans la moindre erreur en console.
 * 2. Le bloc restait invisible EN CRÉATION quoi qu'on coche : une collection est masquée
 *    d'office tant que le parent n'a pas d'id, et cette rangée `d-none` l'emportait sur
 *    tout. D'où l'assertion sur les DEUX modes : c'est le mode création qui a échoué le
 *    plus longtemps, et c'est celui qu'on oublie de vérifier.
 *
 * Les deux pannes étaient SILENCIEUSES : un formulaire parfaitement normal, avec un champ
 * qui refusait seulement d'obéir. C'est ce que ce test rend impossible à réintroduire.
 */
class ConditionPartageDialogueRenduTest extends WebTestCase
{
    private const EMAIL = 'phpunit-cpdlg@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit CPDialogue SARL';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :m', ['m' => self::EMAIL]);
        foreach (['condition_partage', 'invite'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :m', ['m' => self::EMAIL]);
    }

    /** @return iterable<string, array{0:bool}> */
    public static function modesDuDialogue(): iterable
    {
        yield 'création' => [true];
        yield 'édition' => [false];
    }

    /**
     * @dataProvider modesDuDialogue
     */
    public function testLeBlocDesRisquesEstGouverneParLeCritere(bool $enCreation): void
    {
        $ids = $this->semer();
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::EMAIL]));

        $url = '/admin/conditionpartage/api/get-form' . ($enCreation ? '' : '/' . $ids['conditionId']);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $html = $this->client->getResponse()->getContent();

        // 1. Le champ source, sous le nom RÉELLEMENT rendu — sans crochets.
        self::assertMatchesRegularExpression(
            '/name="critereRisque"/',
            $html,
            'Le moteur lit form.elements["critereRisque"] : tout autre nom le rend aveugle.',
        );

        // 2. La colonne des risques déclare sa condition, au niveau que le moteur traite.
        $colonne = $this->colonneDesRisques($html);
        self::assertNotNull($colonne, 'La colonne « Risques ciblés » ne déclare aucune condition de visibilité.');
        self::assertStringContainsString('critereRisque', $colonne, 'La condition n\'écoute pas le bon champ.');
        self::assertStringContainsString('&quot;operator&quot;:&quot;in&quot;', $colonne);
        // Les deux critères qui DÉSIGNENT des risques, et eux seuls.
        self::assertStringContainsString(
            '[' . ConditionPartage::CRITERE_EXCLURE_TOUS_CES_RISQUES . ',' . ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES . ']',
            $colonne,
            'Le bloc doit paraître pour « exclure » ET « n\'inclure que », jamais pour « aucun risque ciblé ».',
        );

        // 3. LA RÉGRESSION DU MODE CRÉATION : la rangée ne doit pas être masquée d'office,
        //    sans quoi la condition ci-dessus ne serait jamais visible à l'écran.
        self::assertStringNotContainsString(
            'class="row d-none"',
            $this->rangeeDesRisques($html) ?? '',
            'La rangée des risques est masquée d\'office : le bloc resterait invisible quoi qu\'on coche.',
        );
    }

    /** Le fragment de la colonne qui porte la condition de visibilité, s'il existe. */
    private function colonneDesRisques(string $html): ?string
    {
        $pos = strpos($html, 'data-field-code="produits"');
        if ($pos === false) {
            return null;
        }
        // La déclaration vit sur la colonne, en AMONT de la carte du champ.
        $amont = substr($html, max(0, $pos - 1500), min($pos, 1500));
        $debut = strrpos($amont, 'data-visibility-conditions-value=');

        return $debut === false ? null : substr($amont, $debut);
    }

    /** La rangée qui contient les risques ciblés, pour vérifier qu'elle n'est pas masquée. */
    private function rangeeDesRisques(string $html): ?string
    {
        $pos = strpos($html, 'data-field-code="produits"');
        if ($pos === false) {
            return null;
        }
        $amont = substr($html, max(0, $pos - 2000), min($pos, 2000));
        $debut = strrpos($amont, '<div class="row');

        return $debut === false ? null : substr($amont, $debut);
    }

    /** @return array{conditionId:int} */
    private function semer(): array
    {
        $em = $this->em();

        $user = (new Utilisateur())->setEmail(self::EMAIL)->setNom('CPDialogue')->setVerified(true);
        $user->setPassword('peu importe : on se connecte par loginUser()');
        $em->persist($user);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')->setAdresse('1 rue')
            ->setTelephone('+2430000')->setRccm('RCCM')->setIdnat('IDNAT')->setNumimpot('IMP');
        $entreprise->setUtilisateur($user);
        $em->persist($entreprise);
        $user->setConnectedTo($entreprise);

        $invite = (new Invite())->setNom('Proprio')->setProprietaire(true);
        $invite->setUtilisateur($user)->setEntreprise($entreprise);
        $em->persist($invite);

        $agent = (new Invite())->setNom('Alice')->setProprietaire(false);
        $agent->setEntreprise($entreprise);
        $em->persist($agent);

        $condition = (new ConditionPartage())->setNom('Rétrocommission — Alice')
            ->setFormule(ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL)->setSeuil(0.0)
            ->setTaux(15.0)
            ->setCritereRisque(ConditionPartage::CRITERE_PAS_RISQUES_CIBLES)
            ->setAgent($agent);
        $condition->setEntreprise($entreprise);
        $em->persist($condition);

        $em->flush();
        $ids = ['conditionId' => (int) $condition->getId()];
        $em->clear();

        return $ids;
    }
}
