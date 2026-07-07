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
 * Tests fonctionnels des actions spéciales « portefeuille » de la rubrique Invités
 * (pattern Bordereau → Note) :
 *  - endpoint de contexte (mode edit/create selon l'état réel de l'invité) ;
 *  - préremplissage du gestionnaire à la création (parentContext de dialog-instance) ;
 *  - suppression du portefeuille via l'invité (clients détachés, pas supprimés ; 404 sans
 *    portefeuille) ;
 *  - exposition des actions conditionnelles dans le canevas (data-condition-*) et de
 *    l'attribut calculé hasPortefeuille qui pilote leur visibilité.
 *
 * On agit en tant que PROPRIÉTAIRE de l'entreprise (bypass du contrôle d'accès) pour
 * isoler la logique testée. Chaque test crée ses données et les nettoie.
 */
class InvitePortefeuilleActionsTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-ipa-owner@test.local';
    private const PASSWORD = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit InvitePfActions SARL';

    private const PF_NOM = 'PHPUNIT-IPA-PF';
    private const GEST_NOM = 'PHPUNIT-IPA-GESTIONNAIRE';
    private const SANS_PF_NOM = 'PHPUNIT-IPA-SANS-PF';
    private const CLI_NOM = 'PHPUNIT-IPA-CLIENT';

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
     * Jeu de données : entreprise + propriétaire connecté, un invité GESTIONNAIRE d'un
     * portefeuille (avec un client rattaché) et un invité SANS portefeuille.
     *
     * @return array{owner: Invite, entreprise: Entreprise, gestionnaire: Invite, sansPf: Invite, portefeuille: Portefeuille, clientIn: Client}
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

        $sansPf = new Invite();
        $sansPf->setNom(self::SANS_PF_NOM);
        $sansPf->setEntreprise($entreprise);
        $sansPf->setProprietaire(false);
        $em->persist($sansPf);

        $portefeuille = new Portefeuille();
        $portefeuille->setNom(self::PF_NOM);
        $portefeuille->setGestionnaire($gestionnaire);
        $portefeuille->setEntreprise($entreprise);
        $em->persist($portefeuille);

        $clientIn = new Client();
        $clientIn->setNom(self::CLI_NOM);
        $clientIn->setExonere(false);
        $clientIn->setEntreprise($entreprise);
        $portefeuille->addClient($clientIn);
        $em->persist($clientIn);

        $em->flush();

        // Le KernelBrowser partage l'EM du test : on recharge les entités pour que les
        // collections inverses (Invite::portefeuilles) soient des PersistentCollection
        // lazy-loadées depuis la base, comme dans une vraie requête — sinon l'ArrayCollection
        // vide du constructeur masquerait le portefeuille fraîchement créé.
        $ids = [
            'owner' => $ownerInvite->getId(),
            'entreprise' => $entreprise->getId(),
            'gestionnaire' => $gestionnaire->getId(),
            'sansPf' => $sansPf->getId(),
            'portefeuille' => $portefeuille->getId(),
            'clientIn' => $clientIn->getId(),
        ];
        $em->clear();

        return [
            'owner' => $em->getRepository(Invite::class)->find($ids['owner']),
            'entreprise' => $em->getRepository(Entreprise::class)->find($ids['entreprise']),
            'gestionnaire' => $em->getRepository(Invite::class)->find($ids['gestionnaire']),
            'sansPf' => $em->getRepository(Invite::class)->find($ids['sansPf']),
            'portefeuille' => $em->getRepository(Portefeuille::class)->find($ids['portefeuille']),
            'clientIn' => $em->getRepository(Client::class)->find($ids['clientIn']),
        ];
    }

    public function testContextReturnsEditModeForInviteWithPortefeuille(): void
    {
        ['gestionnaire' => $gestionnaire, 'portefeuille' => $pf, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('GET', sprintf(
            '/admin/invite/api/get-portefeuille-context/%d?idEntreprise=%d',
            $gestionnaire->getId(),
            $e->getId()
        ));
        $this->assertResponseIsSuccessful('Le contexte portefeuille doit répondre 200.');
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertSame('edit', $payload['mode'], "L'invité gère un portefeuille : mode édition.");
        $this->assertSame($gestionnaire->getId(), $payload['inviteId']);
        $this->assertSame($pf->getId(), $payload['portefeuille']['id'] ?? null, 'Le portefeuille sérialisé doit être celui de l\'invité.');
        // Le canevas retourné est bien celui du Portefeuille (le dialogue soumettra là-bas).
        $this->assertSame(
            '/admin/portefeuille/api/submit',
            $payload['formCanvas']['parametres']['endpoint_submit_url'] ?? null,
            'Le canevas doit être celui de l\'entité Portefeuille.'
        );
    }

    public function testContextReturnsCreateModeForInviteWithoutPortefeuille(): void
    {
        ['sansPf' => $sansPf, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('GET', sprintf(
            '/admin/invite/api/get-portefeuille-context/%d?idEntreprise=%d',
            $sansPf->getId(),
            $e->getId()
        ));
        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertSame('create', $payload['mode'], "L'invité ne gère aucun portefeuille : mode création.");
        $this->assertSame($sansPf->getId(), $payload['inviteId']);
        $this->assertNull($payload['portefeuille']);
        $this->assertSame(
            '/admin/portefeuille/api/submit',
            $payload['formCanvas']['parametres']['endpoint_submit_url'] ?? null
        );
    }

    public function testGetFormPrefillsGestionnaireFromParentContext(): void
    {
        ['sansPf' => $sansPf] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // Même mécanique que la note pré-remplie depuis un bordereau : dialog-instance
        // transmet le parentContext en query params au get-form du Portefeuille.
        $this->client->request('GET', sprintf(
            '/admin/portefeuille/api/get-form?parent_field_name=gestionnaire&parent_id=%d',
            $sansPf->getId()
        ));
        $this->assertResponseIsSuccessful('Le formulaire de création prérempli doit répondre 200.');
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString(self::SANS_PF_NOM, $html, 'Le gestionnaire prérempli doit être rendu dans le formulaire.');
        $this->assertStringContainsString('Portefeuille de ' . self::SANS_PF_NOM, $html, 'Le nom par défaut du portefeuille doit être proposé.');
    }

    public function testDeletePortefeuilleDetachesClientsAndThen404(): void
    {
        ['gestionnaire' => $gestionnaire, 'portefeuille' => $pf, 'clientIn' => $clientIn] = $this->seed();
        $pfId = $pf->getId();
        $clientInId = $clientIn->getId();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // Suppression via l'INVITÉ (l'id de l'URL est celui de l'invité, pas du portefeuille).
        $this->client->request('DELETE', '/admin/invite/api/delete-portefeuille/' . $gestionnaire->getId());
        $this->assertResponseIsSuccessful('La suppression du portefeuille via l\'invité doit répondre 200.');

        $this->em()->clear();
        $this->assertNull($this->em()->getRepository(Portefeuille::class)->find($pfId), 'Le portefeuille doit être supprimé.');
        $survivor = $this->em()->getRepository(Client::class)->find($clientInId);
        $this->assertNotNull($survivor, 'Le client ne doit pas être supprimé avec le portefeuille.');
        $this->assertNull($survivor->getPortefeuille(), 'Le client doit être détaché (FK ON DELETE SET NULL).');

        // Second appel : l'invité ne gère plus aucun portefeuille → 404.
        $this->client->request('DELETE', '/admin/invite/api/delete-portefeuille/' . $gestionnaire->getId());
        $this->assertResponseStatusCodeSame(404, 'Sans portefeuille, la suppression doit répondre 404.');
    }

    public function testInviteFormCanvasExposesConditionalPortefeuilleActions(): void
    {
        ['gestionnaire' => $gestionnaire] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // Le formulaire d'édition d'un invité rend la barre d'outils des attributs avec
        // les trois actions « portefeuille » et leurs conditions (data-condition-*),
        // filtrées côté JS contre l'entité (dialog-instance#initializeAttributeToolbar).
        $this->client->request('GET', '/admin/invite/api/get-form/' . $gestionnaire->getId());
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString('get-portefeuille-context/%id%', $html, 'Les actions Ajouter/Éditer doivent pointer le contexte portefeuille.');
        $this->assertStringContainsString('delete-portefeuille', $html, 'L\'action Supprimer doit pointer la route de suppression.');
        $this->assertStringContainsString('data-condition-field="hasPortefeuille"', $html, 'Les actions doivent porter leur condition pour le filtrage côté dialogue.');
    }

    public function testHasPortefeuilleCalculatedIndicator(): void
    {
        ['gestionnaire' => $gestionnaire, 'sansPf' => $sansPf] = $this->seed();

        $canvasBuilder = static::getContainer()->get(CanvasBuilder::class);

        $canvasBuilder->loadAllCalculatedValues($gestionnaire);
        $this->assertTrue($gestionnaire->hasPortefeuille, 'L\'invité gestionnaire doit exposer hasPortefeuille = true.');
        $this->assertSame(self::PF_NOM, $gestionnaire->portefeuilleNom, 'La ligne secondaire doit porter le nom du portefeuille géré.');

        $canvasBuilder->loadAllCalculatedValues($sansPf);
        $this->assertFalse($sansPf->hasPortefeuille, 'L\'invité sans portefeuille doit exposer hasPortefeuille = false (booléen, jamais null).');
        $this->assertSame('Aucun portefeuille', $sansPf->portefeuilleNom, 'L\'absence de portefeuille doit être affichée explicitement.');
    }

    public function testInviteListShowsPortefeuilleOnSecondaryLine(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        // La rubrique Invités (module Administration) rend, sur la ligne secondaire de
        // chaque invité, son portefeuille — ou « Aucun portefeuille » explicitement.
        $this->client->request(
            'GET',
            sprintf('/espacedetravail/api/load-component/%d/%d', $owner->getId(), $e->getId()),
            ['component' => '_view_manager_administration.html.twig', 'entity' => 'Invite']
        );
        $this->assertResponseIsSuccessful('La rubrique Invités doit se charger.');
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString(self::PF_NOM, $html, 'Le nom du portefeuille du gestionnaire doit apparaître sur sa ligne.');
        $this->assertStringContainsString('Aucun portefeuille', $html, 'Les invités sans portefeuille doivent l\'indiquer explicitement.');
    }
}
