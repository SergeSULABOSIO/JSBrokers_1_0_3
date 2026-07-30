<?php

namespace App\Tests\Ai;

use App\Ai\Export\MessageExporter;
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
 * Tests fonctionnels de l'EXPORT d'un message du chat (PDF / Word / Markdown) :
 * contrat HTTP de chaque format, fail-closed sur l'appartenance du message, et
 * ABSENCE de débit de tokens.
 *
 * Le point de sécurité central : un idMessage est toujours cherché DANS la
 * conversation, elle-même déjà restreinte à l'invité — d'où les cas « message
 * d'une autre conversation du MÊME invité » et « conversation d'un autre
 * invité », qui doivent tous deux donner 404.
 */
class AssistantMessageExportTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-iaexp-owner@test.local';
    private const GUEST_EMAIL = 'phpunit-iaexp-guest@test.local';
    private const OTHER_EMAIL = 'phpunit-iaexp-other@test.local';
    private const PASSWORD = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit IAEXP SARL';

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
        $user = new Utilisateur();
        $user->setEmail($email);
        $user->setNom('PHPUnit IAEXP');
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
    private function seed(bool $withIaRole = true, bool $comptePayant = true): array
    {
        $em = $this->em();

        $ownerUser = $this->makeUser(self::OWNER_EMAIL);
        if ($comptePayant) {
            $ownerUser->setPaidTokens(1_000_000);
        }
        $entreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)->setLicence('LIC-IAEXP')->setAdresse('1 rue Exportée')
            ->setTelephone('+243000000000')->setRccm('RCCM-IAEXP')->setIdnat('IDNAT-IAEXP')
            ->setNumimpot('IMP-IAEXP')->setUtilisateur($ownerUser);
        $em->persist($entreprise);
        $ownerUser->setConnectedTo($entreprise);

        $guestUser = $this->makeUser(self::GUEST_EMAIL);
        $guestUser->setConnectedTo($entreprise);
        $guestInvite = (new Invite())->setNom('Collaborateur')->setUtilisateur($guestUser)
            ->setEntreprise($entreprise)->setProprietaire(false);
        $em->persist($guestInvite);

        if ($withIaRole) {
            $role = new \App\Entity\RolesEnAdministration();
            $role->setNom('Rôle module IA');
            $role->setAccessAssistantIa([Invite::ACCESS_LECTURE]);
            $role->setEntreprise($entreprise);
            $guestInvite->addRolesEnAdministration($role);
            $em->persist($role);
        }

        // Autre invité de la MÊME entreprise : sa conversation doit rester
        // inaccessible à l'invité connecté.
        $otherUser = $this->makeUser(self::OTHER_EMAIL);
        $otherUser->setConnectedTo($entreprise);
        $otherInvite = (new Invite())->setNom('Autre')->setUtilisateur($otherUser)
            ->setEntreprise($entreprise)->setProprietaire(false);
        $em->persist($otherInvite);
        $roleAutre = new \App\Entity\RolesEnAdministration();
        $roleAutre->setNom('Rôle module IA (autre)');
        $roleAutre->setAccessAssistantIa([Invite::ACCESS_LECTURE]);
        $roleAutre->setEntreprise($entreprise);
        $otherInvite->addRolesEnAdministration($roleAutre);
        $em->persist($roleAutre);

        $em->flush();

        return ['guest' => $guestInvite, 'other' => $otherInvite, 'entreprise' => $entreprise];
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
        $this->em()->persist($message);
        $this->em()->flush();

        return $message;
    }

    private function urlExport(int $idEntreprise, int $idConversation, int $idMessage, string $format): string
    {
        return sprintf('/admin/assistant-ia/api/messages/%d/%d/%d/export/%s', $idEntreprise, $idConversation, $idMessage, $format);
    }

    /**
     * Requête d'export, précédée d'un clear() de l'EntityManager.
     *
     * INDISPENSABLE : le test et le contrôleur partagent le même EM. Les messages
     * du seed sont créés avec setConversation() sans renseigner le côté inverse,
     * donc la collection $conversation->getMessages() de l'identity map reste
     * VIDE et trouverMessage() ne verrait rien. Le clear force le contrôleur à
     * recharger depuis la base — c'est-à-dire à faire ce qu'il fait en production.
     */
    private function exporter(int $idEntreprise, int $idConversation, int $idMessage, string $format): void
    {
        $this->em()->clear();
        $this->client->request('GET', $this->urlExport($idEntreprise, $idConversation, $idMessage, $format));
    }

    private function nbConsommations(): int
    {
        return \count(static::getContainer()->get(TokenConsumptionRepository::class)->findBy([
            'proprietaire' => $this->user(self::OWNER_EMAIL),
        ]));
    }

    // ── Contrat HTTP des trois formats ─────────────────────────────────────

    /** @dataProvider formatsEtRoles */
    public function testExportRepondAvecLeBonTypeEtUneDispositionDePiece(string $format, string $mime, string $extension, string $role): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $conversation = $this->makeConversation($e, $guest);
        $message = $this->makeMessage($conversation, "## Bilan\n\nTout va **bien**.", $role);

        $this->exporter($e->getId(), $conversation->getId(), $message->getId(), $format);
        $reponse = $this->client->getResponse();

        self::assertSame(200, $reponse->getStatusCode());
        self::assertStringStartsWith($mime, (string) $reponse->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $reponse->headers->get('Content-Disposition'));
        self::assertStringContainsString(
            sprintf('message-ia-%d-', $message->getId()),
            (string) $reponse->headers->get('Content-Disposition')
        );
        self::assertStringContainsString('.' . $extension, (string) $reponse->headers->get('Content-Disposition'));
    }

    public static function formatsEtRoles(): array
    {
        return [
            'PDF, réponse de Ket' => ['pdf', 'application/pdf', 'pdf', AssistantMessage::ROLE_ASSISTANT],
            'PDF, question utilisateur' => ['pdf', 'application/pdf', 'pdf', AssistantMessage::ROLE_USER],
            'Word, réponse de Ket' => ['word', 'application/msword', 'doc', AssistantMessage::ROLE_ASSISTANT],
            'Word, question utilisateur' => ['word', 'application/msword', 'doc', AssistantMessage::ROLE_USER],
            'Markdown, réponse de Ket' => ['markdown', 'text/markdown', 'md', AssistantMessage::ROLE_ASSISTANT],
            'Markdown, question utilisateur' => ['markdown', 'text/markdown', 'md', AssistantMessage::ROLE_USER],
        ];
    }

    public function testPdfPorteLaSignatureDuFormat(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $conversation = $this->makeConversation($e, $guest);
        $message = $this->makeMessage($conversation, 'Bonjour.');

        $this->exporter($e->getId(), $conversation->getId(), $message->getId(), 'pdf');

        self::assertStringStartsWith('%PDF-', (string) $this->client->getResponse()->getContent());
    }

    public function testWordPorteLesNamespacesOffice(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $conversation = $this->makeConversation($e, $guest);
        $message = $this->makeMessage($conversation, 'Bonjour.');

        $this->exporter($e->getId(), $conversation->getId(), $message->getId(), 'word');
        $contenu = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('xmlns:w', $contenu);
        self::assertStringContainsString('w:WordDocument', $contenu);
    }

    public function testMarkdownEstStrictementLeContenuStocke(): void
    {
        // Garde-fou : personne ne doit glisser un « nettoyage » dans ce chemin.
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $conversation = $this->makeConversation($e, $guest);
        $contenu = "## Titre\n\n- item **gras**\n- [Payée](#success)\n";
        $message = $this->makeMessage($conversation, $contenu);

        $this->exporter($e->getId(), $conversation->getId(), $message->getId(), 'markdown');

        self::assertSame($contenu, (string) $this->client->getResponse()->getContent());
    }

    public function testMessageRicheAvecTableauPastilleEtGraphiqueProduitUnPdf(): void
    {
        // Non-régression DomPDF sur la combinaison la plus lourde émise par Ket.
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $conversation = $this->makeConversation($e, $guest);
        $contenu = "## Situation\n\n| Client | Statut |\n|---|---|\n| SONAS | [Payée](#success) |\n\n"
            . "```chart\n{\"type\":\"bar\",\"titre\":\"CA\",\"unite\":\"USD\",\"labels\":[\"Jan\"],\"series\":[{\"label\":\"HT\",\"data\":[1200]}],\"legende\":\"Commissions.\"}\n```";
        $message = $this->makeMessage($conversation, $contenu);

        $this->exporter($e->getId(), $conversation->getId(), $message->getId(), 'pdf');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringStartsWith('%PDF-', (string) $this->client->getResponse()->getContent());
    }

    // ── Fail-closed ────────────────────────────────────────────────────────

    public function testMessageDUneAutreConversationDuMemeInviteEstRefuse(): void
    {
        // Le cœur du fail-closed : l'id existe et appartient à l'invité, mais pas
        // à la conversation demandée.
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $conversationA = $this->makeConversation($e, $guest);
        $conversationB = $this->makeConversation($e, $guest);
        $messageB = $this->makeMessage($conversationB, 'Message de la conversation B.');

        $this->exporter($e->getId(), $conversationA->getId(), $messageB->getId(), 'pdf');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testConversationDUnAutreInviteEstRefusee(): void
    {
        ['guest' => $guest, 'other' => $other, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $conversationAutre = $this->makeConversation($e, $other);
        $message = $this->makeMessage($conversationAutre, 'Message privé.');

        $this->exporter($e->getId(), $conversationAutre->getId(), $message->getId(), 'pdf');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Message privé', (string) $this->client->getResponse()->getContent());
    }

    public function testEntrepriseInexistanteEstRefusee(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $conversation = $this->makeConversation($e, $guest);
        $message = $this->makeMessage($conversation, 'Bonjour.');

        $this->exporter(999_999_999, $conversation->getId(), $message->getId(), 'pdf');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testMessageInexistantEstRefuse(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $conversation = $this->makeConversation($e, $guest);

        $this->exporter($e->getId(), $conversation->getId(), 999_999_999, 'pdf');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /** @dataProvider formatsRefuses */
    public function testFormatHorsListeEstRejeteParLeRouteur(string $format): void
    {
        // La contrainte vit dans la ROUTE : le rejet précède l'action.
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $conversation = $this->makeConversation($e, $guest);
        $message = $this->makeMessage($conversation, 'Bonjour.');

        $this->exporter($e->getId(), $conversation->getId(), $message->getId(), $format);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public static function formatsRefuses(): array
    {
        return [
            'tableur' => ['xlsx'],
            // L'image est produite par le NAVIGATEUR : aucune route ne la sert.
            'image' => ['image'],
            'png' => ['png'],
            'vide' => [''],
        ];
    }

    public function testModuleRefuseDonneUnTroisCentTrois(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed(withIaRole: false);
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $conversation = $this->makeConversation($e, $guest);
        $message = $this->makeMessage($conversation, 'Bonjour.');

        $this->exporter($e->getId(), $conversation->getId(), $message->getId(), 'pdf');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testCompteNonPayantEstBloque(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed(comptePayant: false);
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $conversation = $this->makeConversation($e, $guest);
        $message = $this->makeMessage($conversation, 'Bonjour.');

        $this->exporter($e->getId(), $conversation->getId(), $message->getId(), 'pdf');

        self::assertSame(402, $this->client->getResponse()->getStatusCode());
        self::assertTrue((json_decode((string) $this->client->getResponse()->getContent(), true) ?? [])['premium'] ?? false);
    }

    // ── Métrage : aucun débit ──────────────────────────────────────────────

    public function testAucunExportNeDebiteDeTokens(): void
    {
        // Le message a déjà été facturé à l'envoi : le relire ne se facture pas.
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $conversation = $this->makeConversation($e, $guest);
        $message = $this->makeMessage($conversation, "## Bilan\n\nTout va bien.");

        $avant = $this->nbConsommations();
        foreach (MessageExporter::FORMATS as $format) {
            $this->exporter($e->getId(), $conversation->getId(), $message->getId(), $format);
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
        }

        self::assertSame($avant, $this->nbConsommations());
    }
}
