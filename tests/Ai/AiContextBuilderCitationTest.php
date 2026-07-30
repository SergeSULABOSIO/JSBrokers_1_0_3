<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Injection de la CITATION dans le contexte envoyé au moteur.
 *
 * Sans ce marqueur, répondre à une bulle ancienne n'aurait aucun effet côté
 * modèle : il traiterait la demande comme portant sur le dernier sujet du fil.
 * Le marqueur porte l'extrait EXACT, ce qui reste vrai même quand la bulle citée
 * est sortie de la fenêtre d'historique (MAX_MESSAGES).
 *
 * Le prompt SYSTÈME n'est volontairement pas touché : la citation est un
 * attribut du tour de parole, pas de l'état de la conversation.
 */
class AiContextBuilderCitationTest extends KernelTestCase
{
    private const OWNER_EMAIL = 'phpunit-iacit-ctx-owner@test.local';
    private const GUEST_EMAIL = 'phpunit-iacit-ctx-guest@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit IACITCTX SARL';

    private AiContextBuilder $builder;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->builder = self::getContainer()->get(AiContextBuilder::class);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        $emails = [self::OWNER_EMAIL, self::GUEST_EMAIL];
        $noms = [self::ENTREPRISE_NOM];

        $conn->executeStatement(
            'UPDATE utilisateur SET connected_to_id = NULL WHERE email IN (:emails)',
            ['emails' => $emails],
            ['emails' => ArrayParameterType::STRING],
        );
        // repond_a_id est ON DELETE SET NULL : les messages partent quand même en
        // une requête grâce au CASCADE de conversation_id.
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

    private function makeUser(string $email): Utilisateur
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new Utilisateur())->setEmail($email)->setNom('PHPUnit IACITCTX');
        $user->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'Test1234!'));
        $this->em()->persist($user);

        return $user;
    }

    /** @return array{entreprise: Entreprise, invite: Invite, conversation: AssistantConversation} */
    private function seed(): array
    {
        $em = $this->em();

        $owner = $this->makeUser(self::OWNER_EMAIL);
        $owner->setPaidTokens(1_000_000);
        $entreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)->setLicence('LIC-IACITCTX')->setAdresse('1 rue Citée')
            ->setTelephone('+243000000000')->setRccm('RCCM-IACITCTX')->setIdnat('IDNAT-IACITCTX')
            ->setNumimpot('IMP-IACITCTX')->setUtilisateur($owner);
        $em->persist($entreprise);
        $owner->setConnectedTo($entreprise);

        $guestUser = $this->makeUser(self::GUEST_EMAIL);
        $guestUser->setConnectedTo($entreprise);
        $invite = (new Invite())->setNom('Collaborateur')->setUtilisateur($guestUser)
            ->setEntreprise($entreprise)->setProprietaire(false);
        $em->persist($invite);

        $role = new \App\Entity\RolesEnAdministration();
        $role->setNom('Rôle module IA');
        $role->setAccessAssistantIa([Invite::ACCESS_LECTURE]);
        $role->setEntreprise($entreprise);
        $invite->addRolesEnAdministration($role);
        $em->persist($role);

        $conversation = (new AssistantConversation())->setEntreprise($entreprise)->setInvite($invite);
        $em->persist($conversation);
        $em->flush();

        return ['entreprise' => $entreprise, 'invite' => $invite, 'conversation' => $conversation];
    }

    private function addMessage(
        AssistantConversation $conversation,
        string $role,
        string $contenu,
        ?AssistantMessage $repondA = null,
        ?array $contexteObjets = null,
    ): AssistantMessage {
        $message = (new AssistantMessage())
            ->setConversation($conversation)
            ->setRole($role)
            ->setContenu($contenu)
            ->setRepondA($repondA)
            ->setContexteObjets($contexteObjets);
        $conversation->addMessage($message);
        $this->em()->persist($message);
        $this->em()->flush();

        return $message;
    }

    /** @return array<int, array{role: string, content: string}> */
    private function messages(array $seed): array
    {
        return $this->builder->build($seed['entreprise'], $seed['invite'], $seed['conversation'])->messages;
    }

    /** Contenu du dernier tour transmis au moteur (celui qui porte la citation). */
    private function dernierContenu(array $seed): string
    {
        $messages = $this->messages($seed);

        return (string) end($messages)['content'];
    }

    // ── Marqueur ───────────────────────────────────────────────────────────

    public function testCiterUneReponseDeKetLeDitAuModele(): void
    {
        $seed = $this->seed();
        $cite = $this->addMessage($seed['conversation'], AssistantMessage::ROLE_ASSISTANT, 'Vous avez 3 avenants échus.');
        $this->addMessage($seed['conversation'], AssistantMessage::ROLE_USER, 'Lesquels ?', $cite);

        $dernier = $this->dernierContenu($seed);

        self::assertStringStartsWith('[CE MESSAGE RÉPOND À TA propre réponse', $dernier);
        self::assertStringContainsString('Vous avez 3 avenants échus.', $dernier);
        self::assertStringContainsString('Lesquels ?', $dernier);
    }

    public function testCiterSonPropreMessageLeDitAussi(): void
    {
        $seed = $this->seed();
        $cite = $this->addMessage($seed['conversation'], AssistantMessage::ROLE_USER, 'Combien de clients ?');
        $this->addMessage($seed['conversation'], AssistantMessage::ROLE_USER, 'Je précise ma question.', $cite);

        $dernier = $this->dernierContenu($seed);

        self::assertStringStartsWith('[CE MESSAGE RÉPOND À un message ANTÉRIEUR de l\'utilisateur', $dernier);
        self::assertStringContainsString('Combien de clients ?', $dernier);
    }

    public function testLaRegleAntiDerivéDeSujetEstEnonceeAuModele(): void
    {
        $seed = $this->seed();
        $cite = $this->addMessage($seed['conversation'], AssistantMessage::ROLE_ASSISTANT, 'Réponse initiale.');
        $this->addMessage($seed['conversation'], AssistantMessage::ROLE_USER, 'Détaille.', $cite);

        $dernier = $this->dernierContenu($seed);

        self::assertStringContainsString('portant sur CE passage précis', $dernier);
        self::assertStringContainsString('demande une précision', $dernier);
    }

    // ── Non-régression ─────────────────────────────────────────────────────

    public function testSansCitationLeContenuEstStrictementInchange(): void
    {
        $seed = $this->seed();
        $this->addMessage($seed['conversation'], AssistantMessage::ROLE_USER, 'Combien de clients ?');

        self::assertSame('Combien de clients ?', $this->dernierContenu($seed));
    }

    public function testCitationEtContexteCoexistentDansUnOrdreDeterministe(): void
    {
        $seed = $this->seed();
        $cite = $this->addMessage($seed['conversation'], AssistantMessage::ROLE_ASSISTANT, 'Voici le portefeuille.');
        $this->addMessage(
            $seed['conversation'],
            AssistantMessage::ROLE_USER,
            'Et pour celui-ci ?',
            $cite,
            [['type' => 'Client', 'id' => 42, 'nom' => 'SONAS']],
        );

        $dernier = $this->dernierContenu($seed);

        $posReponse = strpos($dernier, '[CE MESSAGE RÉPOND À');
        $posContexte = strpos($dernier, '[Objets en contexte');
        $posContenu = strpos($dernier, 'Et pour celui-ci ?');

        self::assertNotFalse($posReponse);
        self::assertNotFalse($posContexte);
        self::assertLessThan($posContexte, $posReponse, 'La citation doit précéder le contexte.');
        self::assertLessThan($posContenu, $posContexte, 'Le contexte doit précéder le contenu.');
    }

    // ── Extrait ────────────────────────────────────────────────────────────

    public function testExtraitLongEstTronqueEtAplati(): void
    {
        $seed = $this->seed();
        $cite = $this->addMessage(
            $seed['conversation'],
            AssistantMessage::ROLE_ASSISTANT,
            "Ligne une.\n\nLigne deux.\n" . str_repeat('mot ', 300),
        );
        $this->addMessage($seed['conversation'], AssistantMessage::ROLE_USER, 'Résume.', $cite);

        $dernier = $this->dernierContenu($seed);
        $marqueur = substr($dernier, 0, (int) strpos($dernier, "\n"));

        self::assertStringContainsString('…', $marqueur);
        // Un marqueur multiligne casserait la lisibilité du tour de parole.
        self::assertStringNotContainsString("\n", $marqueur);
        self::assertLessThanOrEqual(
            AssistantMessage::EXTRAIT_CITATION_MAX + 1,
            mb_strlen($cite->extraitCitation())
        );
    }

    public function testMessageCiteHorsFenetreDHistoriqueResteLisible(): void
    {
        // C'est la raison d'être de l'extrait : sans lui, le pointeur serait vide
        // de sens dès que la cible sort des 20 derniers messages.
        $seed = $this->seed();
        $cite = $this->addMessage($seed['conversation'], AssistantMessage::ROLE_ASSISTANT, 'Le chiffre clé est 1 250 USD.');
        for ($i = 0; $i < 25; ++$i) {
            $this->addMessage($seed['conversation'], AssistantMessage::ROLE_USER, 'Message intercalaire ' . $i);
        }
        $this->addMessage($seed['conversation'], AssistantMessage::ROLE_USER, 'Reviens là-dessus.', $cite);

        $messages = $this->messages($seed);
        $dernier = end($messages)['content'];

        self::assertLessThanOrEqual(20, \count($messages));
        self::assertStringNotContainsString('Le chiffre clé est 1 250 USD.', $messages[0]['content']);
        self::assertStringContainsString('Le chiffre clé est 1 250 USD.', $dernier);
    }

    public function testExtraitCitationSurContenuVideOuBlanc(): void
    {
        self::assertSame('', (new AssistantMessage())->setContenu('')->extraitCitation());
        self::assertSame('', (new AssistantMessage())->setContenu("  \n\t ")->extraitCitation());
        self::assertSame('a b c', (new AssistantMessage())->setContenu("a\n b\t\tc ")->extraitCitation());
    }
}
