<?php

namespace App\Tests\Workspace;

use App\Entity\Assureur;
use App\Entity\Bordereau;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\RolesEnFinance;
use App\Entity\RolesEnProduction;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * DEUX ÉCRITURES QUI NE REGARDAIENT QUE LE CABINET, JAMAIS LE DROIT (recensement des points de
 * création, 2026-10-30).
 *
 * - L'analyse d'un bordereau (`simulate-action`, `simulate-batch-action`) : une ligne
 *   « nouvelle » crée client, risque, piste, proposition, tranche, revenu et avenant ; une ligne
 *   « écart » modifie l'avenant. Il suffisait de LIRE les bordereaux.
 * - La tranche par défaut d'une proposition naissait d'un GET (`/cotation/api/{id}/tranches`),
 *   pour qui pouvait seulement lire.
 */
class EcrituresGardeesParLesDroitsTest extends WebTestCase
{
    private const OWNER = 'phpunit-ecritures-droits-owner@test.local';
    private const GUEST = 'phpunit-ecritures-droits-guest@test.local';
    private const CABINET = 'PHPUnit Ecritures Droits SARL';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
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
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (?, ?)', [self::OWNER, self::GUEST]);
        $id = $conn->fetchOne('SELECT id FROM entreprise WHERE nom = ?', [self::CABINET]);
        if ($id !== false) {
            $tables = $conn->fetchFirstColumn(
                "SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'entreprise_id' AND TABLE_NAME <> 'entreprise'"
            );
            $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach ($tables as $table) {
                    $conn->executeStatement("DELETE FROM `$table` WHERE entreprise_id = ?", [$id]);
                }
                $conn->executeStatement('DELETE FROM entreprise WHERE id = ?', [$id]);
            } finally {
                $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            }
        }
        $conn->executeStatement('DELETE FROM utilisateur WHERE email IN (?, ?)', [self::OWNER, self::GUEST]);
        $this->em()->clear();
    }

    /**
     * Un cabinet, son propriétaire, et un collaborateur qui LIT et ÉCRIT les bordereaux, LIT les
     * tranches et les propositions — sans aucun droit sur les clients, pistes ni avenants.
     *
     * @return array{owner: int, guest: int, bordereau: int, cotation: int, entreprise: int}
     */
    private function semer(): array
    {
        $em = $this->em();
        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('Proprio')->setVerified(true)->setPassword('x');
        $guest = (new Utilisateur())->setEmail(self::GUEST)->setNom('Collaborateur')->setVerified(true)->setPassword('x');
        $em->persist($owner);
        $em->persist($guest);
        $cabinet = (new Entreprise())->setNom(self::CABINET)->setLicence('L')->setAdresse('1 rue')
            ->setTelephone('+243000000099')->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $em->persist($cabinet);
        $owner->setConnectedTo($cabinet);
        $guest->setConnectedTo($cabinet);
        $proprio = (new Invite())->setNom('Proprio')->setUtilisateur($owner)->setEntreprise($cabinet)->setProprietaire(true);
        $invite = (new Invite())->setNom('Collaborateur')->setUtilisateur($guest)->setEntreprise($cabinet)->setProprietaire(false);
        $em->persist($proprio);
        $em->persist($invite);

        $finance = (new RolesEnFinance())->setNom('Bordereaux seulement')
            ->setAccessBordereau([Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE, Invite::ACCESS_MODIFICATION])
            ->setAccessTranche([Invite::ACCESS_LECTURE]);
        $finance->setEntreprise($cabinet);
        $invite->addRolesEnFinance($finance);
        $em->persist($finance);
        $production = (new RolesEnProduction())->setNom('Propositions en lecture')->setAccessCotation([Invite::ACCESS_LECTURE]);
        $production->setEntreprise($cabinet);
        $invite->addRolesEnProduction($production);
        $em->persist($production);

        $assureur = (new Assureur())->setNom('Assureur droits');
        $assureur->setEntreprise($cabinet);
        $em->persist($assureur);
        $bordereau = (new Bordereau())->setNom('Bordereau droits')->setAssureur($assureur)->setReceivedAt(new \DateTimeImmutable('today'))
            ->setReference('BDX-DROITS-001')->setPeriodeDebut(new \DateTimeImmutable('-30 days'))->setPeriodeFin(new \DateTimeImmutable('today'));
        $bordereau->setEntreprise($cabinet)->setInvite($proprio);
        $em->persist($bordereau);
        $client = (new Client())->setNom('Client droits')->setExonere(false);
        $client->setEntreprise($cabinet);
        $em->persist($client);
        $piste = (new Piste())->setNom('Piste droits')->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION)->setDescriptionDuRisque('R')
            ->setExercice(2026)->setClient($client);
        $piste->setEntreprise($cabinet)->setInvite($proprio);
        $em->persist($piste);
        $cotation = (new Cotation())->setNom('Proposition droits')->setDuree(12);
        $cotation->setPiste($piste)->setEntreprise($cabinet)->setInvite($proprio);
        $em->persist($cotation);
        $em->flush();

        $s = [
            'owner' => (int) $owner->getId(), 'guest' => (int) $guest->getId(), 'bordereau' => (int) $bordereau->getId(),
            'cotation' => (int) $cotation->getId(), 'entreprise' => (int) $cabinet->getId(),
        ];
        $em->clear();

        return $s;
    }

    private function connecter(int $utilisateur): void
    {
        $this->client->loginUser($this->em()->find(Utilisateur::class, $utilisateur));
    }

    private function compter(string $table, int $entreprise): int
    {
        return (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM `$table` WHERE entreprise_id = ?", [$entreprise]);
    }

    private function ligneNouvelle(): array
    {
        return [
            'action_type' => 'new',
            'row_index'   => 0,
            'excel_data'  => ['nom_client' => 'Client inconnu du cabinet', 'reference_police' => 'POL-DROITS-1', 'risque' => 'INCENDIE'],
        ];
    }

    public function testUneLigneNouvelleExigeLEcritureSurTouteLaChaine(): void
    {
        $s = $this->semer();
        $this->connecter($s['guest']);

        $this->client->request('POST', '/admin/bordereau/api/simulate-action/' . $s['bordereau'], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($this->ligneNouvelle()));

        self::assertResponseStatusCodeSame(403);
        $message = json_decode((string) $this->client->getResponse()->getContent(), true)['message'] ?? '';
        self::assertStringContainsString('Clients', $message, 'Le refus nomme les rubriques qui manquent.');
        self::assertStringContainsString('Avenants', $message);
        foreach (['client' => 1, 'piste' => 1, 'cotation' => 1, 'avenant' => 0, 'tranche' => 0] as $table => $avant) {
            self::assertSame($avant, $this->compter($table, $s['entreprise']), "Rien n'est écrit ($table).");
        }
    }

    public function testLeLotRefuseChaqueLigneSansEcrire(): void
    {
        $s = $this->semer();
        $this->connecter($s['guest']);

        $this->client->request('POST', '/admin/bordereau/api/simulate-batch-action/' . $s['bordereau'], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['items' => [$this->ligneNouvelle()]]));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->compter('avenant', $s['entreprise']));
        self::assertSame(1, $this->compter('client', $s['entreprise']));
    }

    public function testUnEcartExigeLaModificationDeLAvenant(): void
    {
        $s = $this->semer();
        $this->connecter($s['guest']);

        $this->client->request('POST', '/admin/bordereau/api/simulate-action/' . $s['bordereau'], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'action_type' => 'discrepancy', 'row_index' => 0, 'avenant_id' => 1, 'excel_data' => [],
        ]));

        self::assertResponseStatusCodeSame(403);
    }

    public function testLeProprietaireNEstPasArretePourUnDroit(): void
    {
        $s = $this->semer();
        $this->connecter($s['owner']);

        $this->client->request('POST', '/admin/bordereau/api/simulate-action/' . $s['bordereau'], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($this->ligneNouvelle()));

        self::assertNotSame(403, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
    }

    public function testLaTrancheParDefautNeNaitPasDUnGetSansDroitDEcriture(): void
    {
        $s = $this->semer();
        $this->connecter($s['guest']);

        $this->client->request('GET', '/admin/cotation/api/' . $s['cotation'] . '/tranches/generic');
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->compter('tranche', $s['entreprise']), 'Lire les tranches ne doit pas en écrire une.');

        $this->connecter($s['owner']);
        $this->client->request('GET', '/admin/cotation/api/' . $s['cotation'] . '/tranches/generic');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->compter('tranche', $s['entreprise']), 'Qui peut écrire reçoit toujours sa tranche unique par défaut.');
    }
}
