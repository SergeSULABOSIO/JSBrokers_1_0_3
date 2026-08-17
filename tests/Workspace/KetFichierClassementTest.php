<?php

namespace App\Tests\Workspace;

use App\Ai\Mutation\ConversationFichierRef;
use App\Ai\Mutation\MutationOperation;
use App\Ai\Mutation\MutationPlan;
use App\Ai\Mutation\PlanBuilder;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\TelechargerDocumentsTool;
use App\Entity\AssistantConversation;
use App\Entity\Client;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Service\Workspace\MutationException;
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

        // LE RANGEMENT AUTOMATIQUE VAUT AUSSI POUR KET, et c'est ici qu'on le prouve : le
        // document n'a pas été créé par l'interface mais par un plan d'écriture, sans que
        // personne ne mentionne de classeur. La règle est posée au ras de Doctrine
        // (ClasseurAutomatiqueListener) précisément pour que les deux chemins n'aient pas
        // à la connaître — encore faut-il que celui de Ket y passe réellement.
        $this->assertNotNull(
            $document->getClasseur(),
            'Un document créé par Ket pour un client doit atterrir dans le classeur de ce client.',
        );
        $this->assertSame('Client Classement', $document->getClasseur()?->getNom());
        $this->assertSame(
            $clientId,
            $document->getClasseur()?->getClient()?->getId(),
            'Le classeur doit être RELIÉ au client, pas seulement porter son nom.',
        );

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

    /**
     * LE CHEMIN RÉELLEMENT EMPRUNTÉ PAR KET — et le seul qui n'était pas testé.
     *
     * L'INCIDENT DU 2026-08-14. Les tests existants construisent la MutationOperation
     * à la main et attaquent directement le service : ils sautent PlanBuilder, donc
     * AliasDeChamps. Or `fichier` est une propriété VICH, pas une colonne Doctrine :
     * elle est absente de inventaireChamps(), donc traitée comme un champ INCONNU, et
     * rapprochée par libellé du seul champ contenant le mot « fichier » —
     * `nomFichierStocke` (« Nom fichier stocke »). Le marqueur changeait de clé,
     * DocumentType ne connaissait pas cette clé, l'upload était jeté sans un mot, et
     * Ket annonçait « 1 opération exécutée, conforme au plan validé » sur un document
     * VIDE. L'utilisateur a cherché son contrat pendant deux tours.
     *
     * Ce test emprunte la route officielle — celle que le prompt dicte au modèle
     * (AiContextBuilder : « entite=Document, champs:{…, "fichier":"@fichier:<id>"} »).
     */
    public function testUnFichierTraversePlanBuilderSansChangerDeChamp(): void
    {
        [$ent, $inv, $owner, $conversation] = $this->seed();
        $client = (new Client())->setNom('Client PlanBuilder')->setExonere(false)->setEntreprise($ent);
        $this->em->persist($client);
        $this->em->flush();
        $clientId = $client->getId();

        $this->client->loginUser($owner);
        $idFichier = $this->uploaderFichier($ent, $conversation, 'contrat.txt', 'Contrat signe par le client.');

        $this->em->clear();
        $conversation = $this->em->getRepository(AssistantConversation::class)->find($conversation->getId());
        $ent = $this->em->getRepository(Entreprise::class)->find($ent->getId());
        $inv = $this->em->getRepository(Invite::class)->find($inv->getId());
        $owner = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => self::OWNER]);
        $scope = new AiScope($ent, $inv, $conversation);

        $plan = MutationPlan::fromArray([[
            'op'     => 'create',
            'entite' => 'Document',
            'champs' => [
                'nom'     => 'Contrat signé',
                'client'  => $clientId,
                'fichier' => ConversationFichierRef::marqueur($idFichier),
            ],
        ]]);

        $resultat = static::getContainer()->get(PlanBuilder::class)
            ->construire($plan, $scope, 'preparer_operations');

        self::assertTrue(
            $resultat->data['pret'] ?? false,
            'Le plan doit être prêt : le champ fichier ne doit PAS être renommé en cours de route. Manquants : '
                . json_encode($resultat->data['manquants'] ?? [], JSON_UNESCAPED_UNICODE),
        );

        // Et la pièce arrive VRAIMENT jusqu'au disque — c'est la seule preuve qui vaille.
        $op = new MutationOperation('create', 'Document', null, [
            'nom'     => 'Contrat signé',
            'client'  => $clientId,
            'fichier' => ConversationFichierRef::marqueur($idFichier),
        ]);
        $step = $this->service->executer($op, $scope, $owner);

        $this->em->clear();
        $document = $this->em->getRepository(Document::class)->find($step['id']);
        self::assertNotNull($document);
        self::assertNotNull(
            $document->getNomFichierStocke(),
            'Le fichier doit être attaché au Document, pas perdu en chemin.',
        );
    }

    /**
     * UNE PIÈCE QUI NE PEUT PAS ÊTRE JOINTE SE DIT.
     *
     * Ket avait référencé une pièce #19 alors que la conversation s'arrêtait à #18.
     * Le marqueur était retiré en silence, le Document créé sans fichier, et le plan
     * annoncé conforme. Un plan qui perdrait la pièce ne doit plus être présentable :
     * il est REFUSÉ, en nommant le champ et les pièces réellement disponibles — sans
     * quoi ni l'utilisateur ni Ket ne peuvent corriger.
     */
    public function testUnePieceIntrouvableRefuseLePlanAuLieuDeLaPerdre(): void
    {
        [$ent, $inv, $owner, $conversation] = $this->seed();
        $client = (new Client())->setNom('Client Piece Absente')->setExonere(false)->setEntreprise($ent);
        $this->em->persist($client);
        $this->em->flush();
        $clientId = $client->getId();

        $this->client->loginUser($owner);
        $idFichier = $this->uploaderFichier($ent, $conversation, 'reel.txt', 'Une piece reellement attachee.');

        $this->em->clear();
        $conversation = $this->em->getRepository(AssistantConversation::class)->find($conversation->getId());
        $ent = $this->em->getRepository(Entreprise::class)->find($ent->getId());
        $inv = $this->em->getRepository(Invite::class)->find($inv->getId());
        $scope = new AiScope($ent, $inv, $conversation);

        $op = new MutationOperation('create', 'Document', null, [
            'nom'     => 'Contrat fantôme',
            'client'  => $clientId,
            'fichier' => ConversationFichierRef::marqueur($idFichier + 10_000),
        ]);

        $analyse = $this->service->analyserOperation($op, $scope);

        self::assertFalse($analyse['ok'], 'Un plan qui perdrait la pièce jointe ne doit pas être présentable.');
        $motifs = implode(' ', array_merge(...array_values($analyse['manquants'])));
        self::assertStringContainsString('fichier', implode(' ', array_keys($analyse['manquants'])));
        self::assertStringContainsString('n’est pas attachée à cette conversation', $motifs);
        // Le motif NOMME ce qui existe : sans cela, personne ne peut corriger.
        self::assertStringContainsString('reel.txt', $motifs, 'Le refus doit lister les pièces disponibles.');
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

    /**
     * FAIL-CLOSED, ET À VOIX HAUTE.
     *
     * Un fichier d'une AUTRE conversation ne doit jamais pouvoir être classé depuis
     * la conversation courante. C'était déjà le cas — mais le marqueur était retiré
     * en SILENCE et le Document se créait quand même, vide, sous un « enregistré avec
     * succès ». Depuis le 2026-08-14 la garantie est plus forte : rien n'est écrit du
     * tout, et le refus nomme la raison. Une pièce perdue se dit.
     */
    public function testFichierHorsConversationNeFuitPas(): void
    {
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
        // Le dry-run refuse : l'utilisateur ne se voit jamais proposer un plan qui
        // perdrait la pièce.
        $analyse = $this->service->analyserOperation($op, $scope);
        $this->assertFalse($analyse['ok'], 'Le plan doit être refusé, pas présenté puis vidé de sa pièce.');

        // Et l'exécution ne crée RIEN plutôt qu'un document vide.
        $avant = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM document');
        try {
            $this->service->executer($op, $scope, $owner);
            $this->fail('L’exécution doit échouer : le fichier d’une autre conversation est hors périmètre.');
        } catch (MutationException $e) {
            $this->assertStringContainsString('Documents', $e->getMessage());
        }

        $this->em->clear();
        $this->assertSame(
            $avant,
            (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM document'),
            'Aucun document ne doit être créé quand sa pièce ne peut pas être jointe.',
        );
    }
}
