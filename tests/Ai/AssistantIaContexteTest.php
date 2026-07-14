<?php

namespace App\Tests\Ai;

use App\Entity\AssistantConversation;
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
 * Tests fonctionnels des objets attachés au contexte d'une conversation IA
 * (« Ajouter au chat avec l'assistant IA ») : attache simple et multiple,
 * idempotence, fail-closed (autre entreprise, type non whitelisté, module IA,
 * premium), facturation à l'attache (80 % du poids message, débit + journal,
 * 402 sans persistance), retrait individuel / vidage, puces rendues dans le
 * partial du chat, non-régression de l'envoi de message.
 * Pattern AssistantIaWorkspaceTest : chaque test crée ses données et les
 * nettoie ensuite.
 */
class AssistantIaContexteTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-iactx-owner@test.local';
    private const GUEST_EMAIL = 'phpunit-iactx-guest@test.local';
    private const PASSWORD = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit IACTX SARL';
    private const ENTREPRISE_B_NOM = 'PHPUnit IACTX Autre SARL';

    /** Coût unitaire par défaut d'un objet attaché : ceil(0.8 × 10). */
    private const COUT_CONTEXTE = 8;

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
        $user->setNom('PHPUnit IACTX');
        $user->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em()->persist($user);

        return $user;
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $emails = [self::OWNER_EMAIL, self::GUEST_EMAIL];
        $noms = [self::ENTREPRISE_NOM, self::ENTREPRISE_B_NOM];

        $conn->executeStatement(
            "UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:emails)",
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );

        // Données de l'assistant : contextes et messages AVANT les conversations.
        foreach (['assistant_conversation_contexte', 'assistant_message'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t
                 JOIN assistant_conversation c ON t.conversation_id = c.id
                 JOIN entreprise e ON c.entreprise_id = e.id
                 WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING]
            );
        }
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
        $entreprise->setLicence('LIC-IACTX');
        $entreprise->setAdresse('1 rue du Contexte');
        $entreprise->setTelephone('+243000000000');
        $entreprise->setRccm('RCCM-IACTX');
        $entreprise->setIdnat('IDNAT-IACTX');
        $entreprise->setNumimpot('IMP-IACTX');
        $entreprise->setUtilisateur($owner);
        $this->em()->persist($entreprise);

        return $entreprise;
    }

    /**
     * Propriétaire + invité (Lecture Clients + module IA par défaut), connectés
     * à l'entreprise A payante. Pattern AssistantIaWorkspaceTest::seed().
     *
     * @return array{owner: Invite, guest: Invite, entreprise: Entreprise}
     */
    private function seed(bool $withClientRole = true, bool $withIaRole = true, bool $comptePayant = true): array
    {
        $em = $this->em();

        $ownerUser = $this->makeUser(self::OWNER_EMAIL);
        if ($comptePayant) {
            $ownerUser->setPaidTokens(1_000_000);
        }
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
            $role->setNom('Rôle test contexte IA');
            $role->setAccessClient([Invite::ACCESS_LECTURE]);
            $role->setEntreprise($entreprise);
            $guestInvite->addRolesEnProduction($role);
            $em->persist($role);
        }

        if ($withIaRole) {
            $roleIa = new \App\Entity\RolesEnAdministration();
            $roleIa->setNom('Rôle module IA');
            $roleIa->setAccessAssistantIa([Invite::ACCESS_LECTURE]);
            $roleIa->setEntreprise($entreprise);
            $guestInvite->addRolesEnAdministration($roleIa);
            $em->persist($roleIa);
        }

        $em->flush();

        return ['owner' => $ownerInvite, 'guest' => $guestInvite, 'entreprise' => $entreprise];
    }

    /** @return int[] ids des clients créés, dans l'ordre des noms */
    private function seedClients(Entreprise $entreprise, array $noms): array
    {
        $em = $this->em();
        $ids = [];
        foreach ($noms as $nom) {
            $client = new Client();
            $client->setNom($nom);
            $client->setExonere(false);
            $client->setEntreprise($entreprise);
            $em->persist($client);
            $ids[] = $client;
        }
        $em->flush();

        return array_map(static fn (Client $c) => $c->getId(), $ids);
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

    private function attach(int $idEntreprise, int $idConversation, array $objets): void
    {
        $this->client->request(
            'POST',
            sprintf('/admin/assistant-ia/api/contextes/%d/%d', $idEntreprise, $idConversation),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['objets' => $objets])
        );
    }

    private function jsonResponse(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** Consommations contexte du SEUL propriétaire du test (hermétique aux autres données). */
    private function nbConsommationsContexte(): int
    {
        return \count(static::getContainer()->get(TokenConsumptionRepository::class)
            ->findBy([
                'entiteNom'    => 'AssistantConversationContexte',
                'proprietaire' => $this->user(self::OWNER_EMAIL),
            ]));
    }

    // ── Attache ──────────────────────────────────────────────────────────────

    public function testAttachUnObjet(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        [$idClient] = $this->seedClients($e, ['Client Contexte Alpha']);
        $conversation = $this->makeConversation($e, $guest);

        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->attach($e->getId(), $conversation->getId(), [['type' => 'Client', 'id' => $idClient]]);

        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();
        $this->assertSame(0, $data['ignores']);
        $this->assertCount(1, $data['contextes']);
        $this->assertSame('Client', $data['contextes'][0]['entityType']);
        $this->assertSame($idClient, $data['contextes'][0]['entityId']);
        $this->assertSame('Client Contexte Alpha', $data['contextes'][0]['label']);
        // Le fragment HTML des puces est rendu côté serveur (chemin unique).
        $this->assertStringContainsString('aic-chip', $data['html']);
        $this->assertStringContainsString('Client Contexte Alpha', $data['html']);

        // Persisté en base.
        $conn = $this->em()->getConnection();
        $this->assertSame(
            1,
            (int) $conn->fetchOne('SELECT COUNT(*) FROM assistant_conversation_contexte WHERE conversation_id = :id', ['id' => $conversation->getId()]),
        );
    }

    public function testAttachMultiEtIdempotence(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $ids = $this->seedClients($e, ['Ctx Alpha', 'Ctx Beta', 'Ctx Gamma']);
        $conversation = $this->makeConversation($e, $guest);
        $objets = array_map(static fn (int $id) => ['type' => 'Client', 'id' => $id], $ids);

        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->attach($e->getId(), $conversation->getId(), $objets);
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();
        $this->assertSame(0, $data['ignores']);
        $this->assertCount(3, $data['contextes']);
        $debitsApresPremierLot = $this->nbConsommationsContexte();
        $this->assertSame(3, $debitsApresPremierLot, 'Une ligne de journal par objet attaché.');

        // Re-attache du même lot : idempotent, AUCUN nouveau débit.
        $this->attach($e->getId(), $conversation->getId(), $objets);
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();
        $this->assertCount(3, $data['contextes'], 'Le doublon ne crée pas de second rattachement.');
        $this->assertSame(0, $data['ignores'], 'Un doublon est idempotent, pas une erreur.');
        $this->assertSame($debitsApresPremierLot, $this->nbConsommationsContexte(), 'Un doublon ne doit RIEN débiter.');
    }

    public function testFacturationALAttache(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $ids = $this->seedClients($e, ['Fact Alpha', 'Fact Beta']);
        $conversation = $this->makeConversation($e, $guest);

        $soldeAvant = $this->user(self::OWNER_EMAIL)->getPaidTokens();

        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->attach($e->getId(), $conversation->getId(), array_map(
            static fn (int $id) => ['type' => 'Client', 'id' => $id],
            $ids,
        ));
        $this->assertResponseIsSuccessful();

        // Débit exact : N × ceil(0.8 × poids message) sur le solde du propriétaire.
        $this->em()->clear();
        $soldeApres = $this->user(self::OWNER_EMAIL)->getPaidTokens();
        $this->assertSame(2 * self::COUT_CONTEXTE, $soldeAvant - $soldeApres);

        // Journalisation : une ligne par objet, au coût unitaire attendu.
        $logs = static::getContainer()->get(TokenConsumptionRepository::class)
            ->findBy([
                'entiteNom'    => 'AssistantConversationContexte',
                'proprietaire' => $this->user(self::OWNER_EMAIL),
            ]);
        $this->assertCount(2, $logs);
        $this->assertSame(self::COUT_CONTEXTE, $logs[0]->getPoidsUnitaire());
    }

    public function testSoldeInsuffisantBloqueSansPersistance(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        [$idClient] = $this->seedClients($e, ['Insolvable Alpha']);
        $conversation = $this->makeConversation($e, $guest);

        // Compte payant (garde premium passée) mais insolvable pour le coût contexte.
        $owner = $this->user(self::OWNER_EMAIL);
        $owner->setFreeTokens(0);
        $owner->setPaidTokens(self::COUT_CONTEXTE - 1);
        $owner->setFreeWindowStartedAt(new \DateTimeImmutable());
        $this->em()->flush();

        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->attach($e->getId(), $conversation->getId(), [['type' => 'Client', 'id' => $idClient]]);

        $this->assertResponseStatusCodeSame(402);
        $data = $this->jsonResponse();
        $this->assertTrue($data['blocked']);
        $this->assertSame(self::COUT_CONTEXTE, $data['required']);
        $this->assertArrayHasKey('available', $data);

        // RIEN n'est attaché ni journalisé.
        $conn = $this->em()->getConnection();
        $this->assertSame(
            0,
            (int) $conn->fetchOne('SELECT COUNT(*) FROM assistant_conversation_contexte WHERE conversation_id = :id', ['id' => $conversation->getId()]),
        );
        $this->assertSame(0, $this->nbConsommationsContexte());
    }

    // ── Fail-closed ──────────────────────────────────────────────────────────

    public function testAttachFailClosedAutreEntreprise(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $conversation = $this->makeConversation($e, $guest);

        // Client d'une AUTRE entreprise (même propriétaire, peu importe) :
        // le scoping entreprise doit l'écarter.
        $entrepriseB = $this->makeEntreprise(self::ENTREPRISE_B_NOM, $this->user(self::OWNER_EMAIL));
        $this->em()->flush();
        [$idClientB] = $this->seedClients($entrepriseB, ['Client Etranger']);

        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->attach($e->getId(), $conversation->getId(), [['type' => 'Client', 'id' => $idClientB]]);

        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();
        $this->assertSame(1, $data['ignores']);
        $this->assertSame([], $data['contextes']);
        $this->assertSame(0, $this->nbConsommationsContexte(), 'Un objet écarté ne doit rien débiter.');
    }

    public function testAttachFailClosedTypeNonWhitelisteOuHorsRole(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $conversation = $this->makeConversation($e, $guest);

        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->attach($e->getId(), $conversation->getId(), [
            ['type' => 'Utilisateur', 'id' => 1],   // hors carte de permissions
            ['type' => '../Hack', 'id' => 1],       // tentative de traversée
            ['type' => 'Avenant', 'id' => 1],       // whitelisté mais AUCUN rôle de lecture
        ]);

        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();
        $this->assertSame(3, $data['ignores']);
        $this->assertSame([], $data['contextes']);
    }

    public function testAttachRefuseSansModuleIa(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed(withIaRole: false);
        [$idClient] = $this->seedClients($e, ['Sans Module']);
        $conversation = $this->makeConversation($e, $guest);

        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->attach($e->getId(), $conversation->getId(), [['type' => 'Client', 'id' => $idClient]]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAttachRefuseComptesNonPayants(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed(comptePayant: false);
        [$idClient] = $this->seedClients($e, ['Sans Premium']);
        $conversation = $this->makeConversation($e, $guest);

        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->attach($e->getId(), $conversation->getId(), [['type' => 'Client', 'id' => $idClient]]);
        $this->assertResponseStatusCodeSame(402);
        $this->assertTrue($this->jsonResponse()['premium']);
    }

    // ── Retraits ─────────────────────────────────────────────────────────────

    public function testDetachUnPuisTout(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $ids = $this->seedClients($e, ['Det Alpha', 'Det Beta', 'Det Gamma']);
        $conversation = $this->makeConversation($e, $guest);

        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->attach($e->getId(), $conversation->getId(), array_map(
            static fn (int $id) => ['type' => 'Client', 'id' => $id],
            $ids,
        ));
        $contextes = $this->jsonResponse()['contextes'];
        $this->assertCount(3, $contextes);
        $debits = $this->nbConsommationsContexte();

        // Retrait individuel : l'objet visé disparaît, les autres restent.
        $this->client->request('DELETE', sprintf(
            '/admin/assistant-ia/api/contextes/%d/%d/%d',
            $e->getId(),
            $conversation->getId(),
            $contextes[0]['id'],
        ));
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();
        $this->assertCount(2, $data['contextes']);
        $this->assertNotContains($contextes[0]['id'], array_column($data['contextes'], 'id'));

        // Vidage complet.
        $this->client->request('DELETE', sprintf(
            '/admin/assistant-ia/api/contextes/%d/%d',
            $e->getId(),
            $conversation->getId(),
        ));
        $this->assertResponseIsSuccessful();
        $this->assertSame([], $this->jsonResponse()['contextes']);

        $conn = $this->em()->getConnection();
        $this->assertSame(
            0,
            (int) $conn->fetchOne('SELECT COUNT(*) FROM assistant_conversation_contexte WHERE conversation_id = :id', ['id' => $conversation->getId()]),
        );
        // Ni remboursement ni débit au retrait.
        $this->assertSame($debits, $this->nbConsommationsContexte());
    }

    public function testDetachConversationDAutruiRefuse(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        [$idClient] = $this->seedClients($e, ['Privé Alpha']);
        $conversation = $this->makeConversation($e, $guest);

        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->attach($e->getId(), $conversation->getId(), [['type' => 'Client', 'id' => $idClient]]);
        $idContexte = $this->jsonResponse()['contextes'][0]['id'];

        // Le propriétaire lui-même ne touche pas au contexte d'une conversation
        // d'un autre invité (isolation par invité → 404).
        $this->client->loginUser($this->user(self::OWNER_EMAIL));
        $this->client->request('DELETE', sprintf(
            '/admin/assistant-ia/api/contextes/%d/%d/%d',
            $e->getId(),
            $conversation->getId(),
            $idContexte,
        ));
        $this->assertResponseStatusCodeSame(404);

        // Un id de contexte inexistant dans SA conversation : 404 aussi.
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->client->request('DELETE', sprintf(
            '/admin/assistant-ia/api/contextes/%d/%d/%d',
            $e->getId(),
            $conversation->getId(),
            999999,
        ));
        $this->assertResponseStatusCodeSame(404);
    }

    // ── Rendu & envoi de message ─────────────────────────────────────────────

    public function testChipsRenduesDansLePartialChat(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        [$idClient] = $this->seedClients($e, ['Chip Alpha']);
        $conversation = $this->makeConversation($e, $guest);

        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->attach($e->getId(), $conversation->getId(), [['type' => 'Client', 'id' => $idClient]]);
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('/admin/assistant-ia/chat/%d/%d', $e->getId(), $conversation->getId()));
        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('aic-chip', $content);
        $this->assertStringContainsString('Chip Alpha', $content);
        $this->assertStringContainsString('1 objet en contexte', $content);
    }

    public function testEnvoiMessageAvecEtSansContexte(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        [$idClient] = $this->seedClients($e, ['Msg Alpha']);
        $conversation = $this->makeConversation($e, $guest);
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        $postMessage = function (string $contenu) use ($e, $conversation): void {
            $this->client->request(
                'POST',
                sprintf('/admin/assistant-ia/api/messages/%d/%d', $e->getId(), $conversation->getId()),
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode(['contenu' => $contenu])
            );
        };

        // Non-régression : l'envoi fonctionne SANS contexte.
        $postMessage('Bonjour, qui es-tu ?');
        $this->assertResponseIsSuccessful();
        $this->assertNotSame('', $this->jsonResponse()['assistant']['contenu']);

        // Et il fonctionne toujours AVEC un contexte attaché (le moteur simulé
        // ignore les objets attachés, aucune erreur).
        $this->attach($e->getId(), $conversation->getId(), [['type' => 'Client', 'id' => $idClient]]);
        $this->assertResponseIsSuccessful();
        $postMessage('Combien de clients avons-nous ?');
        $this->assertResponseIsSuccessful();
        $this->assertFalse($this->jsonResponse()['assistant']['refus']);
    }
}
