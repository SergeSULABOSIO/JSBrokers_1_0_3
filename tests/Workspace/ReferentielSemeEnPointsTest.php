<?php

namespace App\Tests\Workspace;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Risque;
use App\Entity\TypeRevenu;
use App\Entity\Utilisateur;
use App\Services\ServiceInitialisationEntreprise;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * CONVENTION POINTS jusque dans le RÉFÉRENTIEL SEMÉ.
 *
 * Version20260725110000 avait converti les données en base (fraction → points),
 * mais pas les valeurs écrites en dur dans le code de semis : le référentiel de
 * risques (43 lignes) et les trois types de revenu partaient encore en fractions.
 * Toute entreprise créée depuis démarrait donc avec des commissions divisées par
 * cent — 0,15 % au lieu de 15 % — et l'erreur était invisible : un taux plausible,
 * simplement cent fois trop petit.
 *
 * Ces tests verrouillent les deux bouts (le fichier de référence et le service qui
 * le sème) pour qu'aucune valeur ne puisse y retomber en fraction.
 */
class ReferentielSemeEnPointsTest extends WebTestCase
{
    private const ENT = 'PHPUnit-RefSeme SARL';
    private const OWNER = 'phpunit-refseme-owner@test.local';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER]);
        // Enfants avant parents : autorite_fiscale → taxe, type_revenu → chargement.
        $conn->executeStatement(
            'DELETE af FROM autorite_fiscale af JOIN taxe t ON af.taxe_id = t.id JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n',
            ['n' => self::ENT],
        );
        // Le semis d'une entreprise crée aussi ses types d'absence et la dotation de
        // ses agents : les retirer AVANT l'invité, dont ils dépendent.
        foreach (['mouvement_conge', 'type_absence', 'roles_en_administration', 'risque', 'type_revenu', 'chargement', 'groupe', 'taxe', 'monnaie'] as $table) {
            $conn->executeStatement("DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n", ['n' => self::ENT]);
        }
        $conn->executeStatement('DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    /**
     * Le fichier de référence lui-même : aucune commission ne doit être ≤ 1.
     * Une commission de courtage sous 1 % n'existe pas dans ce référentiel —
     * une valeur ≤ 1 y signale donc une fraction oubliée, jamais un taux réel.
     */
    public function testReferentielDeRisquesEnPoints(): void
    {
        $chemin = static::getContainer()->getParameter('kernel.project_dir') . '/assets/data/risques_defaut.json';
        $this->assertFileExists($chemin);

        $risques = json_decode((string) file_get_contents($chemin), true);
        $this->assertIsArray($risques);
        $this->assertNotEmpty($risques);

        foreach ($risques as $r) {
            $this->assertGreaterThan(
                1,
                (float) $r['commission'],
                sprintf('Le risque « %s » porte une commission de %s : fraction oubliée ?', $r['code'], $r['commission']),
            );
        }
    }

    /**
     * Le semis réel : une entreprise neuve reçoit ses risques et ses types de
     * revenu en POINTS, et getFraction() rend la fraction attendue.
     */
    public function testSemisDUneEntrepriseNeuveEnPoints(): void
    {
        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('P')->setVerified(true);
        $owner->setPassword('x');
        $this->em->persist($owner);
        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('L')->setAdresse('a')->setTelephone('t')
            ->setRccm('r')->setIdnat('i')->setNumimpot('n')->setUtilisateur($owner);
        $this->em->persist($ent);
        $inv = (new Invite())->setNom('O')->setUtilisateur($owner)->setEntreprise($ent)->setProprietaire(true);
        $this->em->persist($inv);
        $this->em->flush();

        static::getContainer()->get(ServiceInitialisationEntreprise::class)->initialiser($ent, $inv);
        $this->em->flush();
        $this->em->clear();

        $ent = $this->em->getRepository(Entreprise::class)->findOneBy(['nom' => self::ENT]);

        $risques = $this->em->getRepository(Risque::class)->findBy(['entreprise' => $ent]);
        $this->assertNotEmpty($risques, 'Le référentiel de risques est semé.');
        foreach ($risques as $risque) {
            $taux = (float) $risque->getPourcentageCommissionSpecifiqueHT();
            $this->assertGreaterThan(1, $taux, sprintf('Risque « %s » semé en fraction.', $risque->getCode()));
        }

        $attendus = ['Commission sur Fronting' => 30.0, 'Frais de consultance' => 5.0, 'Honoraire de gestion' => 2.0];
        foreach ($attendus as $nom => $points) {
            $type = $this->em->getRepository(TypeRevenu::class)->findOneBy(['entreprise' => $ent, 'nom' => $nom]);
            $this->assertNotNull($type, sprintf('Le type de revenu « %s » est semé.', $nom));
            $this->assertSame($points, (float) $type->getPourcentage(), sprintf('« %s » en POINTS.', $nom));
            $this->assertEqualsWithDelta($points / 100, $type->getFraction(), 0.0001);
        }
    }

    /**
     * Les défauts des formulaires de création : un écran qui s'ouvre sur 0,1 %
     * là où il annonçait 10 % est le même piège, en plus discret.
     */
    public function testDefautsDesFormulairesEnPoints(): void
    {
        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('P')->setVerified(true);
        $owner->setPassword('x');
        $this->em->persist($owner);
        $ent = (new Entreprise())->setNom(self::ENT)->setLicence('L')->setAdresse('a')->setTelephone('t')
            ->setRccm('r')->setIdnat('i')->setNumimpot('n')->setUtilisateur($owner);
        $this->em->persist($ent);
        $inv = (new Invite())->setNom('O')->setUtilisateur($owner)->setEntreprise($ent)->setProprietaire(true);
        $this->em->persist($inv);
        $owner->setConnectedTo($ent);
        $this->em->flush();
        $this->client->loginUser($owner);

        foreach ([
            '/admin/risque/api/get-form',
            '/admin/partenaire/api/get-form',
            '/admin/typerevenu/api/get-form',
        ] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful(sprintf('Le formulaire %s s’ouvre.', $url));

            // Le PercentType est en mode « integer » : la valeur du champ EST le
            // nombre de points affiché à l'écran (rendu en locale FR : « 10,00 »).
            $html = (string) $this->client->getResponse()->getContent();
            $this->assertMatchesRegularExpression(
                '/value="10(?:[.,]0+)?"/',
                $html,
                sprintf('Le défaut de %s doit valoir 10 POINTS (10 %%), pas 0,1.', $url),
            );
            $this->assertDoesNotMatchRegularExpression(
                '/value="0[.,]10*"/',
                $html,
                sprintf('%s propose encore une fraction.', $url),
            );
        }
    }
}
