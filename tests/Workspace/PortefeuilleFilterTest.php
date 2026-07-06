<?php

namespace App\Tests\Workspace;

use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Feedback;
use App\Entity\Invite;
use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\Piste;
use App\Entity\Portefeuille;
use App\Entity\Tache;
use App\Entity\Utilisateur;
use App\Services\Search\PortefeuilleScope;
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
    private const SIN_IN = 'PHPUNIT-SIN-IN';
    private const SIN_OUT = 'PHPUNIT-SIN-OUT';
    private const OFF_IN = 'PHPUNIT-OFF-IN';
    private const OFF_OUT = 'PHPUNIT-OFF-OUT';
    private const TACHE_IN = 'PHPUNIT-TACHE-IN';
    private const TACHE_OUT = 'PHPUNIT-TACHE-OUT';
    private const FB_IN = 'PHPUNIT-FB-IN';
    private const FB_OUT = 'PHPUNIT-FB-OUT';

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

        // Entités « sinistre / tâche / feedback » sans colonne entreprise : on les purge par
        // leur marqueur de test, en respectant l'ordre des FK (feedback → tache → offre →
        // notification), AVANT les entités du chaînage principal.
        $conn->executeStatement("DELETE FROM feedback WHERE description LIKE 'PHPUNIT-%'");
        $conn->executeStatement("DELETE FROM tache WHERE description LIKE 'PHPUNIT-%'");
        $conn->executeStatement("DELETE FROM offre_indemnisation_sinistre WHERE beneficiaire LIKE 'PHPUNIT-%'");
        $conn->executeStatement("DELETE FROM notification_sinistre WHERE reference_sinistre LIKE 'PHPUNIT-%'");

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
        [$pisteIn, , ] = $this->makeChain($em, $entreprise, $assureur, $clientIn, self::PISTE_IN, self::COT_IN, self::POL_IN, self::SIN_IN, self::OFF_IN, self::TACHE_IN, self::FB_IN);
        // Chaîne "dehors" (témoin)
        $this->makeChain($em, $entreprise, $assureur, $clientOut, self::PISTE_OUT, self::COT_OUT, self::POL_OUT, self::SIN_OUT, self::OFF_OUT, self::TACHE_OUT, self::FB_OUT);

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
     * Construit un chaînage complet rattaché à un client :
     * Piste → Cotation → Avenant, plus NotificationSinistre (assuré = client) →
     * OffreIndemnisationSinistre, ainsi qu'une Tâche (liée à la piste) → Feedback.
     * Sert à tester le périmètre portefeuille sur toutes ces rubriques.
     *
     * @return array{0: Piste, 1: Cotation, 2: Avenant, 3: NotificationSinistre, 4: OffreIndemnisationSinistre, 5: Tache, 6: Feedback}
     */
    private function makeChain(EntityManagerInterface $em, Entreprise $e, Assureur $ass, Client $client, string $pisteNom, string $cotNom, string $refPolice, string $sinRef, string $offBenef, string $tacheDesc, string $fbDesc): array
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

        // Sinistre déclaré par le client (assuré), puis offre d'indemnisation associée.
        // Ces entités portent une entreprise (AuditableTrait, colonne non nulle).
        $notif = new NotificationSinistre();
        $notif->setAssure($client);
        $notif->setOccuredAt(new \DateTimeImmutable('now'));
        $notif->setReferenceSinistre($sinRef);
        $notif->setEntreprise($e);
        $em->persist($notif);

        $offre = new OffreIndemnisationSinistre();
        $offre->setNotificationSinistre($notif);
        $offre->setMontantPayable(100.0);
        $offre->setBeneficiaire($offBenef);
        $offre->setNom($offBenef);
        $offre->setEntreprise($e);
        $em->persist($offre);

        // Tâche liée à la piste (parent possible parmi d'autres) et son feedback.
        $tache = new Tache();
        $tache->setDescription($tacheDesc);
        $tache->setToBeEndedAt(new \DateTimeImmutable('+7 days'));
        $tache->setClosed(false);
        $tache->setPiste($piste);
        $tache->setEntreprise($e);
        $em->persist($tache);

        $feedback = new Feedback();
        $feedback->setDescription($fbDesc);
        $feedback->setType(Feedback::TYPE_CALL);
        $feedback->setTache($tache);
        $feedback->setEntreprise($e);
        $em->persist($feedback);

        return [$piste, $cotation, $avenant, $notif, $offre, $tache, $feedback];
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

    /**
     * Nouveau : le sélecteur autocomplété de relation filtre par IDENTITÉ (opérateur '='
     * + id de l'entité liée, sans targetField), et non plus par LIKE approximatif.
     */
    public function testFilterClientsByPortefeuilleId(): void
    {
        ['owner' => $owner, 'entreprise' => $e, 'portefeuille' => $pf] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $res = $this->dynamicQuery('client', $owner->getId(), $e->getId(), [
            // Forme émise par le nouveau picker : { operator: '=', value: <id>, label: '…' }.
            'portefeuille' => ['operator' => '=', 'value' => $pf->getId(), 'label' => self::PF_NOM],
        ]);

        $this->assertSame(1, $res['pagination']['totalItems'], 'Le filtre relation par id doit renvoyer le seul client rattaché.');
        $this->assertStringContainsString(self::CLI_IN, $res['html']);
        $this->assertStringNotContainsString(self::CLI_OUT, $res['html']);
    }

    /**
     * Nouveau : l'endpoint générique d'autocomplétion renvoie les entités de l'entité cible
     * correspondant à la saisie, scopées à l'entreprise du workspace, au format Tom Select.
     */
    public function testSearchAutocompleteReturnsScopedEntities(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // Requête ciblée : seul le client « IN » correspond.
        $this->client->request('GET', sprintf(
            '/espacedetravail/api/search-autocomplete/%d/%d?entity=Client&displayField=nom&query=%s',
            $owner->getId(), $e->getId(), rawurlencode(self::CLI_IN)
        ));
        $this->assertResponseIsSuccessful("L'endpoint d'autocomplétion doit répondre 200.");
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('results', $payload);
        $this->assertCount(1, $payload['results'], 'Un seul client correspond à la requête ciblée.');
        $this->assertSame(self::CLI_IN, $payload['results'][0]['text']);

        // Requête par préfixe : les deux clients de l'entreprise remontent.
        $this->client->request('GET', sprintf(
            '/espacedetravail/api/search-autocomplete/%d/%d?entity=Client&displayField=nom&query=%s',
            $owner->getId(), $e->getId(), rawurlencode('PHPUNIT-CLI')
        ));
        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertCount(2, $payload['results'], 'Les deux clients de l\'entreprise doivent être proposés.');
    }

    /**
     * Nouveau : au premier chargement de la rubrique Clients, un périmètre par défaut
     * limite la liste aux clients des portefeuilles gérés par l'invité connecté, et chaque
     * ligne affiche le nom du portefeuille de rattachement.
     */
    public function testClientListDefaultsToConnectedInvitePortefeuilleScope(): void
    {
        ['owner' => $owner, 'entreprise' => $e, 'portefeuille' => $pf] = $this->seed();
        // On fait de l'invité connecté (propriétaire) le gestionnaire du portefeuille de test.
        $pf->setGestionnaire($this->em()->getRepository(Invite::class)->find($owner->getId()));
        $this->em()->flush();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $crawler = $this->client->request(
            'GET',
            sprintf('/admin/client/index/%d/%d', $owner->getId(), $e->getId())
        );
        $this->assertResponseIsSuccessful('La rubrique Clients doit se charger.');
        $html = (string) $this->client->getResponse()->getContent();

        // Périmètre par défaut : seul le client rattaché au portefeuille géré est listé.
        $this->assertStringContainsString(self::CLI_IN, $html, 'Le client du portefeuille géré doit apparaître.');
        $this->assertStringNotContainsString(self::CLI_OUT, $html, 'Le client hors portefeuille ne doit pas apparaître par défaut.');

        // Part A : la ligne du client affiche le nom de son portefeuille (ligne secondaire).
        $this->assertStringContainsString(self::PF_NOM, $html, 'Le nom du portefeuille doit être affiché sur la ligne du client.');

        // Amorçage du filtre par défaut côté frontend + critère synthétique « Mon portefeuille ».
        // On décode les entités HTML (attributs json_encode|e('html_attr')) avant l'assertion.
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('__mon_portefeuille__', $decoded, 'Le critère de périmètre par défaut doit être transmis au frontend.');
        $this->assertStringContainsString('Mon portefeuille', $decoded, 'Le critère synthétique « Mon portefeuille » doit être exposé dans le canevas de recherche.');
    }

    /**
     * Nouveau : le périmètre « Mon portefeuille » s'applique à toutes les rubriques liées,
     * quelle que soit la profondeur/indirection du lien vers le portefeuille — y compris les
     * entités polymorphes (Tâche, Feedback) reliées à une Piste/Cotation/Sinistre. Sous ce
     * périmètre (portefeuille géré par l'invité), seule la branche « dedans » remonte.
     */
    public function testPortefeuilleScopeAppliesToAllLinkedRubrics(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();
        $gestionnaire = $this->em()->getRepository(Invite::class)->findOneBy(['nom' => self::GEST_NOM]);
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // Le portefeuille de test est géré par $gestionnaire : chaque rubrique liée ne doit
        // exposer, sous ce périmètre, que l'unique élément « dedans ».
        $scope = [PortefeuilleScope::CRITERION_KEY => ['operator' => '=', 'value' => $gestionnaire->getId()]];

        $rubrics = [
            'client', 'piste', 'cotation', 'avenant',
            'notificationsinistre', 'offreindemnisationsinistre', 'tache', 'feedback',
        ];
        foreach ($rubrics as $serverRoot) {
            $res = $this->dynamicQuery($serverRoot, $owner->getId(), $e->getId(), $scope);
            $this->assertSame(
                1,
                $res['pagination']['totalItems'],
                sprintf('Rubrique « %s » : le périmètre « Mon portefeuille » doit ne retenir que l\'élément rattaché.', $serverRoot)
            );
        }
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
        $collHtml = $payload['html'] ?? '';
        $this->assertStringContainsString(self::CLI_IN, $collHtml, 'La ligne du client rattaché doit être rendue.');
        // L'action de suppression doit être un RETRAIT (détachement), pas une suppression BD,
        // et il ne doit pas y avoir de bouton d'édition.
        $this->assertStringContainsString('Retirer du portefeuille', $collHtml, "Le bouton doit être libellé « Retirer du portefeuille ».");
        $this->assertStringNotContainsString('collection#editItem', $collHtml, "La collection des clients ne doit pas exposer de bouton d'édition.");

        // Le « retrait » d'un client DÉTACHE (portefeuille = null) sans supprimer le client.
        $this->client->request('DELETE', sprintf('/admin/portefeuille/api/%d/detach-client/%d', $pfId, $clientInId));
        $this->assertResponseIsSuccessful('Le détachement doit répondre 200.');

        $this->em()->clear();
        $survivor = $this->em()->getRepository(Client::class)->find($clientInId);
        $this->assertNotNull($survivor, 'Le client ne doit pas être supprimé par le retrait du portefeuille.');
        $this->assertNull($survivor->getPortefeuille(), 'Le client doit être détaché du portefeuille.');
    }

    /**
     * Ergonomie : en collection embarquée (usage « dialog »), la colonne Id. + case à cocher
     * (sélection en lot) est supprimée — aucune action groupée n'existe dans ce contexte.
     * Le composant liste étant PARTAGÉ avec toutes les autres rubriques (clients, cotations,
     * bordereaux, sinistres…), ce test verrouille le comportement pour ce contexte.
     */
    public function testDialogCollectionOmitsIdCheckboxColumn(): void
    {
        ['portefeuille' => $pf] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('GET', sprintf('/admin/portefeuille/api/%d/clients/dialog', $pf->getId()));
        $this->assertResponseIsSuccessful('La collection « clients » du portefeuille doit se charger.');
        $html = json_decode((string) $this->client->getResponse()->getContent(), true)['html'] ?? '';

        // La case à cocher de ligne et la case « tout sélectionner » ne doivent plus être rendues.
        $this->assertStringNotContainsString('name="check_[]"', $html, 'La collection embarquée ne doit plus rendre de case à cocher de ligne.');
        $this->assertStringNotContainsString('list-manager#toggleAll', $html, 'La collection embarquée ne doit plus rendre la case « tout sélectionner ».');
        $this->assertStringNotContainsString('Tout sélectionner', $html, 'Le libellé de sélection globale ne doit plus apparaître.');
        // Mais la ligne du client et son action de retrait restent bien présentes.
        $this->assertStringContainsString(self::CLI_IN, $html, 'La ligne du client rattaché doit rester rendue.');
        $this->assertStringContainsString('Retirer du portefeuille', $html, 'L\'action de retrait doit rester présente.');
    }

    /**
     * Non-régression : les LISTES PRINCIPALES (usage générique) conservent la colonne Id. +
     * case à cocher, car la sélection en lot (suppression/opérations groupées) y est légitime.
     */
    public function testMainListKeepsIdCheckboxColumn(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request(
            'GET',
            sprintf('/espacedetravail/api/load-component/%d/%d', $owner->getId(), $e->getId()),
            ['component' => '_view_manager_production.html.twig', 'entity' => 'Portefeuille']
        );
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString('name="check_[]"', $html, 'La liste principale doit conserver la case à cocher de ligne.');
        $this->assertStringContainsString('list-manager#toggleAll', $html, 'La liste principale doit conserver la case « tout sélectionner ».');
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

    public function testClientPickerOffersAddForFreeAndRemoveForCurrent(): void
    {
        ['portefeuille' => $pf, 'clientIn' => $clientIn, 'clientOut' => $clientOut] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $crawler = $this->client->request('GET', sprintf('/admin/portefeuille/api/%d/client-picker', $pf->getId()));
        $this->assertResponseIsSuccessful('La boîte de sélection de clients doit se charger.');

        // Statistiques d'entête : 1 client rattaché (clientIn) sur 2 au total (in + out).
        $this->assertSame('1', trim($crawler->filter('[data-picker-count-current]')->text()), 'Le picker doit indiquer 1 client dans ce portefeuille.');
        $this->assertStringContainsString('au total', $crawler->filter('.jsb-picker-stats')->text(), 'Le picker doit afficher le total de clients.');
        // Compteur d'éléments affichés (résultat du filtrage) : initialement = total (2).
        $this->assertSame('2', trim($crawler->filter('[data-picker-count-shown]')->text()), 'Le picker doit afficher le nombre d\'éléments listés.');

        // Client SANS portefeuille : action « Ajouter » visible, pas de « Retirer » visible.
        $freeRow = $crawler->filter(sprintf('[data-picker-row][data-client-id="%d"]', $clientOut->getId()));
        $this->assertGreaterThan(0, $freeRow->filter('[data-picker-attach]:not([hidden])')->count(), "Le client libre doit exposer une action d'ajout visible.");
        $this->assertSame(0, $freeRow->filter('[data-picker-detach]:not([hidden])')->count(), 'Le client libre ne doit pas exposer de retrait visible.');

        // Client de CE portefeuille : action « Retirer » visible, portant l'icône de
        // suppression standard (comme les autres boutons de suppression de l'app).
        $currentRow = $crawler->filter(sprintf('[data-picker-row][data-client-id="%d"]', $clientIn->getId()));
        $this->assertGreaterThan(0, $currentRow->filter('[data-picker-detach]:not([hidden])')->count(), 'Le client du portefeuille doit exposer un retrait visible.');
        $this->assertGreaterThan(0, $currentRow->filter('[data-picker-detach] svg')->count(), "Le bouton « Retirer » doit porter une icône de suppression.");
        $this->assertSame(0, $currentRow->filter('[data-picker-attach]:not([hidden])')->count(), "Le client déjà rattaché ne doit pas exposer d'ajout visible.");
    }

    public function testAttachClientRequiresUnassignedClient(): void
    {
        ['portefeuille' => $pf, 'clientIn' => $clientIn, 'clientOut' => $clientOut] = $this->seed();
        $clientOutId = $clientOut->getId();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // Rattachement d'un client libre : succès, la relation est posée.
        $this->client->request('PUT', sprintf('/admin/portefeuille/api/%d/attach-client/%d', $pf->getId(), $clientOutId));
        $this->assertResponseIsSuccessful('Le rattachement d\'un client sans portefeuille doit réussir.');

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(Client::class)->find($clientOutId);
        $this->assertNotNull($reloaded->getPortefeuille());
        $this->assertSame($pf->getId(), $reloaded->getPortefeuille()->getId(), 'Le client doit être rattaché au portefeuille.');

        // Rattachement d'un client DÉJÀ dans un portefeuille : refusé (409), pas de vol.
        $this->client->request('PUT', sprintf('/admin/portefeuille/api/%d/attach-client/%d', $pf->getId(), $clientIn->getId()));
        $this->assertResponseStatusCodeSame(409, "Un client déjà rattaché ne doit pas pouvoir être réaffecté via l'ajout.");
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

        // Les COMPTEURS (format « Nombre ») s'affichent en entier, sans décimales parasites :
        // le portefeuille de test compte 1 client → « 1 », jamais « 1,00 ».
        $this->assertStringNotContainsString('1,00</dd>', $html, 'Les compteurs ne doivent plus afficher de décimales.');
        $this->assertMatchesRegularExpression(
            '/Nb\. Clients<\/dt>\s*<dd class="attr-value">\s*1\s*</',
            $html,
            'Le compteur « Nb. Clients » doit être rendu en entier (1).'
        );
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
