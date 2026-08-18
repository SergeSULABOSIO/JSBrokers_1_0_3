<?php

namespace App\Tests\Workspace;

use App\Entity\ConditionPartage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * LE DIALOGUE D'UNE CONDITION DE PARTAGE, TEL QU'IL ARRIVE DANS LE NAVIGATEUR.
 *
 * La visibilité conditionnelle du bloc « Risques ciblés » repose sur deux accroches que
 * le serveur doit poser dans le HTML, et sur elles seules :
 *   - `data-controller` sur le <form>, sans quoi aucun comportement ne se branche ;
 *   - `data-field-code="produits"` sur la carte du champ, sans quoi le contrôleur ne
 *     trouve pas le bloc à masquer.
 *
 * Aucune des deux ne se voit à l'œil nu, et leur absence est SILENCIEUSE : le formulaire
 * s'affiche normalement, le champ reste simplement toujours visible. D'où ce test, qui
 * les exige dans le HTML rendu plutôt que dans l'intention du gabarit.
 */
class ConditionPartageDialogueRenduTest extends WebTestCase
{
    private const EMAIL = 'phpunit-cpdlg@test.local';
    private const PASSWORD = 'Test1234!';
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

    public function testLeDialogueDeclareSonControleurEtMarqueLeBlocDesRisques(): void
    {
        $ids = $this->semer();
        $this->client->loginUser($this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::EMAIL]));

        $this->client->request('GET', '/admin/conditionpartage/api/get-form/' . $ids['conditionId']);
        self::assertResponseIsSuccessful();

        $html = $this->client->getResponse()->getContent();

        self::assertStringContainsString(
            'data-controller="condition-partage-fields"',
            $html,
            'Sans ce contrôleur sur le <form>, aucune visibilité conditionnelle ne se branche.',
        );
        self::assertStringContainsString(
            'data-field-code="produits"',
            $html,
            'Sans cette accroche, le contrôleur ne trouve pas le bloc « Risques ciblés » à masquer.',
        );
        // Les radios que le contrôleur écoute — et SOUS QUEL NOM.
        preg_match_all('/name="([^"]*critereRisque[^"]*)"/', $html, $m);
        fwrite(STDERR, "
[NOMS critereRisque] " . implode(' | ', array_unique($m[1])) . "
");
        preg_match_all('/data-field-code="([^"]+)"/', $html, $m2);
        fwrite(STDERR, "[CARTES] " . implode(' | ', array_unique($m2[1])) . "
");
        self::assertStringContainsString('critereRisque', $html);
    }

    /** @return array{conditionId:int} */
    private function semer(): array
    {
        $em = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = (new Utilisateur())->setEmail(self::EMAIL)->setNom('CPDialogue')->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
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
