<?php

namespace App\Tests\Workspace;

use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Portefeuille;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests fonctionnels de bout en bout de la fonctionnalité « Portefeuille client » :
 *  - CRUD du portefeuille (formulaire, création avec gestionnaire + clients, suppression
 *    qui détache les clients sans les supprimer) ;
 *  - filtrage par portefeuille des 4 moteurs de recherche demandés (Clients, Pistes,
 *    Propositions/Cotations, Avenants), via des chemins de relation à plusieurs niveaux ;
 *  - non-régression : un critère existant renvoie le même périmètre qu'avant ;
 *  - bloc « Renouvellements » du tableau de bord : affichage du portefeuille + gestionnaire.
 *
 * On agit en tant que PROPRIÉTAIRE de l'entreprise (bypass total du contrôle d'accès)
 * pour isoler la logique métier testée. Chaque test crée ses données et les nettoie.
 */
class PortefeuilleFilterTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-pf-owner@test.local';
    private const PASSWORD = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit Portefeuille SARL';

    private const PF_NOM = 'PHPUNIT-PF-ALPHA';
    private const GEST_NOM = 'PHPUNIT-GESTIONNAIRE';
    private const CLI_IN = 'PHPUNIT-CLI-IN';
    private const CLI_OUT = 'PHPUNIT-CLI-OUT';
    private const PISTE_IN = 'PHPUNIT-PISTE-IN';
    private const PISTE_OUT = 'PHPUNIT-PISTE-OUT';
    private const COT_IN = 'PHPUNIT-COT-IN';
    private const COT_OUT = 'PHPUNIT-COT-OUT';
    private const POL_IN = 'PHPUNIT-POL-IN';
    private const POL_OUT = 'PHPUNIT-POL-OUT';

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

        // Ordre des FK : avenant → cotation → piste → client → portefeuille → assureur
        //               → invite → entreprise → utilisateur.
        $conn->executeStatement("DELETE a FROM avenant a JOIN entreprise e ON a.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
        $conn->executeStatement("DELETE c FROM cotation c JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
        $conn->executeStatement("DELETE p FROM piste p JOIN entreprise e ON p.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
        $conn->executeStatement("DELETE c FROM client c JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
        $conn->executeStatement("DELETE pf FROM portefeuille pf JOIN entreprise e ON pf.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
        $conn->executeStatement("DELETE a FROM assureur a JOIN entreprise e ON a.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
        $conn->executeStatement("DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :nom", ['nom' => $nom]);
        $conn->executeStatement("DELETE FROM entreprise WHERE nom = :nom", ['nom' => $nom]);
        $conn->executeStatement("DELETE FROM utilisateur WHERE email = :e", ['e' => self::OWNER_EMAIL]);
    }

    /**
     * Jeu de données : entreprise + propriétaire, un invité gestionnaire, un portefeuille,
     * un client DEDANS et un client DEHORS, et pour le client dedans une chaîne
     * Piste → Cotation → Avenant (idem pour le client dehors, comme témoin négatif).
     *
     * @return array{owner: Invite, entreprise: Entreprise, portefeuille: Portefeuille, clientIn: Client, clientOut: Client}
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

        // Invité désigné comme gestionnaire de compte (sans compte utilisateur nécessaire).
        $gestionnaire = new Invite();
        $gestionnaire->setNom(self::GEST_NOM);
        $gestionnaire->setEntreprise($entreprise);
        $gestionnaire->setProprietaire(false);
        $em->persist($gestionnaire);

        $portefeuille = new Portefeuille();
        $portefeuille->setNom(self::PF_NOM);
        $portefeuille->setGestionnaire($gestionnaire);
        $portefeuille->setEntreprise($entreprise);
        $em->persist($portefeuille);

        $clientIn = $this->makeClient($entreprise, self::CLI_IN);
        // addClient() synchronise les DEUX côtés (client.portefeuille + collection en
        // mémoire), pour que Portefeuille::getClients() soit cohérent dans le même EM.
        $portefeuille->addClient($clientIn);
        $em->persist($clientIn);

        $clientOut = $this->makeClient($entreprise, self::CLI_OUT);
        $em->persist($clientOut);

        $assureur = new Assureur();
        $assureur->setNom('PHPUNIT-ASS');
        $assureur->setEmail('ass@test.local');
        $assureur->setNumimpot('ASS-IMP');
        $assureur->setIdnat('ASS-IDNAT');
        $assureur->setRccm('ASS-RCCM');
        $assureur->setEntreprise($entreprise);
        $em->persist($assureur);

        // Chaîne "dedans"
        [$pisteIn, , ] = $this->makeChain($em, $entreprise, $assureur, $clientIn, self::PISTE_IN, self::COT_IN, self::POL_IN);
        // Chaîne "dehors" (témoin)
        $this->makeChain($em, $entreprise, $assureur, $clientOut, self::PISTE_OUT, self::COT_OUT, self::POL_OUT);

        $em->flush();

        return [
            'owner' => $ownerInvite,
            'entreprise' => $entreprise,
            'portefeuille' => $portefeuille,
            'clientIn' => $clientIn,
            'clientOut' => $clientOut,
        ];
    }

    private function makeClient(Entreprise $entreprise, string $nom): Client
    {
        $client = new Client();
        $client->setNom($nom);
        $client->setExonere(false);
        $client->setEntreprise($entreprise);

        return $client;
    }

    /**
     * @return array{0: Piste, 1: Cotation, 2: Avenant}
     */
    private function makeChain(EntityManagerInterface $em, Entreprise $e, Assureur $ass, Client $client, string $pisteNom, string $cotNom, string $refPolice): array
    {
        $piste = new Piste();
        $piste->setNom($pisteNom);
        $piste->setClient($client);
        $piste->setTypeAvenant(Piste::AVENANT_SOUSCRIPTION);
        $piste->setDescriptionDuRisque('Risque de test');
        $piste->setExercice(2026);
        $piste->setEntreprise($e);
        $em->persist($piste);

        $cotation = new Cotation();
        $cotation->setNom($cotNom);
        $cotation->setDuree(365);
        $cotation->setPiste($piste);
        $cotation->setAssureur($ass);
        $cotation->setEntreprise($e);
        $em->persist($cotation);

        $avenant = new Avenant();
        $avenant->setStartingAt(new \DateTimeImmutable('now'));
        $avenant->setEndingAt(new \DateTimeImmutable('+30 days'));
        $avenant->setDescription('Avenant de test');
        $avenant->setReferencePolice($refPolice);
        $avenant->setCotation($cotation);
        $avenant->setEntreprise($e);
        $em->persist($avenant);

        return [$piste, $cotation, $avenant];
    }

    /**
     * Lance une requête de recherche dynamique et renvoie la charge JSON décodée.
     *
     * @param array<string, mixed> $criteria
     * @return array{pagination: array, html: string}
     */
    private function dynamicQuery(string $serverRoot, int $idInvite, int $idEntreprise, array $criteria): array
    {
        $this->client->request(
            'POST',
            sprintf('/admin/%s/api/dynamic-query/%d/%d', $serverRoot, $idInvite, $idEntreprise),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['criteria' => $criteria, 'parentContext' => null, 'page' => 1])
        );
        $this->assertResponseIsSuccessful(sprintf('La recherche %s doit répondre 200.', $serverRoot));

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return array<string, mixed> */
    private function pfCriterion(string $code): array
    {
        return [$code => ['operator' => 'LIKE', 'value' => self::PF_NOM, 'targetField' => 'nom']];
    }

    public function testFilterClientsByPortefeuille(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $res = $this->dynamicQuery('client', $owner->getId(), $e->getId(), $this->pfCriterion('portefeuille'));

        $this->assertSame(1, $res['pagination']['totalItems'], 'Un seul client appartient au portefeuille.');
        $this->assertStringContainsString(self::CLI_IN, $res['html']);
        $this->assertStringNotContainsString(self::CLI_OUT, $res['html']);
    }

    public function testFilterPistesByPortefeuille(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $res = $this->dynamicQuery('piste', $owner->getId(), $e->getId(), $this->pfCriterion('client.portefeuille'));

        $this->assertSame(1, $res['pagination']['totalItems'], 'Une seule piste relève du portefeuille (via son client).');
        $this->assertStringContainsString(self::PISTE_IN, $res['html']);
        $this->assertStringNotContainsString(self::PISTE_OUT, $res['html']);
    }

    public function testFilterCotationsByPortefeuille(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $res = $this->dynamicQuery('cotation', $owner->getId(), $e->getId(), $this->pfCriterion('piste.client.portefeuille'));

        $this->assertSame(1, $res['pagination']['totalItems'], 'Une seule proposition (cotation) relève du portefeuille.');
        $this->assertStringContainsString(self::COT_IN, $res['html']);
        $this->assertStringNotContainsString(self::COT_OUT, $res['html']);
    }

    public function testFilterAvenantsByPortefeuille(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $res = $this->dynamicQuery('avenant', $owner->getId(), $e->getId(), $this->pfCriterion('cotation.piste.client.portefeuille'));

        $this->assertSame(1, $res['pagination']['totalItems'], 'Un seul avenant relève du portefeuille.');
        $this->assertStringContainsString(self::POL_IN, $res['html']);
        $this->assertStringNotContainsString(self::POL_OUT, $res['html']);
    }

    /**
     * Non-régression : un critère mono-segment existant (nom) doit continuer de fonctionner
     * exactement comme avant l'ajout du support des chemins multi-niveaux.
     */
    public function testExistingSingleFieldSearchStillWorks(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // Les deux clients partagent le préfixe 'PHPUNIT-CLI'.
        $res = $this->dynamicQuery('client', $owner->getId(), $e->getId(), [
            'nom' => ['operator' => 'LIKE', 'value' => 'PHPUNIT-CLI'],
        ]);

        $this->assertSame(2, $res['pagination']['totalItems'], 'Le filtre nom doit renvoyer les deux clients (aucune régression).');
    }

    public function testCreateAndDeletePortefeuilleDetachesClients(): void
    {
        ['owner' => $owner, 'entreprise' => $e, 'clientOut' => $clientOut] = $this->seed();
        $gestionnaire = $this->em()->getRepository(Invite::class)->findOneBy(['nom' => self::GEST_NOM]);
        $clientOutId = $clientOut->getId();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // Le formulaire de création répond et rend le canevas du portefeuille.
        $this->client->request('GET', '/admin/portefeuille/api/get-form');
        $this->assertResponseIsSuccessful();
        $formHtml = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Fiche portefeuille', $formHtml, 'Le canevas de formulaire du portefeuille doit être rendu.');
        $this->assertStringContainsString('Gestionnaire de compte', $formHtml, 'Le champ gestionnaire doit être présent dans le formulaire.');

        // Création d'un portefeuille (nom + gestionnaire). Les clients sont gérés à part
        // via le widget collection (mapped=false), pas dans la soumission du portefeuille.
        $this->client->request('POST', '/admin/portefeuille/api/submit', [
            'idEntreprise' => $e->getId(),
            'idInvite'     => $owner->getId(),
            'nom'          => 'PHPUNIT-PF-CREATED',
            'gestionnaire' => $gestionnaire->getId(),
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Enregistr', (string) $this->client->getResponse()->getContent());

        $created = $this->em()->getRepository(Portefeuille::class)->findOneBy(['nom' => 'PHPUNIT-PF-CREATED']);
        $this->assertNotNull($created);

        // On rattache un client à ce portefeuille (comme le ferait la fiche client)…
        $clientOut->setPortefeuille($created);
        $this->em()->flush();
        $this->em()->clear();

        // …puis on supprime le portefeuille : le client survit, simplement détaché
        // (FK ON DELETE SET NULL).
        $this->client->request('DELETE', '/admin/portefeuille/api/delete/' . $created->getId());
        $this->assertResponseIsSuccessful();

        $this->em()->clear();
        $this->assertNull($this->em()->getRepository(Portefeuille::class)->find($created->getId()), 'Le portefeuille doit être supprimé.');
        $survivor = $this->em()->getRepository(Client::class)->find($clientOutId);
        $this->assertNotNull($survivor, 'Le client ne doit pas être supprimé avec le portefeuille.');
        $this->assertNull($survivor->getPortefeuille(), 'Le client doit être détaché du portefeuille supprimé.');
    }

    public function testClientsRenderedAsCollectionAndDetachIsNonDestructive(): void
    {
        ['owner' => $owner, 'entreprise' => $e, 'portefeuille' => $pf, 'clientIn' => $clientIn] = $this->seed();
        $pfId = $pf->getId();
        $clientInId = $clientIn->getId();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // La liste des clients du portefeuille est servie comme une collection (widget) :
        // l'usage « dialog » renvoie le décompte et le HTML des lignes rattachées.
        $this->client->request('GET', sprintf('/admin/portefeuille/api/%d/clients/dialog', $pfId));
        $this->assertResponseIsSuccessful('La collection « clients » du portefeuille doit se charger.');
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(1, $payload['itemCount'] ?? null, 'La collection doit compter le client rattaché.');
        $this->assertStringContainsString(self::CLI_IN, $payload['html'] ?? '', 'La ligne du client rattaché doit être rendue.');

        // Le « retrait » d'un client DÉTACHE (portefeuille = null) sans supprimer le client.
        $this->client->request('DELETE', sprintf('/admin/portefeuille/api/%d/detach-client/%d', $pfId, $clientInId));
        $this->assertResponseIsSuccessful('Le détachement doit répondre 200.');

        $this->em()->clear();
        $survivor = $this->em()->getRepository(Client::class)->find($clientInId);
        $this->assertNotNull($survivor, 'Le client ne doit pas être supprimé par le retrait du portefeuille.');
        $this->assertNull($survivor->getPortefeuille(), 'Le client doit être détaché du portefeuille.');
    }

    public function testWorkspaceLoadsPortefeuilleComponent(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // La rubrique « Portefeuilles » de l'espace de travail doit être routée vers son
        // contrôleur (non-régression : « Action de contrôleur non trouvée pour Portefeuille »).
        $this->client->request(
            'GET',
            sprintf('/espacedetravail/api/load-component/%d/%d', $owner->getId(), $e->getId()),
            ['component' => '_view_manager_production.html.twig', 'entity' => 'Portefeuille']
        );

        $this->assertResponseIsSuccessful('La rubrique Portefeuilles doit être routée vers PortefeuilleController.');
        $this->assertStringContainsString(self::PF_NOM, (string) $this->client->getResponse()->getContent(), 'La liste des portefeuilles doit contenir le portefeuille de test.');
    }

    public function testEditFormShowsClientLikeCalculatedAttributes(): void
    {
        ['portefeuille' => $pf] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // Le volet gauche du formulaire d'édition doit afficher les attributs calculés,
        // repris de l'entité Client (agrégés sur le portefeuille).
        $this->client->request('GET', '/admin/portefeuille/api/get-form/' . $pf->getId());
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString('Attributs calculés', $html, 'Le volet des attributs calculés doit être rendu.');
        foreach (['Nb. Clients', 'Prime Totale', 'Commission TTC', 'Indice de solvabilité'] as $label) {
            $this->assertStringContainsString($label, $html, sprintf('L\'attribut « %s » (comme sur la fiche client) doit être présent.', $label));
        }
    }

    public function testRenewalsBlockShowsPortefeuilleAndGestionnaire(): void
    {
        ['entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('GET', sprintf('/admin/entreprise_dashbord/renewals-fragment/%d', $e->getId()));
        $this->assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        // La ligne du client rattaché doit exposer son portefeuille ET son gestionnaire.
        $this->assertStringContainsString(self::CLI_IN, $html, 'Le renouvellement du client rattaché doit apparaître.');
        $this->assertStringContainsString(self::PF_NOM, $html, 'Le nom du portefeuille doit être affiché sur la ligne.');
        $this->assertStringContainsString(self::GEST_NOM, $html, 'Le gestionnaire de compte doit être affiché sur la ligne.');
    }
}
