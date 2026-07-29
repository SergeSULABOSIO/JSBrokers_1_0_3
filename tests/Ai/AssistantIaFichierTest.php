<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Entity\AssistantConversation;
use App\Entity\AssistantConversationFichier;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Repository\TokenConsumptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests fonctionnels des FICHIERS attachés au chat de l'assistant IA : upload
 * multipart + extraction de texte (.txt et PDF), rejets (format, taille, plafond
 * de nombre), facturation (100 % du poids message, débit + journal, 402 sans
 * persistance), retrait / vidage, téléchargement fail-closed, puces rendues dans
 * le partial du chat, et injection de la section « PIÈCES JOINTES » dans le
 * prompt système. Miroir de AssistantIaContexteTest, côté pièces jointes.
 */
class AssistantIaFichierTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-iafic-owner@test.local';
    private const GUEST_EMAIL = 'phpunit-iafic-guest@test.local';
    private const OTHER_EMAIL = 'phpunit-iafic-other@test.local';
    private const PASSWORD = 'Test1234!';
    private const ENTREPRISE_NOM = 'PHPUnit IAFIC SARL';

    /** Coût unitaire d'un fichier attaché : ceil(1.0 × 10). */
    private const COUT_FICHIER = 10;

    private KernelBrowser $client;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        foreach ($this->tempFiles as $f) {
            @unlink($f);
        }
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
        $user->setNom('PHPUnit IAFIC');
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
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        foreach (['assistant_conversation_fichier', 'assistant_conversation_contexte', 'assistant_message'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t
                 JOIN assistant_conversation c ON t.conversation_id = c.id
                 JOIN entreprise e ON c.entreprise_id = e.id
                 WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }
        foreach (['assistant_conversation', 'document', 'client'] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom IN (:noms)",
                ['noms' => $noms],
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }
        $conn->executeStatement(
            'DELETE tc FROM token_consumption tc LEFT JOIN utilisateur u ON tc.proprietaire_id = u.id WHERE u.email IN (:emails)',
            ['emails' => $emails],
            ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING],
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
                ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }
        $conn->executeStatement(
            'DELETE i FROM invite i LEFT JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom IN (:noms)',
            ['noms' => $noms],
            ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $conn->executeStatement('DELETE FROM entreprise WHERE nom IN (:noms)', ['noms' => $noms], ['noms' => \Doctrine\DBAL\ArrayParameterType::STRING]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email IN (:emails)', ['emails' => $emails], ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]);
    }

    /**
     * @return array{guest: Invite, other: Invite, entreprise: Entreprise}
     */
    private function seed(bool $withIaRole = true, bool $comptePayant = true): array
    {
        $em = $this->em();

        $ownerUser = $this->makeUser(self::OWNER_EMAIL);
        if ($comptePayant) {
            $ownerUser->setPaidTokens(1_000_000);
        }
        $entreprise = (new Entreprise())
            ->setNom(self::ENTREPRISE_NOM)->setLicence('LIC-IAFIC')->setAdresse('1 rue Jointe')
            ->setTelephone('+243000000000')->setRccm('RCCM-IAFIC')->setIdnat('IDNAT-IAFIC')
            ->setNumimpot('IMP-IAFIC')->setUtilisateur($ownerUser);
        $em->persist($entreprise);
        $ownerUser->setConnectedTo($entreprise);

        $guestUser = $this->makeUser(self::GUEST_EMAIL);
        $guestUser->setConnectedTo($entreprise);
        $guestInvite = (new Invite())->setNom('Collaborateur')->setUtilisateur($guestUser)
            ->setEntreprise($entreprise)->setProprietaire(false);
        $em->persist($guestInvite);

        if ($withIaRole) {
            $roleIa = new \App\Entity\RolesEnAdministration();
            $roleIa->setNom('Rôle module IA');
            $roleIa->setAccessAssistantIa([Invite::ACCESS_LECTURE]);
            $roleIa->setEntreprise($entreprise);
            $guestInvite->addRolesEnAdministration($roleIa);
            $em->persist($roleIa);
        }

        // Autre invité de la MÊME entreprise (test fail-closed du téléchargement).
        $otherUser = $this->makeUser(self::OTHER_EMAIL);
        $otherUser->setConnectedTo($entreprise);
        $otherInvite = (new Invite())->setNom('Autre')->setUtilisateur($otherUser)
            ->setEntreprise($entreprise)->setProprietaire(false);
        $em->persist($otherInvite);
        $roleIaOther = new \App\Entity\RolesEnAdministration();
        $roleIaOther->setNom('Rôle module IA (autre)');
        $roleIaOther->setAccessAssistantIa([Invite::ACCESS_LECTURE]);
        $roleIaOther->setEntreprise($entreprise);
        $otherInvite->addRolesEnAdministration($roleIaOther);
        $em->persist($roleIaOther);

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

    /** Fichier temporaire → UploadedFile de test (test=true : non issu d'un upload HTTP). */
    private function upFile(string $name, string $contenu, string $mime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'phpunit_pj_');
        file_put_contents($path, $contenu);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, $mime, null, true);
    }

    /** @param UploadedFile[] $files */
    private function attach(int $idEntreprise, int $idConversation, array $files): void
    {
        $this->client->request(
            'POST',
            sprintf('/admin/assistant-ia/api/fichiers/%d/%d', $idEntreprise, $idConversation),
            [],
            ['fichiers' => $files],
        );
    }

    private function jsonResponse(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function nbConsommationsFichier(): int
    {
        return \count(static::getContainer()->get(TokenConsumptionRepository::class)->findBy([
            'entiteNom'    => 'AssistantConversationFichier',
            'proprietaire' => $this->user(self::OWNER_EMAIL),
        ]));
    }

    // ── Upload & extraction ────────────────────────────────────────────────

    public function testUploadTexteEtExtraction(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $conversation = $this->makeConversation($e, $guest);

        $soldeAvant = $this->user(self::OWNER_EMAIL)->getPaidTokens();
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->attach($e->getId(), $conversation->getId(), [
            $this->upFile('note.txt', "Police AUTO n°A-42 pour le client Dupont.", 'text/plain'),
        ]);

        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();
        $this->assertSame(0, $data['ignores']);
        $this->assertCount(1, $data['fichiers']);
        $this->assertSame('note.txt', $data['fichiers'][0]['nom']);
        $this->assertTrue($data['fichiers'][0]['aExtrait'], 'Le texte du .txt est extrait.');
        $this->assertStringContainsString('aic-fichier-chip', $data['html']);
        $this->assertStringContainsString('note.txt', $data['html']);

        // Persistance + extrait stocké.
        $this->em()->clear();
        $fichier = $this->em()->getRepository(AssistantConversationFichier::class)
            ->findOneBy([], ['id' => 'DESC']);
        $this->assertNotNull($fichier);
        $this->assertStringContainsString('Dupont', (string) $fichier->getTexteExtrait());

        // Facturation : 1 × 10 débité + une ligne de journal.
        $this->assertSame(self::COUT_FICHIER, $soldeAvant - $this->user(self::OWNER_EMAIL)->getPaidTokens());
        $this->assertSame(1, $this->nbConsommationsFichier());
    }

    public function testUploadPdfExtraction(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $conversation = $this->makeConversation($e, $guest);

        $pdf = new \TCPDF();
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 14);
        $pdf->Write(0, 'Contrat Ket extraction PDF');
        $bytes = $pdf->Output('', 'S');

        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->attach($e->getId(), $conversation->getId(), [$this->upFile('contrat.pdf', $bytes, 'application/pdf')]);
        $this->assertResponseIsSuccessful();

        $this->em()->clear();
        $fichier = $this->em()->getRepository(AssistantConversationFichier::class)->findOneBy([], ['id' => 'DESC']);
        $this->assertNotNull($fichier);
        $this->assertStringContainsString('Ket', (string) $fichier->getTexteExtrait(), 'Le texte du PDF est extrait.');
    }

    public function testRejetFormatNonAutorise(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $conversation = $this->makeConversation($e, $guest);

        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->attach($e->getId(), $conversation->getId(), [
            $this->upFile('malware.exe', 'MZ binaire', 'application/octet-stream'),
        ]);
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();
        $this->assertSame(1, $data['ignores']);
        $this->assertSame([], $data['fichiers']);
        $this->assertNotEmpty($data['erreurs']);
        $this->assertSame(0, $this->nbConsommationsFichier(), 'Un fichier rejeté ne débite rien.');
    }

    public function testRejetTailleMax(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $conversation = $this->makeConversation($e, $guest);

        // 11 Mo de texte (> 10 Mo) : extension valide, taille refusée.
        $gros = str_repeat('A', 11 * 1024 * 1024);
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->attach($e->getId(), $conversation->getId(), [$this->upFile('gros.txt', $gros, 'text/plain')]);
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();
        $this->assertSame(1, $data['ignores']);
        $this->assertSame([], $data['fichiers']);
        $this->assertSame(0, $this->nbConsommationsFichier());
    }

    public function testPlafondNombreFichiers(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $conversation = $this->makeConversation($e, $guest);

        $files = [];
        for ($i = 1; $i <= 6; $i++) {
            $files[] = $this->upFile("f{$i}.txt", "contenu {$i}", 'text/plain');
        }
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->attach($e->getId(), $conversation->getId(), $files);
        $this->assertResponseIsSuccessful();
        $data = $this->jsonResponse();
        $this->assertCount(5, $data['fichiers'], 'Plafond de 5 fichiers par conversation.');
        $this->assertSame(1, $data['ignores']);
        $this->assertSame(5, $this->nbConsommationsFichier(), 'Seuls les 5 stockés sont facturés.');
    }

    public function testSoldeInsuffisantBloqueSansPersistance(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $conversation = $this->makeConversation($e, $guest);

        $owner = $this->user(self::OWNER_EMAIL);
        $owner->setFreeTokens(0);
        $owner->setPaidTokens(self::COUT_FICHIER - 1);
        $owner->setFreeWindowStartedAt(new \DateTimeImmutable());
        $this->em()->flush();

        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->attach($e->getId(), $conversation->getId(), [$this->upFile('x.txt', 'Contenu texte lisible pour le test de solde.', 'text/plain')]);
        $this->assertResponseStatusCodeSame(402);
        $this->assertTrue($this->jsonResponse()['blocked']);

        $conn = $this->em()->getConnection();
        $this->assertSame(0, (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM assistant_conversation_fichier WHERE conversation_id = :id',
            ['id' => $conversation->getId()],
        ));
        $this->assertSame(0, $this->nbConsommationsFichier());
    }

    // ── Retrait / téléchargement ────────────────────────────────────────────

    public function testDetachEtClear(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $conversation = $this->makeConversation($e, $guest);
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->attach($e->getId(), $conversation->getId(), [
            $this->upFile('a.txt', 'Premier document texte de test.', 'text/plain'),
            $this->upFile('b.txt', 'Second document texte de test.', 'text/plain'),
        ]);
        $fichiers = $this->jsonResponse()['fichiers'];
        $this->assertCount(2, $fichiers);

        $this->client->request('DELETE', sprintf(
            '/admin/assistant-ia/api/fichiers/%d/%d/%d',
            $e->getId(), $conversation->getId(), $fichiers[0]['id'],
        ));
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $this->jsonResponse()['fichiers']);

        $this->client->request('DELETE', sprintf('/admin/assistant-ia/api/fichiers/%d/%d', $e->getId(), $conversation->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertSame([], $this->jsonResponse()['fichiers']);
    }

    public function testDownloadFailClosedAutreInvite(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $conversation = $this->makeConversation($e, $guest);
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->attach($e->getId(), $conversation->getId(), [$this->upFile('secret.txt', 'contenu privé', 'text/plain')]);
        $idFichier = $this->jsonResponse()['fichiers'][0]['id'];

        $url = sprintf('/admin/assistant-ia/api/fichiers/%d/%d/%d/download', $e->getId(), $conversation->getId(), $idFichier);

        // Le propriétaire de la pièce la télécharge.
        $this->client->request('GET', $url);
        $this->assertResponseIsSuccessful();

        // Un AUTRE invité de la même entreprise ne voit pas la conversation d'autrui → 404.
        $this->client->loginUser($this->user(self::OTHER_EMAIL));
        $this->client->request('GET', $url);
        $this->assertResponseStatusCodeSame(404);
    }

    // ── Rendu & prompt ──────────────────────────────────────────────────────

    public function testChipsDansPartialChat(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $conversation = $this->makeConversation($e, $guest);
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->attach($e->getId(), $conversation->getId(), [$this->upFile('dossier.txt', 'contenu', 'text/plain')]);
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('/admin/assistant-ia/chat/%d/%d', $e->getId(), $conversation->getId()));
        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('aic-fichier-chip', $content);
        $this->assertStringContainsString('dossier.txt', $content);
        $this->assertStringContainsString('1 fichier joint', $content);
        // Le bouton trombone et le champ fichier caché sont présents.
        $this->assertStringContainsString('assistant-chat#ouvrirSelecteurFichier', $content);
        $this->assertStringContainsString('assistant-chat#onFichiersChoisis', $content);
    }

    public function testSectionPiecesJointesDansLePrompt(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $conversation = $this->makeConversation($e, $guest);
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->attach($e->getId(), $conversation->getId(), [
            $this->upFile('rapport.txt', 'Le sinistre concerne le vehicule immatricule XYZ-123.', 'text/plain'),
        ]);
        $idFichier = $this->jsonResponse()['fichiers'][0]['id'];

        $this->em()->clear();
        $builder = static::getContainer()->get(AiContextBuilder::class);
        $conversation = $this->em()->getRepository(AssistantConversation::class)->find($conversation->getId());
        $invite = $this->em()->getRepository(Invite::class)->find($guest->getId());
        $entreprise = $this->em()->getRepository(Entreprise::class)->find($e->getId());

        $prompt = $builder->toSystemPrompt($builder->build($entreprise, $invite, $conversation));
        $this->assertStringContainsString('PIÈCES JOINTES', $prompt);
        $this->assertStringContainsString('@fichier:' . $idFichier, $prompt);
        $this->assertStringContainsString('XYZ-123', $prompt, "L'extrait de texte est injecté dans le prompt.");
    }

    public function testPromptSansFichierInchange(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $conversation = $this->makeConversation($e, $guest);

        $builder = static::getContainer()->get(AiContextBuilder::class);
        $prompt = $builder->toSystemPrompt($builder->build($e, $guest, $conversation));
        $this->assertStringNotContainsString('PIÈCES JOINTES', $prompt, 'Aucun fichier : section absente (non-régression).');
    }

    public function testImageEstTransmiseNativementAuMoteur(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $conversation = $this->makeConversation($e, $guest);
        $this->client->loginUser($this->user(self::GUEST_EMAIL));

        // PNG 1×1 valide (finfo => image/png) : pas d'extrait texte possible,
        // donc transmis NATIVEMENT au moteur multimodal (lecture par vision).
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $this->attach($e->getId(), $conversation->getId(), [$this->upFile('photo.png', $png, 'image/png')]);
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $this->jsonResponse()['fichiers']);

        $this->em()->clear();
        $builder = static::getContainer()->get(AiContextBuilder::class);
        $conversation = $this->em()->getRepository(AssistantConversation::class)->find($conversation->getId());
        $pieces = $builder->piecesNatives($conversation);

        $this->assertCount(1, $pieces, 'L’image est transmise nativement au moteur.');
        $this->assertSame('image/png', $pieces[0]['mimeType']);
        $this->assertNotSame('', $pieces[0]['donneesBase64']);
        $this->assertSame('photo.png', $pieces[0]['nom']);
    }

    // ── Téléchargement déclenché depuis le chat ─────────────────────────────

    public function testKetProposeLeTelechargement(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $conversation = $this->makeConversation($e, $guest);
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->attach($e->getId(), $conversation->getId(), [$this->upFile('bilan.txt', 'Contenu du bilan annuel a telecharger.', 'text/plain')]);
        $idFichier = $this->jsonResponse()['fichiers'][0]['id'];

        // L'utilisateur demande le téléchargement : Ket émet une action files-download.
        $this->client->request(
            'POST',
            sprintf('/admin/assistant-ia/api/messages/%d/%d', $e->getId(), $conversation->getId()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['contenu' => 'Peux-tu me donner le lien de téléchargement du fichier joint ?']),
        );
        $this->assertResponseIsSuccessful();
        $actions = $this->jsonResponse()['assistant']['actions'] ?? [];

        $dl = null;
        foreach ($actions as $a) {
            if (($a['type'] ?? null) === 'files-download') {
                $dl = $a;
                break;
            }
        }
        $this->assertNotNull($dl, 'Ket émet une action de téléchargement.');
        $this->assertCount(1, $dl['fichiers']);
        $this->assertSame($idFichier, $dl['fichiers'][0]['id']);
        $this->assertStringContainsString(
            sprintf('/fichiers/%d/%d/%d/download', $e->getId(), $conversation->getId(), $idFichier),
            $dl['fichiers'][0]['url'],
            'Le bouton pointe vers la route de téléchargement sécurisée.',
        );

        // Le lien émis fonctionne réellement pour le propriétaire de la conversation.
        $this->client->request('GET', $dl['fichiers'][0]['url']);
        $this->assertResponseIsSuccessful();
    }

    public function testAgrafeFichiersSurBulleUtilisateur(): void
    {
        ['guest' => $guest, 'entreprise' => $e] = $this->seed();
        $conversation = $this->makeConversation($e, $guest);
        $this->client->loginUser($this->user(self::GUEST_EMAIL));
        $this->attach($e->getId(), $conversation->getId(), [$this->upFile('dossier.txt', 'Contenu du dossier a joindre au message.', 'text/plain')]);

        // Envoi d'un message alors qu'une pièce jointe est attachée : le message
        // « transporte » l'instantané des fichiers (agrafe).
        $this->client->request(
            'POST',
            sprintf('/admin/assistant-ia/api/messages/%d/%d', $e->getId(), $conversation->getId()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['contenu' => 'Analyse ce fichier']),
        );
        $this->assertResponseIsSuccessful();
        $rep = $this->jsonResponse();
        $this->assertNotNull($rep['user']['fichiersJoints'], 'Le message porte l’instantané des pièces jointes.');
        $this->assertSame('dossier.txt', $rep['user']['fichiersJoints'][0]['nom']);

        // Rendu PERSISTANT : l'agrafe fichiers figure sur la bulle utilisateur.
        $this->client->request('GET', sprintf('/admin/assistant-ia/chat/%d/%d', $e->getId(), $conversation->getId()));
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('aic-msg-attach--file', $html);
        $this->assertStringContainsString('dossier.txt', $html);
    }
}
