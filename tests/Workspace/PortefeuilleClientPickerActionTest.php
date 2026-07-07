<?php

namespace App\Tests\Workspace;

use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Portefeuille;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests fonctionnels de l'action spéciale « Ajouter des clients au portefeuille » de la
 * rubrique Portefeuilles (menu contextuel / toolbar / volet du dialogue d'édition) :
 *  - exposition de l'action dans le canevas de formulaire du portefeuille (événement
 *    ui:portefeuille.client-picker-request + URL du picker avec %id%) ;
 *  - mode « standalone » du picker de clients (?standalone=1) : le HTML embarque le
 *    contrôleur Stimulus dédié « client-picker » ; sans le paramètre, il reste piloté
 *    par le widget collection (non-régression) ;
 *  - flux d'ajout : chaque PUT réussi persiste immédiatement le rattachement et renvoie
 *    le message affiché en toast ; un client déjà rattaché est refusé (409) ;
 *  - retrait depuis le picker : détachement non destructif avec message.
 *
 * On agit en tant que PROPRIÉTAIRE de l'entreprise (bypass du contrôle d'accès) pour
 * isoler la logique testée. Chaque test crée ses données et les nettoie.
 */
class PortefeuilleClientPickerActionTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-pcp-owner@test.local';
    private const PASSWORD = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit PfClientPicker SARL';

    private const PF_NOM = 'PHPUNIT-PCP-PF';
    private const GEST_NOM = 'PHPUNIT-PCP-GEST';
    private const CLI_IN = 'PHPUNIT-PCP-CLI-IN';
    private const CLI_LIBRE = 'PHPUNIT-PCP-CLI-LIBRE';

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
     * Jeu de données : entreprise + propriétaire connecté, un gestionnaire, un
     * portefeuille, un client rattaché et un client libre.
     *
     * @return array{owner: Invite, entreprise: Entreprise, pf: Portefeuille, clientIn: Client, clientLibre: Client}
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

        $gestionnaire = new Invite();
        $gestionnaire->setNom(self::GEST_NOM);
        $gestionnaire->setEntreprise($entreprise);
        $gestionnaire->setProprietaire(false);
        $em->persist($gestionnaire);

        $pf = new Portefeuille();
        $pf->setNom(self::PF_NOM);
        $pf->setGestionnaire($gestionnaire);
        $pf->setEntreprise($entreprise);
        $em->persist($pf);

        $clientIn = new Client();
        $clientIn->setNom(self::CLI_IN);
        $clientIn->setExonere(false);
        $clientIn->setEntreprise($entreprise);
        $pf->addClient($clientIn);
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
            'pf' => $pf->getId(),
            'clientIn' => $clientIn->getId(),
            'clientLibre' => $clientLibre->getId(),
        ];
        $em->clear();

        return [
            'owner' => $em->getRepository(Invite::class)->find($ids['owner']),
            'entreprise' => $em->getRepository(Entreprise::class)->find($ids['entreprise']),
            'pf' => $em->getRepository(Portefeuille::class)->find($ids['pf']),
            'clientIn' => $em->getRepository(Client::class)->find($ids['clientIn']),
            'clientLibre' => $em->getRepository(Client::class)->find($ids['clientLibre']),
        ];
    }

    /**
     * Le canevas de formulaire du portefeuille expose l'action spéciale : c'est elle
     * qui alimente les TROIS canaux (toolbar de liste, menu contextuel, volet du
     * dialogue d'édition). Sans condition : toujours proposée pour un portefeuille.
     */
    public function testFormCanvasExposesAddClientsAction(): void
    {
        ['pf' => $pf] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('GET', '/admin/portefeuille/api/get-form/' . $pf->getId());
        $this->assertResponseIsSuccessful();
        // Les paramètres du canevas sont sérialisés dans des attributs HTML : on décode
        // les entités avant d'inspecter le JSON embarqué.
        $html = html_entity_decode((string) $this->client->getResponse()->getContent(), ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString('ui:portefeuille.client-picker-request', $html, "L'action doit émettre l'événement d'ouverture du picker de clients.");
        $this->assertStringContainsString('/admin/portefeuille/api/%id%/client-picker', $html, "L'action doit pointer la route du picker avec le placeholder %id%.");
        $this->assertStringContainsString('Ajouter des clients au portefeuille', $html, "Le libellé de l'action doit être exposé.");
    }

    /**
     * En mode « standalone » (ouverture directe depuis la liste), le picker embarque son
     * contrôleur Stimulus dédié ; sans le paramètre (widget collection du dialogue
     * d'édition), il n'en embarque PAS (non-régression : il resterait sinon piloté deux
     * fois — par le widget ET par le contrôleur autonome).
     */
    public function testClientPickerStandaloneModeEmbedsDedicatedController(): void
    {
        ['pf' => $pf] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('GET', sprintf('/admin/portefeuille/api/%d/client-picker?standalone=1', $pf->getId()));
        $this->assertResponseIsSuccessful('Le picker en mode standalone doit se charger.');
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('data-controller="client-picker"', $html, 'Le mode standalone doit embarquer le contrôleur « client-picker ».');
        $this->assertStringContainsString('data-picker-error', $html, "La zone d'erreur inline du picker doit être rendue.");

        $this->client->request('GET', sprintf('/admin/portefeuille/api/%d/client-picker', $pf->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString(
            'data-controller="client-picker"',
            (string) $this->client->getResponse()->getContent(),
            'Sans standalone=1, le picker reste piloté par le widget collection (aucun contrôleur embarqué).'
        );
    }

    /**
     * Flux d'ajout depuis le picker : chaque PUT réussi persiste IMMÉDIATEMENT le
     * rattachement (la BD est à jour avant même la fermeture du picker) et renvoie le
     * message affiché en toast. Un client déjà rattaché est refusé (409).
     */
    public function testAttachPersistsImmediatelyAndReturnsToastMessage(): void
    {
        ['pf' => $pf, 'clientIn' => $clientIn, 'clientLibre' => $libre] = $this->seed();
        $libreId = $libre->getId();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('PUT', sprintf('/admin/portefeuille/api/%d/attach-client/%d', $pf->getId(), $libreId));
        $this->assertResponseIsSuccessful("L'ajout d'un client libre doit réussir.");
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('ajouté au portefeuille', $payload['message'] ?? '', 'Le message du toast doit confirmer l\'ajout.');

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(Client::class)->find($libreId);
        $this->assertSame($pf->getId(), $reloaded->getPortefeuille()?->getId(), 'Le rattachement doit être persisté immédiatement.');

        // Client déjà rattaché (même à CE portefeuille) : refus explicite, pas de doublon.
        $this->client->request('PUT', sprintf('/admin/portefeuille/api/%d/attach-client/%d', $pf->getId(), $clientIn->getId()));
        $this->assertResponseStatusCodeSame(409, 'Un client déjà rattaché ne doit pas être ré-ajoutable.');
    }

    /**
     * Retrait depuis le picker (bouton « Retirer » des clients du portefeuille) :
     * détachement non destructif, avec message pour le toast.
     */
    public function testDetachFromPickerIsNonDestructive(): void
    {
        ['pf' => $pf, 'clientIn' => $clientIn] = $this->seed();
        $clientInId = $clientIn->getId();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('DELETE', sprintf('/admin/portefeuille/api/%d/detach-client/%d', $pf->getId(), $clientInId));
        $this->assertResponseIsSuccessful('Le retrait doit répondre 200.');
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('détaché du portefeuille', $payload['message'] ?? '', 'Le message du toast doit confirmer le retrait.');

        $this->em()->clear();
        $survivor = $this->em()->getRepository(Client::class)->find($clientInId);
        $this->assertNotNull($survivor, 'Le client ne doit pas être supprimé par le retrait.');
        $this->assertNull($survivor->getPortefeuille(), 'Le client doit être détaché du portefeuille.');
    }
}
