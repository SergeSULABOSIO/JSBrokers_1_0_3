<?php

namespace App\Tests\Workspace;

use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Portefeuille;
use App\Entity\Utilisateur;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests fonctionnels des actions spéciales « portefeuille » de la rubrique Clients :
 *  - picker de portefeuille cible (mode affecter / transférer, portefeuille actuel
 *    marqué « Actuel » sans bouton d'action) ;
 *  - affectation d'un client libre, refus du portefeuille actuel (409), transfert ;
 *  - retrait non destructif (client détaché, pas supprimé ; 404 au second appel) ;
 *  - exposition des actions conditionnelles dans le canevas (data-condition-*) et de
 *    l'attribut calculé hasPortefeuille (booléen strict) qui pilote leur visibilité.
 *
 * On agit en tant que PROPRIÉTAIRE de l'entreprise (bypass du contrôle d'accès) pour
 * isoler la logique testée. Chaque test crée ses données et les nettoie.
 */
class ClientPortefeuilleActionsTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-cpa-owner@test.local';
    private const PASSWORD = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit ClientPfActions SARL';

    private const PF_A = 'PHPUNIT-CPA-PF-ALPHA';
    private const PF_B = 'PHPUNIT-CPA-PF-BETA';
    private const GEST_A = 'PHPUNIT-CPA-GEST-A';
    private const GEST_B = 'PHPUNIT-CPA-GEST-B';
    private const CLI_IN = 'PHPUNIT-CPA-CLI-IN';
    private const CLI_LIBRE = 'PHPUNIT-CPA-CLI-LIBRE';

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

    private function user(string $email): Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $nom = self::ENTREPRISE_NOM;

        $conn->executeStatement(
            "UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e",
            ['e' => self::OWNER_EMAIL]
        );

        // Ordre des FK : client → portefeuille → invite → entreprise → utilisateur.
        $conn->executeStatement("DELETE c FROM client c JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
        $conn->executeStatement("DELETE pf FROM portefeuille pf JOIN entreprise e ON pf.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
        $conn->executeStatement("DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
        $conn->executeStatement("DELETE FROM entreprise WHERE nom = :nom", ['nom' => $nom]);
        $conn->executeStatement("DELETE FROM utilisateur WHERE email = :e", ['e' => self::OWNER_EMAIL]);
    }

    /**
     * Jeu de données : entreprise + propriétaire connecté, deux portefeuilles (A et B,
     * gestionnaires distincts), un client rattaché à A et un client libre.
     *
     * @return array{owner: Invite, entreprise: Entreprise, pfA: Portefeuille, pfB: Portefeuille, clientIn: Client, clientLibre: Client}
     */
    private function seed(): array
    {
        $em = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $ownerUser = new Utilisateur();
        $ownerUser->setEmail(self::OWNER_EMAIL);
        $ownerUser->setNom('PHPUnit Owner');
        $ownerUser->setVerified(true);
        $ownerUser->setPassword($hasher->hashPassword($ownerUser, self::PASSWORD));
        $em->persist($ownerUser);

        $entreprise = new Entreprise();
        $entreprise->setNom(self::ENTREPRISE_NOM);
        $entreprise->setLicence('LIC-TEST');
        $entreprise->setAdresse('1 rue du Test');
        $entreprise->setTelephone('+243000000000');
        $entreprise->setRccm('RCCM-TEST');
        $entreprise->setIdnat('IDNAT-TEST');
        $entreprise->setNumimpot('IMP-TEST');
        $entreprise->setUtilisateur($ownerUser);
        $ownerUser->setConnectedTo($entreprise);
        $em->persist($entreprise);

        $ownerInvite = new Invite();
        $ownerInvite->setNom('Administrateur');
        $ownerInvite->setUtilisateur($ownerUser);
        $ownerInvite->setEntreprise($entreprise);
        $ownerInvite->setProprietaire(true);
        $em->persist($ownerInvite);

        $gestA = new Invite();
        $gestA->setNom(self::GEST_A);
        $gestA->setEntreprise($entreprise);
        $gestA->setProprietaire(false);
        $em->persist($gestA);

        $gestB = new Invite();
        $gestB->setNom(self::GEST_B);
        $gestB->setEntreprise($entreprise);
        $gestB->setProprietaire(false);
        $em->persist($gestB);

        $pfA = new Portefeuille();
        $pfA->setNom(self::PF_A);
        $pfA->setGestionnaire($gestA);
        $pfA->setEntreprise($entreprise);
        $em->persist($pfA);

        $pfB = new Portefeuille();
        $pfB->setNom(self::PF_B);
        $pfB->setGestionnaire($gestB);
        $pfB->setEntreprise($entreprise);
        $em->persist($pfB);

        $clientIn = new Client();
        $clientIn->setNom(self::CLI_IN);
        $clientIn->setExonere(false);
        $clientIn->setEntreprise($entreprise);
        $pfA->addClient($clientIn);
        $em->persist($clientIn);

        $clientLibre = new Client();
        $clientLibre->setNom(self::CLI_LIBRE);
        $clientLibre->setExonere(false);
        $clientLibre->setEntreprise($entreprise);
        $em->persist($clientLibre);

        $em->flush();

        // Le KernelBrowser partage l'EM du test : on recharge les entités pour que les
        // relations soient lues depuis la base comme dans une vraie requête.
        $ids = [
            'owner' => $ownerInvite->getId(),
            'entreprise' => $entreprise->getId(),
            'pfA' => $pfA->getId(),
            'pfB' => $pfB->getId(),
            'clientIn' => $clientIn->getId(),
            'clientLibre' => $clientLibre->getId(),
        ];
        $em->clear();

        return [
            'owner' => $em->getRepository(Invite::class)->find($ids['owner']),
            'entreprise' => $em->getRepository(Entreprise::class)->find($ids['entreprise']),
            'pfA' => $em->getRepository(Portefeuille::class)->find($ids['pfA']),
            'pfB' => $em->getRepository(Portefeuille::class)->find($ids['pfB']),
            'clientIn' => $em->getRepository(Client::class)->find($ids['clientIn']),
            'clientLibre' => $em->getRepository(Client::class)->find($ids['clientLibre']),
        ];
    }

    public function testPickerAffecterModeForFreeClient(): void
    {
        ['clientLibre' => $libre, 'pfA' => $pfA, 'pfB' => $pfB] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $crawler = $this->client->request('GET', sprintf('/admin/client/api/%d/portefeuille-picker', $libre->getId()));
        $this->assertResponseIsSuccessful('Le picker de portefeuilles doit se charger.');
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString('Affecter «', $html, 'Le titre doit être en mode affectation.');
        $this->assertStringContainsString(self::CLI_LIBRE, $html, 'Le titre doit rappeler le client concerné.');
        // Les deux portefeuilles sont proposés avec un bouton d'action, aucun badge « Actuel ».
        foreach ([$pfA, $pfB] as $pf) {
            $row = $crawler->filter(sprintf('[data-picker-row][data-portefeuille-id="%d"]', $pf->getId()));
            $this->assertGreaterThan(0, $row->filter('[data-picker-affect]')->count(), sprintf('Le portefeuille %s doit être affectable.', $pf->getNom()));
        }
        $this->assertStringNotContainsString('jsb-picker-chip--current', $html, 'Aucun portefeuille « Actuel » pour un client libre.');
        $this->assertStringContainsString('Affecter ici', $html);
    }

    public function testPickerTransfertModeMarksCurrentPortefeuille(): void
    {
        ['clientIn' => $clientIn, 'pfA' => $pfA, 'pfB' => $pfB] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $crawler = $this->client->request('GET', sprintf('/admin/client/api/%d/portefeuille-picker', $clientIn->getId()));
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString('Transférer «', $html, 'Le titre doit être en mode transfert.');
        $this->assertStringContainsString(self::PF_A, $html, 'Le portefeuille actuel doit être rappelé.');
        $this->assertStringContainsString(self::GEST_A, $html, 'Le gestionnaire actuel doit être rappelé.');

        // Portefeuille ACTUEL : badge texte, aucun bouton d'action (prévention des erreurs).
        $rowA = $crawler->filter(sprintf('[data-picker-row][data-portefeuille-id="%d"]', $pfA->getId()));
        $this->assertSame(0, $rowA->filter('[data-picker-affect]')->count(), 'Le portefeuille actuel ne doit pas être une cible.');
        $this->assertStringContainsString('Actuel', $rowA->text(), 'Le portefeuille actuel doit porter le badge « Actuel ».');

        // Autre portefeuille : bouton « Transférer ici ».
        $rowB = $crawler->filter(sprintf('[data-picker-row][data-portefeuille-id="%d"]', $pfB->getId()));
        $this->assertGreaterThan(0, $rowB->filter('[data-picker-affect]')->count());
        $this->assertStringContainsString('Transférer ici', $rowB->text());
    }

    public function testAffecterFreeClientAndRefuseCurrentPortefeuille(): void
    {
        ['clientLibre' => $libre, 'pfA' => $pfA] = $this->seed();
        $libreId = $libre->getId();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // Affectation d'un client libre : succès, message explicite.
        $this->client->request('PUT', sprintf('/admin/client/api/%d/affecter-portefeuille/%d', $libreId, $pfA->getId()));
        $this->assertResponseIsSuccessful("L'affectation d'un client libre doit réussir.");
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('affecté au portefeuille', $payload['message']);

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(Client::class)->find($libreId);
        $this->assertSame($pfA->getId(), $reloaded->getPortefeuille()?->getId(), 'Le client doit être rattaché au portefeuille cible.');

        // Ré-affectation au MÊME portefeuille : refusée (409), message clair.
        $this->client->request('PUT', sprintf('/admin/client/api/%d/affecter-portefeuille/%d', $libreId, $pfA->getId()));
        $this->assertResponseStatusCodeSame(409, 'Le portefeuille actuel ne doit pas être une cible valide.');
    }

    public function testTransfertChangesPortefeuilleWithExplicitMessage(): void
    {
        ['clientIn' => $clientIn, 'pfA' => $pfA, 'pfB' => $pfB] = $this->seed();
        $clientInId = $clientIn->getId();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('PUT', sprintf('/admin/client/api/%d/affecter-portefeuille/%d', $clientInId, $pfB->getId()));
        $this->assertResponseIsSuccessful('Le transfert vers un autre portefeuille doit réussir.');
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('transféré de', $payload['message'], 'Le message doit expliciter le transfert.');
        $this->assertStringContainsString(self::PF_A, $payload['message']);
        $this->assertStringContainsString(self::PF_B, $payload['message']);

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(Client::class)->find($clientInId);
        $this->assertSame($pfB->getId(), $reloaded->getPortefeuille()?->getId(), 'Le client doit avoir changé de portefeuille.');
    }

    public function testRetirerDetachesClientThen404(): void
    {
        ['clientIn' => $clientIn] = $this->seed();
        $clientInId = $clientIn->getId();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('DELETE', '/admin/client/api/retirer-portefeuille/' . $clientInId);
        $this->assertResponseIsSuccessful('Le retrait du portefeuille doit répondre 200.');
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('retiré du portefeuille', $payload['message']);
        $this->assertStringContainsString("n'est pas supprimé", $payload['message'], 'Le message doit rassurer sur la non-destructivité.');

        $this->em()->clear();
        $survivor = $this->em()->getRepository(Client::class)->find($clientInId);
        $this->assertNotNull($survivor, 'Le client ne doit pas être supprimé par le retrait.');
        $this->assertNull($survivor->getPortefeuille(), 'Le client doit être détaché.');

        // Second appel : le client n'appartient plus à aucun portefeuille → 404.
        $this->client->request('DELETE', '/admin/client/api/retirer-portefeuille/' . $clientInId);
        $this->assertResponseStatusCodeSame(404, 'Sans portefeuille, le retrait doit répondre 404.');
    }

    public function testClientFormCanvasExposesConditionalPortefeuilleActions(): void
    {
        ['clientIn' => $clientIn] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('GET', '/admin/client/api/get-form/' . $clientIn->getId());
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString('portefeuille-picker', $html, 'Les actions Affecter/Transférer doivent pointer le picker.');
        $this->assertStringContainsString('retirer-portefeuille', $html, 'L\'action Retirer doit pointer la route de retrait.');
        $this->assertStringContainsString('data-condition-field="hasPortefeuille"', $html, 'Les actions doivent porter leur condition pour le filtrage côté dialogue.');
    }

    public function testHasPortefeuilleCalculatedIndicator(): void
    {
        ['clientIn' => $clientIn, 'clientLibre' => $libre] = $this->seed();

        $canvasBuilder = static::getContainer()->get(CanvasBuilder::class);

        $canvasBuilder->loadAllCalculatedValues($clientIn);
        $this->assertTrue($clientIn->hasPortefeuille, 'Le client rattaché doit exposer hasPortefeuille = true.');
        $this->assertSame(self::PF_A, $clientIn->portefeuilleNom);

        $canvasBuilder->loadAllCalculatedValues($libre);
        $this->assertFalse($libre->hasPortefeuille, 'Le client libre doit exposer hasPortefeuille = false (booléen, jamais null).');
        $this->assertNull($libre->portefeuilleNom, 'Sans portefeuille, la ligne secondaire masque l\'information (null).');
    }
}
