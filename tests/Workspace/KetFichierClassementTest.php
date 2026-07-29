<?php

namespace App\Tests\Workspace;

use App\Ai\Mutation\ConversationFichierRef;
use App\Ai\Mutation\MutationOperation;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\TelechargerDocumentsTool;
use App\Entity\AssistantConversation;
use App\Entity\Client;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Service\Workspace\WorkspaceMutationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * Ket CLASSE une pièce jointe de la conversation dans un enregistrement : le
 * marqueur « @fichier:<id> » d'un plan preparer_operations est résolu en upload
 * réel et injecté dans le DocumentType, à travers le pipeline d'écriture normal
 * (WorkspaceMutationService). Vérifie : Document créé avec fichier physique,
 * préservation du fichier original attaché, et fail-closed (fichier hors
 * conversation refusé → champ requis manquant).
 */
class KetFichierClassementTest extends WebTestCase
{
    private const ENT = 'PHPUnit-KetFic SARL';
    private const OWNER = 'phpunit-ketfic-owner@test.local';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private WorkspaceMutationService $service;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(WorkspaceMutationService::class);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE utilisateur SET connected_to_id = NULL WHERE email = :e', ['e' => self::OWNER]);
        $conn->executeStatement('DELETE d FROM document d JOIN entreprise e ON d.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE f FROM assistant_conversation_fichier f JOIN assistant_conversation c ON f.conversation_id = c.id JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE c FROM assistant_conversation c JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE cl FROM client cl JOIN entreprise e ON cl.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        foreach (['roles_en_production', 'roles_en_administration'] as $table) {
            $conn->executeStatement("DELETE r FROM {$table} r JOIN entreprise e ON r.entreprise_id = e.id WHERE e.nom = :n", ['n' => self::ENT]);
        }
        $conn->executeStatement('DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    /** @return array{0:Entreprise,1:Invite,2:Utilisateur,3:AssistantConversation} */
    private function seed(): array
    {
        $owner = (new Utilisateur())->setEmail(self::OWNER)->setNom('PHPUnit')->setVerified(true);
        $owner->setPassword('x');
        $owner->setPaidTokens(1_000_000);
        $this->em->persist($owner);

        $ent = (new Entreprise())
            ->setNom(self::ENT)->setLicence('LIC')->setAdresse('1 rue')->setTelephone('+243000')
            ->setRccm('R')->setIdnat('I')->setNumimpot('N')->setUtilisateur($owner);
        $this->em->persist($ent);
        $owner->setConnectedTo($ent);

        // Invité PROPRIÉTAIRE : bypass des contrôles d'accès (aucun rôle à câbler).
        $inv = (new Invite())->setNom('Owner')->setUtilisateur($owner)->setEntreprise($ent)->setProprietaire(true);
        $this->em->persist($inv);

        $conversation = (new AssistantConversation())->setEntreprise($ent)->setInvite($inv);
        $this->em->persist($conversation);

        $this->em->flush();

        return [$ent, $inv, $owner, $conversation];
    }

    /** Upload d'un fichier via l'endpoint (stockage Vich réel dans le dossier privé). */
    private function uploaderFichier(Entreprise $e, AssistantConversation $c, string $nom, string $contenu): int
    {
        $path = tempnam(sys_get_temp_dir(), 'phpunit_kf_');
        file_put_contents($path, $contenu);
        $upload = new UploadedFile($path, $nom, 'text/plain', null, true);

        $this->client->request(
            'POST',
            sprintf('/admin/assistant-ia/api/fichiers/%d/%d', $e->getId(), $c->getId()),
            [],
            ['fichiers' => [$upload]],
        );
        $this->assertResponseIsSuccessful();
        @unlink($path);

        return (int) json_decode((string) $this->client->getResponse()->getContent(), true)['fichiers'][0]['id'];
    }

    public function testClasserFichierDansDocument(): void
    {
        [$ent, $inv, $owner, $conversation] = $this->seed();
        $client = (new Client())->setNom('Client Classement')->setExonere(false)->setEntreprise($ent);
        $this->em->persist($client);
        $this->em->flush();
        $clientId = $client->getId();

        $this->client->loginUser($owner);
        $idFichier = $this->uploaderFichier($ent, $conversation, 'police.txt', 'Contenu de la police signee.');

        // Recharge la conversation (avec sa pièce jointe) pour le scope de mutation.
        $this->em->clear();
        $conversation = $this->em->getRepository(AssistantConversation::class)->find($conversation->getId());
        $ent = $this->em->getRepository(Entreprise::class)->find($ent->getId());
        $inv = $this->em->getRepository(Invite::class)->find($inv->getId());
        $owner = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => self::OWNER]);
        $scope = new AiScope($ent, $inv, $conversation);

        // Dry-run : le champ fichier requis valide grâce au marqueur résolu.
        $op = new MutationOperation('create', 'Document', null, [
            'nom'     => 'Police signée',
            'client'  => $clientId,
            'fichier' => ConversationFichierRef::marqueur($idFichier),
        ]);
        $analyse = $this->service->analyserOperation($op, $scope);
        $this->assertTrue(
            $analyse['ok'],
            'Le plan est prêt : la pièce jointe est injectée dans le champ fichier. Manquants : '
                . json_encode($analyse['manquants'], JSON_UNESCAPED_UNICODE),
        );

        // Exécution réelle : Document créé + fichier physique déplacé.
        $step = $this->service->executer($op, $scope, $owner);
        $this->assertNotNull($step['id']);

        $this->em->clear();
        $document = $this->em->getRepository(Document::class)->find($step['id']);
        $this->assertNotNull($document);
        $this->assertSame('Police signée', $document->getNom());
        $this->assertNotNull($document->getNomFichierStocke(), 'Le fichier physique du Document est enregistré.');
        $this->assertSame($clientId, $document->getClient()?->getId());

        // Le fichier physique du Document existe.
        $storage = static::getContainer()->get(StorageInterface::class);
        $cheminDoc = $storage->resolvePath($document, 'fichier');
        $this->assertNotNull($cheminDoc);
        $this->assertFileExists($cheminDoc);

        // Le fichier ORIGINAL attaché à la conversation est PRÉSERVÉ (copie temporaire déplacée).
        $fichierConv = $this->em->getRepository(\App\Entity\AssistantConversationFichier::class)->find($idFichier);
        $this->assertNotNull($fichierConv, 'La pièce jointe de la conversation subsiste après classement.');
        $cheminConv = $storage->resolvePath($fichierConv, 'fichier');
        $this->assertNotNull($cheminConv);
        $this->assertFileExists($cheminConv, 'Le binaire original de la conversation n’a pas été déplacé.');
    }

    public function testTelechargerDocumentDepuisLeChat(): void
    {
        // 1) Crée un Document RÉEL avec fichier (via le classement d'une pièce jointe).
        [$ent, $inv, $owner, $conversation] = $this->seed();
        $client = (new Client())->setNom('Client Doc DL')->setExonere(false)->setEntreprise($ent);
        $this->em->persist($client);
        $this->em->flush();
        $clientId = $client->getId();

        $this->client->loginUser($owner);
        $idFichier = $this->uploaderFichier($ent, $conversation, 'contrat.txt', 'Contenu du contrat a classer puis telecharger.');

        $this->em->clear();
        $conversation = $this->em->getRepository(AssistantConversation::class)->find($conversation->getId());
        $ent = $this->em->getRepository(Entreprise::class)->find($ent->getId());
        $inv = $this->em->getRepository(Invite::class)->find($inv->getId());
        $owner = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => self::OWNER]);
        $scope = new AiScope($ent, $inv, $conversation);

        $op = new MutationOperation('create', 'Document', null, [
            'nom'     => 'Contrat signé',
            'client'  => $clientId,
            'fichier' => ConversationFichierRef::marqueur($idFichier),
        ]);
        $docId = (int) $this->service->executer($op, $scope, $owner)['id'];
        $this->assertGreaterThan(0, $docId);

        // 2) L'outil telecharger_documents propose le téléchargement du Document.
        $this->em->clear();
        $ent = $this->em->getRepository(Entreprise::class)->find($ent->getId());
        $inv = $this->em->getRepository(Invite::class)->find($inv->getId());
        $scope = new AiScope($ent, $inv);

        $tool = static::getContainer()->get(TelechargerDocumentsTool::class);
        $res = $tool->execute(['ids' => [$docId]], $scope);
        $this->assertSame(AiToolResult::STATUS_OK, $res->status);
        $this->assertSame('files-download', $res->uiAction['type']);
        $this->assertCount(1, $res->uiAction['fichiers']);
        $url = $res->uiAction['fichiers'][0]['url'];
        $this->assertStringContainsString(sprintf('/documents/%d/%d/download', $ent->getId(), $docId), $url);

        // 3) Le lien télécharge réellement (route fail-closed, invité autorisé)
        //    ET le fichier servi conserve son EXTENSION (sinon inouvrable sur disque).
        $this->client->request('GET', $url);
        $this->assertResponseIsSuccessful();
        $disposition = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        $this->assertStringContainsString('.txt', $disposition, 'Le nom de téléchargement doit porter l’extension réelle.');

        // 4) Un identifiant inexistant n'expose rien (fail-closed).
        $res2 = $tool->execute(['ids' => [99999999]], $scope);
        $this->assertSame(AiToolResult::STATUS_INTROUVABLE, $res2->status);
    }

    public function testFichierHorsConversationNeFuitPas(): void
    {
        // Un fichier appartenant à une AUTRE conversation ne doit JAMAIS pouvoir
        // être classé depuis la conversation courante (fail-closed) : le marqueur
        // reste non résolu et AUCUN fichier n'est attaché (pas de fuite).
        [$ent, $inv, $owner, $conversation] = $this->seed();
        $client = (new Client())->setNom('Client FailClosed')->setExonere(false)->setEntreprise($ent);
        $this->em->persist($client);

        // Seconde conversation (du même invité) avec sa propre pièce jointe.
        $autreConv = (new AssistantConversation())->setEntreprise($ent)->setInvite($inv);
        $this->em->persist($autreConv);
        $this->em->flush();
        $clientId = $client->getId();

        $this->client->loginUser($owner);
        $idFichierAutre = $this->uploaderFichier($ent, $autreConv, 'autre.txt', 'Contenu de la conversation voisine.');

        $this->em->clear();
        $conversation = $this->em->getRepository(AssistantConversation::class)->find($conversation->getId());
        $ent = $this->em->getRepository(Entreprise::class)->find($ent->getId());
        $inv = $this->em->getRepository(Invite::class)->find($inv->getId());
        $owner = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => self::OWNER]);
        // Scope de la conversation A, mais on référence le fichier de la conversation B.
        $scope = new AiScope($ent, $inv, $conversation);

        $op = new MutationOperation('create', 'Document', null, [
            'nom'     => 'Doc tentative de fuite',
            'client'  => $clientId,
            'fichier' => ConversationFichierRef::marqueur($idFichierAutre),
        ]);
        $step = $this->service->executer($op, $scope, $owner);

        $this->em->clear();
        $document = $this->em->getRepository(Document::class)->find($step['id']);
        $this->assertNotNull($document);
        $this->assertNull(
            $document->getNomFichierStocke(),
            'Le fichier d’une autre conversation ne doit PAS être attaché (fail-closed).',
        );
    }
}
