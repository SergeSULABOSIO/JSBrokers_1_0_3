<?php

namespace App\Tests\Ai;

use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\RolesEnProduction;
use App\Entity\Utilisateur;
use App\Repository\TokenConsumptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests fonctionnels de bout en bout de l'assistant IA du workspace :
 * visibilité des rubriques (menu + gating Paramètres), nommage du personnage,
 * isolation des conversations (par invité ET par entreprise), moteur simulé
 * (identité, données réelles via outil, refus poli hors périmètre, indicateur
 * calculé), métrage tokens (journal + blocage 402 sans persistance).
 * Chaque test crée ses données et les nettoie ensuite (pattern
 * WorkspacePerimetreTest).
 */
class AssistantIaWorkspaceTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-ia-owner@test.local';
    private const GUEST_EMAIL = 'phpunit-ia-guest@test.local';
    private const OTHER_EMAIL = 'phpunit-ia-other@test.local';
    private const PASSWORD = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit IA SARL';
    private const ENTREPRISE_B_NOM = 'PHPUnit IA Autre SARL';
    private const DENIED_MARKER = 'jsb-access-denied';

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

    private function makeUser(string $email): Utilisateur
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new Utilisateur();
        $user->setEmail($email);
        $user->setNom('PHPUnit IA');
        $user->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em()->persist($user);

        return $user;
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $emails = [self::OWNER_EMAIL, self::GUEST_EMAIL, self::OTHER_EMAIL];
        $noms = [self::ENTREPRISE_NOM, self::ENTREPRISE_B_NOM];

        $conn->executeStatement(
            "UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:emails)",
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );

        // Données de l'assistant (messages → conversations → paramètres), par entreprise.
        $conn->executeStatement(
            "DELETE m FROM assistant_message m
             JOIN assistant_conversation c ON m.conversation_id = c.id
             JOIN entreprise e ON c.entreprise_id = e.id
             WHERE e.nom IN (:noms)",
            ['noms' => $noms],
            ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        foreach (['assistant_conversation', 'assistant_parametres', 'client'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING]
            );
        }

        $conn->executeStatement(
            "DELETE tc FROM token_consumption tc LEFT JOIN utilisateur u ON tc.proprietaire_id = u.id WHERE u.email IN (:emails)",
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );

        foreach ([
            'roles_en_finance', 'roles_en_marketing', 'roles_en_production',
            'roles_en_sinistre', 'roles_en_administration',
        ] as $table) {
            $conn->executeStatement(
                "DELETE r FROM {$table} r
                 JOIN invite i ON r.invite_id = i.id
                 JOIN entreprise e ON i.entreprise_id = e.id
                 WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING]
            );
        }
        $conn->executeStatement(
            "DELETE i FROM invite i
             LEFT JOIN utilisateur u ON i.utilisateur_id = u.id
             LEFT JOIN entreprise e ON i.entreprise_id = e.id
             WHERE u.email IN (:emails) OR e.nom IN (:noms)",
            ['emails' => $emails, 'noms' => $noms],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING, 'noms' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        $conn->executeStatement(
            "DELETE FROM entreprise WHERE nom IN (:noms)",
            ['noms' => $noms],
            ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        $conn->executeStatement(
            "DELETE FROM utilisateur WHERE email IN (:emails)",
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
    }

    private function makeEntreprise(string $nom, Utilisateur $owner): Entreprise
    {
        $entreprise = new Entreprise();
        $entreprise->setNom($nom);
        $entreprise->setLicence('LIC-IA');
        $entreprise->setAdresse('1 rue de l\'IA');
        $entreprise->setTelephone('+243000000000');
        $entreprise->setRccm('RCCM-IA');
        $entreprise->setIdnat('IDNAT-IA');
        $entreprise->setNumimpot('IMP-IA');
        $entreprise->setUtilisateur($owner);
        $this->em()->persist($entreprise);

        return $entreprise;
    }

    /**
     * Prépare : un propriétaire + un invité restreint (optionnellement Lecture
     * sur les Clients) dans la même entreprise, tous deux « connectés » à elle.
     *
     * @return array{owner: Invite, guest: Invite, entreprise: Entreprise}
     */
    /** @param int[] $clientAccess niveaux du rôle Client de l'invité (si $withClientRole) */
    private function seed(bool $withClientRole = true, array $clientAccess = [Invite::ACCESS_LECTURE]): array
    {
        $em = $this->em();

        $ownerUser = $this->makeUser(self::OWNER_EMAIL);
        $entreprise = $this->makeEntreprise(self::ENTREPRISE_NOM, $ownerUser);
        $ownerUser->setConnectedTo($entreprise);

        $ownerInvite = new Invite();
        $ownerInvite->setNom('Administrateur');
        $ownerInvite->setUtilisateur($ownerUser);
        $ownerInvite->setEntreprise($entreprise);
        $ownerInvite->setProprietaire(true);
        $em->persist($ownerInvite);

        $guestUser = $this->makeUser(self::GUEST_EMAIL);
        $guestUser->setConnectedTo($entreprise);
        $guestInvite = new Invite();
        $guestInvite->setNom('Collaborateur restreint');
        $guestInvite->setUtilisateur($guestUser);
        $guestInvite->setEntreprise($entreprise);
        $guestInvite->setProprietaire(false);
        $em->persist($guestInvite);

        if ($withClientRole) {
            $role = new RolesEnProduction();
            $role->setNom('Rôle test IA');
            $role->setAccessClient($clientAccess);
            $role->setEntreprise($entreprise);
            $guestInvite->addRolesEnProduction($role);
            $em->persist($role);
        }

        $em->flush();

        return ['owner' => $ownerInvite, 'guest' => $guestInvite, 'entreprise' => $entreprise];
    }

    private function seedClients(Entreprise $entreprise, array $noms): void
    {
        $em = $this->em();
        foreach ($noms as $nom) {
            $client = new Client();
            $client->setNom($nom);
            $client->setExonere(false);
            $client->setEntreprise($entreprise);
            $em->persist($client);
        }
        $em->flush();
    }

    private function makeConversation(Entreprise $entreprise, Invite $invite, ?string $titre = null): AssistantConversation
    {
        $conversation = (new AssistantConversation())
            ->setEntreprise($entreprise)
            ->setInvite($invite)
            ->setTitre($titre);
        $this->em()->persist($conversation);
        $this->em()->flush();

        return $conversation;
    }

    private function postMessage(int $idEntreprise, int $idConversation, string $contenu): void
    {
        $this->client->request(
            'POST',
            sprintf('/admin/assistant-ia/api/messages/%d/%d', $idEntreprise, $idConversation),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['contenu' => $contenu])
        );
    }

    private function jsonResponse(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    // ── Menu & gating ─────────────────────────────────────────────────────────

    public function testRubriqueAssistantVisiblePourInviteSansRoleEtParametresMasques(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed(withClientRole: false);
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $this->client->request('GET', sprintf('/espacedetravail/%d/%d', $guest->getId(), $e->getId()));
        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString(
            '_assistant_ia_component.html.twig',
            $content,
            'La rubrique « Assistant » (sans entity_name) doit rester visible même sans aucun rôle.'
        );
        $this->assertStringNotContainsString(
            '_assistant_ia_parametres_component.html.twig',
            $content,
            'La rubrique « Paramètres IA » doit être masquée pour un invité non gestionnaire.'
        );

        // Le composant lui-même se charge pour cet invité sans rôle.
        $this->client->request('GET', sprintf(
            '/espacedetravail/api/load-component/%d/%d?component=_assistant_ia_component.html.twig',
            $guest->getId(),
            $e->getId()
        ));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('jsb-ai', (string) $this->client->getResponse()->getContent());
    }

    public function testMenuProprietaireContientParametresIa(): void
    {
        ['owner' => $owner, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('GET', sprintf('/espacedetravail/%d/%d', $owner->getId(), $e->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString(
            '_assistant_ia_parametres_component.html.twig',
            (string) $this->client->getResponse()->getContent(),
            'Le propriétaire doit voir la rubrique « Paramètres IA ».'
        );
    }

    public function testParametresReservesAuxAyantsDroit(): void
    {
        ['entreprise' => $e] = $this->seed();

        // Invité simple : accès restreint.
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->client->request('GET', sprintf('/admin/assistant-ia/workspace-parametres/%d', $e->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString(self::DENIED_MARKER, (string) $this->client->getResponse()->getContent());

        // Propriétaire : formulaire rendu.
        $this->client->loginUser($this->user(self::OWNER_EMAIL));
        $this->client->request('GET', sprintf('/admin/assistant-ia/workspace-parametres/%d', $e->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('jsb-ai-params', (string) $this->client->getResponse()->getContent());
    }

    // ── Nommage du personnage ────────────────────────────────────────────────

    public function testProprietaireNommeAssistant(): void
    {
        ['entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::OWNER_EMAIL));

        $this->client->request('POST', sprintf('/admin/assistant-ia/workspace-parametres/%d', $e->getId()), [
            'nom' => 'Aristote',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Aristote', (string) $this->client->getResponse()->getContent());

        // Le composant Assistant reflète le nouveau nom du personnage.
        $this->client->request('GET', sprintf('/admin/assistant-ia/workspace/%d', $e->getId()));
        $this->assertStringContainsString('Aristote — Assistant IA', (string) $this->client->getResponse()->getContent());
    }

    // ── Conversations : isolation & cycle de vie ─────────────────────────────

    public function testConversationsIsoleesParInvite(): void
    {
        ['owner' => $owner, 'guest' => $guest, 'entreprise' => $e] = $this->seed();
        $this->makeConversation($e, $owner, 'Conversation du propriétaire');
        $this->makeConversation($e, $guest, 'Conversation du collaborateur');

        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->client->request('GET', sprintf('/admin/assistant-ia/workspace/%d', $e->getId()));
        $content = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString('Conversation du collaborateur', $content);
        $this->assertStringNotContainsString(
            'Conversation du propriétaire',
            $content,
            'Un invité ne doit JAMAIS voir les conversations d\'un autre invité.'
        );
    }

    public function testCreationConversationParApi(): void
    {
        ['entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $this->client->request('POST', sprintf('/admin/assistant-ia/api/conversations/%d', $e->getId()));
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();

        $this->assertArrayHasKey('id', $data);
        $this->assertStringContainsString('/admin/assistant-ia/chat/', $data['chatUrl']);

        // Le chat de la conversation créée se rend correctement (panneau col-4).
        $this->client->request('GET', $data['chatUrl']);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('jsb-ai-chat', (string) $this->client->getResponse()->getContent());
    }

    public function testSuppressionConversationEtCascadeMessages(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $conversation = $this->makeConversation($e, $guest);
        $idConversation = $conversation->getId();

        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->postMessage($e->getId(), $idConversation, 'Bonjour !');
        $this->assertResponseIsSuccessful();

        $this->client->request('DELETE', sprintf('/admin/assistant-ia/api/conversations/%d/%d', $e->getId(), $idConversation));
        $this->assertResponseIsSuccessful();
        $this->assertTrue($this->jsonResponse()['success']);

        $conn = $this->em()->getConnection();
        $this->assertSame(
            0,
            (int) $conn->fetchOne('SELECT COUNT(*) FROM assistant_message WHERE conversation_id = :id', ['id' => $idConversation]),
            'Les messages doivent être supprimés en cascade avec la conversation.'
        );
    }

    public function testRenommageConversation(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $conversation = $this->makeConversation($e, $guest, 'Ancien titre');

        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->client->request(
            'PATCH',
            sprintf('/admin/assistant-ia/api/conversations/%d/%d', $e->getId(), $conversation->getId()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['titre' => 'Dossier flotte auto'])
        );
        $this->assertResponseIsSuccessful();
        $this->assertSame('Dossier flotte auto', $this->jsonResponse()['titre']);

        // Persisté et visible dans la liste du composant.
        $this->client->request('GET', sprintf('/admin/assistant-ia/workspace/%d', $e->getId()));
        $this->assertStringContainsString('Dossier flotte auto', (string) $this->client->getResponse()->getContent());
    }

    public function testRenommageInvalideOuDAutruiRefuse(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $conversation = $this->makeConversation($e, $guest, 'Privée');
        $url = sprintf('/admin/assistant-ia/api/conversations/%d/%d', $e->getId(), $conversation->getId());

        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        // Titre vide → 400.
        $this->client->request('PATCH', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['titre' => '  ']));
        $this->assertResponseStatusCodeSame(400);

        // Titre trop long (> 120) → 400.
        $this->client->request('PATCH', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['titre' => str_repeat('a', 121)]));
        $this->assertResponseStatusCodeSame(400);

        // Le propriétaire ne peut pas renommer la conversation d'un autre invité → 404.
        $this->client->loginUser($this->user(self::OWNER_EMAIL));
        $this->client->request('PATCH', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['titre' => 'Piratage']));
        $this->assertResponseStatusCodeSame(404);

        // Le titre d'origine est intact.
        $this->em()->clear();
        $this->assertSame('Privée', $this->em()->getRepository(AssistantConversation::class)->find($conversation->getId())->getTitre());
    }

    public function testSuppressionConversationDAutruiRefusee(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $conversation = $this->makeConversation($e, $guest, 'À protéger');

        // Le propriétaire lui-même ne peut pas supprimer la conversation d'un autre invité.
        $this->client->loginUser($this->user(self::OWNER_EMAIL));
        $this->client->request('DELETE', sprintf('/admin/assistant-ia/api/conversations/%d/%d', $e->getId(), $conversation->getId()));
        $this->assertResponseStatusCodeSame(404);
    }

    // ── Moteur simulé ────────────────────────────────────────────────────────

    public function testEnvoiMessageReponseSimuleeEtMetrage(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $conversation = $this->makeConversation($e, $guest);

        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->postMessage($e->getId(), $conversation->getId(), 'Bonjour, qui es-tu ?');
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();

        // Sans personnalisation, le personnage porte le nom par défaut « Jess ».
        $this->assertStringContainsString('Jess', $data['assistant']['contenu']);
        $this->assertStringContainsString(self::ENTREPRISE_NOM, $data['assistant']['contenu']);
        $this->assertSame('Bonjour, qui es-tu ?', $data['conversationTitre'], 'Le titre est dérivé du premier message.');

        // Les deux messages (question + réponse) sont persistés.
        $conn = $this->em()->getConnection();
        $this->assertSame(
            2,
            (int) $conn->fetchOne('SELECT COUNT(*) FROM assistant_message WHERE conversation_id = :id', ['id' => $conversation->getId()])
        );

        // Le métrage tokens est journalisé (écriture AssistantMessage).
        $logs = static::getContainer()->get(TokenConsumptionRepository::class)
            ->findBy(['entiteNom' => 'AssistantMessage']);
        $this->assertCount(1, $logs, 'Un message envoyé = une consommation de tokens journalisée.');
    }

    public function testReponseDonneesReellesDansPerimetre(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed(withClientRole: true);
        $this->seedClients($e, ['Client Alpha', 'Client Beta', 'Client Gamma']);
        $conversation = $this->makeConversation($e, $guest);

        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->postMessage($e->getId(), $conversation->getId(), 'Combien de clients avons-nous ?');
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();

        $this->assertFalse($data['assistant']['refus']);
        $this->assertStringContainsString('3 enregistrements', $data['assistant']['contenu']);
        $this->assertStringContainsString('Clients', $data['assistant']['contenu']);

        // Traçabilité : l'outil utilisé est consigné dans les meta du message.
        $meta = $this->em()->getRepository(AssistantMessage::class)
            ->findOneBy(['role' => AssistantMessage::ROLE_ASSISTANT], ['id' => 'DESC'])
            ->getMeta();
        $this->assertSame('compter_entites', $meta['tool']);
    }

    public function testListeDesClientsDansPerimetre(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed(withClientRole: true);
        $this->seedClients($e, ['Client Alpha', 'Client Beta', 'Client Gamma']);
        $conversation = $this->makeConversation($e, $guest);

        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->postMessage($e->getId(), $conversation->getId(), 'Liste nos clients');
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();

        $this->assertFalse($data['assistant']['refus']);
        $this->assertStringContainsString('3 enregistrements', $data['assistant']['contenu']);
        foreach (['Client Alpha', 'Client Beta', 'Client Gamma'] as $nom) {
            $this->assertStringContainsString($nom, $data['assistant']['contenu']);
        }

        $meta = $this->em()->getRepository(AssistantMessage::class)
            ->findOneBy(['role' => AssistantMessage::ROLE_ASSISTANT], ['id' => 'DESC'])
            ->getMeta();
        $this->assertSame('rechercher_entites', $meta['tool']);
    }

    public function testRechercheFiltreeEtHorsPerimetreAuNiveauOutil(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed(withClientRole: true);
        $this->seedClients($e, ['Client Alpha', 'Client Beta', 'Client Gamma']);
        $tool = static::getContainer()->get(\App\Ai\Tool\RechercherEntitesTool::class);
        $scope = new \App\Ai\Scope\AiScope($e, $guest);

        // Filtre texte : LIKE sur le champ de libellé, scopé à l'entreprise.
        $result = $tool->execute(['entite' => 'Client', 'filtre' => 'Beta'], $scope);
        $this->assertSame(\App\Ai\Tool\AiToolResult::STATUS_OK, $result->status);
        $this->assertSame(1, $result->data['totalItems']);
        $this->assertSame('Client Beta', $result->data['items'][0]['libelle']);

        // FAIL-CLOSED : une entité hors du périmètre de lecture est refusée.
        $horsPerimetre = $tool->execute(['entite' => 'Avenant'], $scope);
        $this->assertSame(\App\Ai\Tool\AiToolResult::STATUS_HORS_PERIMETRE, $horsPerimetre->status);
    }

    public function testRefusPoliHorsPerimetre(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed(withClientRole: false);
        $this->seedClients($e, ['Client Alpha', 'Client Beta', 'Client Gamma']);
        $conversation = $this->makeConversation($e, $guest);

        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->postMessage($e->getId(), $conversation->getId(), 'Combien de clients avons-nous ?');
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();

        $this->assertTrue($data['assistant']['refus'], 'Hors périmètre, la réponse doit être un refus.');
        $this->assertStringContainsString('périmètre', $data['assistant']['contenu']);
        $this->assertStringNotContainsString(
            '3 enregistrements',
            $data['assistant']['contenu'],
            'Le refus ne doit révéler AUCUNE donnée (fail-closed).'
        );
    }

    public function testActionOuvrirDialogueCreation(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed(
            withClientRole: true,
            clientAccess: [Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE],
        );
        $conversation = $this->makeConversation($e, $guest);

        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->postMessage($e->getId(), $conversation->getId(), 'Crée un nouveau client');
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();

        // La directive d'intention est remontée au chat (qui ouvrira le dialogue).
        $this->assertFalse($data['assistant']['refus']);
        $this->assertSame(
            [['type' => 'open-dialog', 'entite' => 'Client', 'mode' => 'creation']],
            $data['assistant']['actions'],
        );
        $this->assertStringContainsString('formulaire', $data['assistant']['contenu']);

        $meta = $this->em()->getRepository(AssistantMessage::class)
            ->findOneBy(['role' => AssistantMessage::ROLE_ASSISTANT], ['id' => 'DESC'])
            ->getMeta();
        $this->assertSame('ouvrir_dialogue', $meta['tool']);
    }

    public function testActionOuvrirDialogueRefuseeSansEcriture(): void
    {
        // Lecture seule : ouvrir un formulaire de création est une mutation à venir.
        ['guest' => $guest, 'entreprise' => $e] = $this->seed(withClientRole: true);
        $conversation = $this->makeConversation($e, $guest);

        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->postMessage($e->getId(), $conversation->getId(), 'Crée un nouveau client');
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();

        $this->assertTrue($data['assistant']['refus'], 'Sans niveau Écriture, la création doit être refusée.');
        $this->assertSame([], $data['assistant']['actions'], 'Aucune directive UI ne doit fuiter sur un refus.');
    }

    public function testDialogContextEndpointFailClosed(): void
    {
        ['entreprise' => $e] = $this->seed(
            withClientRole: true,
            clientAccess: [Invite::ACCESS_LECTURE, Invite::ACCESS_ECRITURE, Invite::ACCESS_MODIFICATION],
        );
        $this->seedClients($e, ['Client Alpha']);
        $idClient = $this->em()->getRepository(Client::class)->findOneBy(['nom' => 'Client Alpha'])->getId();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        // Création : canevas de formulaire, pas d'entité.
        $this->client->request('GET', sprintf('/admin/assistant-ia/api/dialog-context/%d?entite=Client&mode=creation', $e->getId()));
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();
        $this->assertSame('creation', $data['mode']);
        $this->assertNull($data['entity']);
        $this->assertNotEmpty($data['formCanvas']);

        // Édition : entité normalisée + canevas.
        $this->client->request('GET', sprintf('/admin/assistant-ia/api/dialog-context/%d?entite=Client&mode=edition&id=%d', $e->getId(), $idClient));
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();
        $this->assertSame('Client Alpha', $data['entity']['nom']);

        // Entité hors périmètre (aucun rôle Avenant) : 403 fail-closed.
        $this->client->request('GET', sprintf('/admin/assistant-ia/api/dialog-context/%d?entite=Avenant&mode=creation', $e->getId()));
        $this->assertResponseStatusCodeSame(403);

        // Entité inconnue : 400.
        $this->client->request('GET', sprintf('/admin/assistant-ia/api/dialog-context/%d?entite=Inexistante&mode=creation', $e->getId()));
        $this->assertResponseStatusCodeSame(400);
    }

    public function testIndicateurCalculeClient(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed(withClientRole: true);
        $this->seedClients($e, ['Client Alpha']);
        $conversation = $this->makeConversation($e, $guest);

        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->postMessage($e->getId(), $conversation->getId(), 'Quelle est la prime totale du client Alpha ?');
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();

        // La valeur (0,00 : aucun avenant) provient du circuit des indicateurs
        // CALCULÉS (CanvasBuilder), pas d'une colonne persistée.
        $this->assertFalse($data['assistant']['refus']);
        $this->assertStringContainsString('Client Alpha', $data['assistant']['contenu']);
        $this->assertStringContainsString('Prime Totale', $data['assistant']['contenu']);
        $this->assertStringContainsString('0,00', $data['assistant']['contenu']);
    }

    // ── Tokens & isolation inter-entreprises ────────────────────────────────

    public function testBlocage402SansPersistance(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $conversation = $this->makeConversation($e, $guest);

        // On vide le solde du PROPRIÉTAIRE (payeur) dans une fenêtre fraîche.
        $owner = $this->user(self::OWNER_EMAIL);
        $owner->setFreeTokens(0);
        $owner->setPaidTokens(0);
        $owner->setFreeWindowStartedAt(new \DateTimeImmutable());
        $this->em()->flush();

        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->postMessage($e->getId(), $conversation->getId(), 'Bonjour ?');

        $this->assertResponseStatusCodeSame(402);
        $data = $this->jsonResponse();
        $this->assertTrue($data['blocked']);
        $this->assertArrayHasKey('nextRenewalAt', $data);

        $conn = $this->em()->getConnection();
        $this->assertSame(
            0,
            (int) $conn->fetchOne('SELECT COUNT(*) FROM assistant_message WHERE conversation_id = :id', ['id' => $conversation->getId()]),
            'En cas de blocage tokens, AUCUN message ne doit être persisté.'
        );
    }

    public function testChatIsoleEntreEntreprises(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $conversation = $this->makeConversation($e, $guest, 'Conversation privée A');

        // Un utilisateur d'une AUTRE entreprise tente d'accéder à la conversation.
        $em = $this->em();
        $otherUser = $this->makeUser(self::OTHER_EMAIL);
        $entrepriseB = $this->makeEntreprise(self::ENTREPRISE_B_NOM, $otherUser);
        $otherUser->setConnectedTo($entrepriseB);
        $otherInvite = new Invite();
        $otherInvite->setNom('Propriétaire B');
        $otherInvite->setUtilisateur($otherUser);
        $otherInvite->setEntreprise($entrepriseB);
        $otherInvite->setProprietaire(true);
        $em->persist($otherInvite);
        $em->flush();

        $this->client->loginUser($this->user(self::OTHER_EMAIL));

        $this->client->request('GET', sprintf('/admin/assistant-ia/chat/%d/%d', $e->getId(), $conversation->getId()));
        $this->assertResponseStatusCodeSame(404);

        $this->postMessage($e->getId(), $conversation->getId(), 'Je ne devrais pas pouvoir écrire ici.');
        $this->assertResponseStatusCodeSame(404);
    }
}
