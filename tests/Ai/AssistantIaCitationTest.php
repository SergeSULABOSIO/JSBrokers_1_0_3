<?php

namespace App\Tests\Ai;

use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Repository\TokenConsumptionRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests fonctionnels de l'action « Répondre » d'une bulle du chat : persistance
 * du lien de citation, refus fail-closed d'un message hors conversation, et
 * crochets HTML dont dépend le contrôleur Stimulus.
 *
 * Deux points méritent l'attention :
 *  - le refus est un 400 INDISCERNABLE dans les trois cas (id inexistant, autre
 *    conversation, autre invité) : aucun oracle d'énumération d'ids ;
 *  - la suppression d'une conversation contenant une citation est testée pour de
 *    bon : la FK auto-référencée ON DELETE SET NULL doit cohabiter avec le
 *    DELETE en une seule requête de deleteConversation().
 */
class AssistantIaCitationTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-iacit-owner@test.local';
    private const GUEST_EMAIL = 'phpunit-iacit-guest@test.local';
    private const OTHER_EMAIL = 'phpunit-iacit-other@test.local';
    private const PASSWORD = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit IACIT SARL';

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

    private function user(string $email): ?Utilisateur
    {
        return $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    private function makeUser(string $email): Utilisateur
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new Utilisateur())->setEmail($email)->setNom('PHPUnit IACIT');
        $user->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em()->persist($user);

        return $user;
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $emails = [self::OWNER_EMAIL, self::GUEST_EMAIL, self::OTHER_EMAIL];
        $noms = [self::ENTREPRISE_NOM];

        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:emails)',
            ['emails' => $emails],
            ['emails' => ArrayParameterType::STRING],
        );
        foreach (['assistant_conversation_fichier', 'assistant_conversation_contexte', 'assistant_message'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t
                 JOIN assistant_conversation c ON t.conversation_id = c.id
                 JOIN entreprise e ON c.entreprise_id = e.id
                 WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => ArrayParameterType::STRING],
            );
        }
        $conn->executeStatement(
            'DELETE t FROM assistant_conversation t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)',
            ['noms' => $noms],
            ['noms' => ArrayParameterType::STRING],
        );
        $conn->executeStatement(
            'DELETE tc FROM token_consumption tc LEFT JOIN utilisateur u ON tc.proprietaire_id = u.id WHERE u.email IN (:emails)',
            ['emails' => $emails],
            ['emails' => ArrayParameterType::STRING],
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
                ['noms' => ArrayParameterType::STRING],
            );
        }
        $conn->executeStatement(
            'DELETE i FROM invite i LEFT JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom IN (:noms)',
            ['noms' => $noms],
            ['noms' => ArrayParameterType::STRING],
        );
        $conn->executeStatement('DELETE FROM entreprise WHERE nom IN (:noms)', ['noms' => $noms], ['noms' => ArrayParameterType::STRING]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email IN (:emails)', ['emails' => $emails], ['emails' => ArrayParameterType::STRING]);
    }

    /** @return array{guest: Invite, other: Invite, entreprise: Entreprise} */
    private function seed(): array
    {
        $em = $this->em();

        $owner = $this->makeUser(self::OWNER_EMAIL);
        $owner->setPaidTokens(1_000_000);
        $entreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)->setLicence('LIC-IACIT')->setAdresse('1 rue Citée')
            ->setTelephone('+243000000000')->setRccm('RCCM-IACIT')->setIdnat('IDNAT-IACIT')
            ->setNumimpot('IMP-IACIT')->setUtilisateur($owner);
        $em->persist($entreprise);
        $owner->setConnectedTo($entreprise);

        $guestUser = $this->makeUser(self::GUEST_EMAIL);
        $guestUser->setConnectedTo($entreprise);
        $guest = (new Invite())->setNom('Collaborateur')->setUtilisateur($guestUser)
            ->setEntreprise($entreprise)->setProprietaire(false);
        $em->persist($guest);
        $role = new \App\Entity\RolesEnAdministration();
        $role->setNom('Rôle module IA');
        $role->setAccessAssistantIa([Invite::ACCESS_LECTURE]);
        $role->setEntreprise($entreprise);
        $guest->addRolesEnAdministration($role);
        $em->persist($role);

        $otherUser = $this->makeUser(self::OTHER_EMAIL);
        $otherUser->setConnectedTo($entreprise);
        $other = (new Invite())->setNom('Autre')->setUtilisateur($otherUser)
            ->setEntreprise($entreprise)->setProprietaire(false);
        $em->persist($other);
        $roleAutre = new \App\Entity\RolesEnAdministration();
        $roleAutre->setNom('Rôle module IA (autre)');
        $roleAutre->setAccessAssistantIa([Invite::ACCESS_LECTURE]);
        $roleAutre->setEntreprise($entreprise);
        $other->addRolesEnAdministration($roleAutre);
        $em->persist($roleAutre);

        $em->flush();

        return ['guest' => $guest, 'other' => $other, 'entreprise' => $entreprise];
    }

    private function makeConversation(Entreprise $entreprise, Invite $invite): AssistantConversation
    {
        $conversation = (new AssistantConversation())->setEntreprise($entreprise)->setInvite($invite);
        $this->em()->persist($conversation);
        $this->em()->flush();

        return $conversation;
    }

    private function makeMessage(AssistantConversation $conversation, string $contenu, string $role = AssistantMessage::ROLE_ASSISTANT): AssistantMessage
    {
        $message = (new AssistantMessage())->setConversation($conversation)->setRole($role)->setContenu($contenu);
        $conversation->addMessage($message);
        $this->em()->persist($message);
        $this->em()->flush();

        return $message;
    }

    /**
     * Envoi d'un message, précédé d'un clear() : le contrôleur doit recharger la
     * conversation depuis la base, comme en production (l'EM est partagé avec le
     * test, et le seed n'alimente pas toujours le côté inverse des relations).
     */
    private function envoyer(int $idEntreprise, int $idConversation, array $payload): void
    {
        $this->em()->clear();
        $this->client->request(
            'POST',
            sprintf('/admin/assistant-ia/api/messages/%d/%d', $idEntreprise, $idConversation),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );
    }

    private function jsonResponse(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function nbMessages(): int
    {
        return \count($this->em()->getRepository(AssistantMessage::class)->findAll());
    }

    private function nbConsommations(): int
    {
        return \count(static::getContainer()->get(TokenConsumptionRepository::class)->findBy([
            'proprietaire' => $this->user(self::OWNER_EMAIL),
        ]));
    }

    // ── Nominal ────────────────────────────────────────────────────────────

    public function testRepondreLieLeMessageEtLeRestitue(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $conversation = $this->makeConversation($e, $guest);
        $cite = $this->makeMessage($conversation, 'Vous avez 3 avenants échus.');
        $idCite = $cite->getId();

        $this->envoyer($e->getId(), $conversation->getId(), ['contenu' => 'Lesquels ?', 'replyToId' => $idCite]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $citation = $this->jsonResponse()['user']['citation'] ?? null;
        self::assertIsArray($citation);
        self::assertSame($idCite, $citation['id']);
        self::assertSame(AssistantMessage::ROLE_ASSISTANT, $citation['role']);
        self::assertSame('Vous avez 3 avenants échus.', $citation['extrait']);

        $this->em()->clear();
        $envoye = $this->em()->getRepository(AssistantMessage::class)->find($this->jsonResponse()['user']['id']);
        self::assertSame($idCite, $envoye?->getRepondA()?->getId());
    }

    public function testSansReplyToIdRienNeChange(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $conversation = $this->makeConversation($e, $guest);

        $this->envoyer($e->getId(), $conversation->getId(), ['contenu' => 'Combien de clients ?']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->jsonResponse()['user']['citation']);

        $this->em()->clear();
        $envoye = $this->em()->getRepository(AssistantMessage::class)->find($this->jsonResponse()['user']['id']);
        self::assertNull($envoye?->getRepondA());
    }

    public function testCiterUnMessageUtilisateurEstPossible(): void
    {
        // « Répondre » vaut pour SES propres messages comme pour ceux de Ket.
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $conversation = $this->makeConversation($e, $guest);
        $cite = $this->makeMessage($conversation, 'Ma première question.', AssistantMessage::ROLE_USER);

        $this->envoyer($e->getId(), $conversation->getId(), ['contenu' => 'Je précise.', 'replyToId' => $cite->getId()]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(AssistantMessage::ROLE_USER, $this->jsonResponse()['user']['citation']['role']);
    }

    // ── Fail-closed ────────────────────────────────────────────────────────

    public function testMessageCiteDUneAutreConversationEstRefuseSansRienConsommer(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $conversationA = $this->makeConversation($e, $guest);
        $conversationB = $this->makeConversation($e, $guest);
        $messageB = $this->makeMessage($conversationB, 'Message de la conversation B.');

        $messagesAvant = $this->nbMessages();
        $consosAvant = $this->nbConsommations();

        $this->envoyer($e->getId(), $conversationA->getId(), ['contenu' => 'Détaille.', 'replyToId' => $messageB->getId()]);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        // Le refus précède le métrage : ni message créé, ni token débité.
        self::assertSame($messagesAvant, $this->nbMessages());
        self::assertSame($consosAvant, $this->nbConsommations());
    }

    public function testMessageCiteDUnAutreInviteEstRefuse(): void
    {
        ['guest' => $guest, 'other' => $other, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $conversation = $this->makeConversation($e, $guest);
        $conversationAutre = $this->makeConversation($e, $other);
        $messageAutre = $this->makeMessage($conversationAutre, 'Message confidentiel.');

        $this->envoyer($e->getId(), $conversation->getId(), ['contenu' => 'Détaille.', 'replyToId' => $messageAutre->getId()]);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('confidentiel', (string) $this->client->getResponse()->getContent());
    }

    /** @dataProvider idsCitesInvalides */
    public function testMessageCiteInvalideEstRefuse(mixed $replyToId): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $conversation = $this->makeConversation($e, $guest);

        $this->envoyer($e->getId(), $conversation->getId(), ['contenu' => 'Bonjour.', 'replyToId' => $replyToId]);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public static function idsCitesInvalides(): array
    {
        return [
            'inexistant' => [999_999_999],
            'zéro' => [0],
            'négatif' => [-1],
            'texte' => ['abc'],
            'texte vide' => [''],
        ];
    }

    public function testLesTroisRefusSontIndiscernables(): void
    {
        // Aucun oracle permettant de deviner qu'un id existe ailleurs.
        ['guest' => $guest, 'other' => $other, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $conversation = $this->makeConversation($e, $guest);
        $conversationAutre = $this->makeConversation($e, $other);
        $messageAutre = $this->makeMessage($conversationAutre, 'Ailleurs.');

        $reponses = [];
        foreach ([999_999_999, $messageAutre->getId()] as $id) {
            $this->envoyer($e->getId(), $conversation->getId(), ['contenu' => 'Bonjour.', 'replyToId' => $id]);
            $reponses[] = [
                $this->client->getResponse()->getStatusCode(),
                (string) $this->client->getResponse()->getContent(),
            ];
        }

        self::assertSame($reponses[0], $reponses[1]);
    }

    // ── Cohabitation FK auto-référencée ⇄ suppression de conversation ───────

    public function testSupprimerUneConversationContenantUneCitation(): void
    {
        // La suppression se fait en UN seul DELETE, qui compte sur le CASCADE de
        // conversation_id. La FK repond_a_id (SET NULL) sur les MÊMES lignes doit
        // le supporter : c'est le risque que ce test transforme en certitude.
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $conversation = $this->makeConversation($e, $guest);
        $cite = $this->makeMessage($conversation, 'Message cité.');

        $this->envoyer($e->getId(), $conversation->getId(), ['contenu' => 'Je réponds.', 'replyToId' => $cite->getId()]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $idConversation = $conversation->getId();
        $this->em()->clear();
        $this->client->request('DELETE', sprintf('/admin/assistant-ia/api/conversations/%d/%d', $e->getId(), $idConversation));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $restants = (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM assistant_message WHERE conversation_id = ?',
            [$idConversation]
        );
        self::assertSame(0, $restants);
    }

    // ── Crochets HTML consommés par le contrôleur Stimulus ─────────────────

    public function testLePartialDuChatPorteLesCrochetsDuMenuEtDeLaCitation(): void
    {
        // Épingle côté serveur ce dont dépend assistant-chat_controller.js : un
        // renommage Twig casse un test, pas la production.
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $conversation = $this->makeConversation($e, $guest);
        $cite = $this->makeMessage($conversation, 'Réponse citée.');
        $reponse = $this->makeMessage($conversation, 'Ma question.', AssistantMessage::ROLE_USER);
        $reponse->setRepondA($cite);
        $this->em()->flush();
        $this->em()->clear();

        $this->client->request('GET', sprintf('/admin/assistant-ia/chat/%d/%d', $e->getId(), $conversation->getId()));
        $html = (string) $this->client->getResponse()->getContent();

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        foreach ([
            'data-message-id=',
            'data-message-role=',
            'aic-msg-menu',
            'role="menu"',
            'data-menu-key="repondre"',
            'data-menu-key="copier-image"',
            'data-menu-key="export-pdf"',
            'data-menu-key="export-word"',
            'data-menu-key="export-markdown"',
            'data-menu-key="export-image"',
            'data-menu-key="envoyer-email"',
            'assistant-chat-target="tplKebab"',
            'assistant-chat-target="tplCitation"',
            'assistant-chat-target="citationBar"',
            'aic-msg-quote',
            'data-quote-id=',
        ] as $crochet) {
            self::assertStringContainsString($crochet, $html, sprintf('Crochet « %s » absent du partial du chat.', $crochet));
        }
    }

    public function testLesCrochetsSurviventAuModeSombre(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $utilisateur = $this->user(self::GUEST_EMAIL);
        $utilisateur->setThemeAssistant('dark');
        $this->em()->flush();
        $this->client->loginUser($utilisateur);
        $conversation = $this->makeConversation($e, $guest);
        $this->makeMessage($conversation, 'Bonjour.');
        $this->em()->clear();

        $this->client->request('GET', sprintf('/admin/assistant-ia/chat/%d/%d', $e->getId(), $conversation->getId()));
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('data-aic-theme="dark"', $html);
        self::assertStringContainsString('aic-msg-menu', $html);
        self::assertStringContainsString('data-message-id=', $html);
    }
}
