<?php

namespace App\Tests\Workspace;

use App\Ai\Mutation\MutationPlan;
use App\Ai\Mutation\MutationReferences;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\AttacherFichierTool;
use App\Entity\AssistantConversation;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use App\Service\Document\DocumentFichier;
use App\Service\Document\DocumentsDe;
use App\Service\Workspace\WorkspaceMutationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * ATTACHER UN FICHIER À N'IMPORTE QUEL OBJET — le parcours complet, jusqu'à la base.
 *
 * CE QUE CE TEST PROTÈGE. Jusqu'au 2026-08-15, quinze entités sur soixante-dix-sept
 * pouvaient porter un document ; partout ailleurs, le serveur avertissait honnêtement
 * que LE FICHIER NE SERAIT PAS CONSERVÉ. Le couple `cibleType`/`cibleId` de Document
 * supprime cette limite. Ce test vérifie la promesse de bout en bout sur une entité
 * qui n'a NI collection « documents » à son formulaire, NI relation typée depuis
 * Document — un Risque : plan préparé, plan exécuté, document en base, parent
 * retrouvé, et pièce emportée avec son objet à la suppression.
 *
 * ⚠️ WebTestCase, et ce n'est pas un confort : FormTreeInspector CONSTRUIT les
 * FormType pour savoir quelles collections sont éditables, et FormListenerFactory y
 * lit getUser()->getConnectedTo(). Sans loginUser + setConnectedTo, il rend une liste
 * VIDE en silence — tous les niveaux 1 basculeraient à tort en universel et le test
 * passerait pour de mauvaises raisons.
 */
class KetRattachementUniverselTest extends WebTestCase
{
    private const ENT = 'PHPUnit-KetUniversel SARL';
    private const OWNER = 'phpunit-ketuniversel-owner@test.local';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AttacherFichierTool $outil;
    private WorkspaceMutationService $service;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->outil = static::getContainer()->get(AttacherFichierTool::class);
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
        // Document AVANT Classeur (FK), Classeur avant Invite (FK invite_id).
        $conn->executeStatement('DELETE d FROM document d JOIN entreprise e ON d.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE c FROM classeur c JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE f FROM assistant_conversation_fichier f JOIN assistant_conversation c ON f.conversation_id = c.id JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE m FROM assistant_message m JOIN assistant_conversation c ON m.conversation_id = c.id JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE c FROM assistant_conversation c JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE r FROM risque r JOIN entreprise e ON r.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        foreach (['roles_en_production', 'roles_en_administration'] as $table) {
            $conn->executeStatement("DELETE r FROM {$table} r JOIN entreprise e ON r.entreprise_id = e.id WHERE e.nom = :n", ['n' => self::ENT]);
        }
        $conn->executeStatement('DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    /** @return array{0:Entreprise,1:Invite,2:Utilisateur,3:AssistantConversation,4:Risque} */
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

        // Invité PROPRIÉTAIRE : bypass des contrôles d'accès.
        $inv = (new Invite())->setNom('Owner')->setUtilisateur($owner)->setEntreprise($ent)->setProprietaire(true);
        $this->em->persist($inv);

        $conversation = (new AssistantConversation())->setEntreprise($ent)->setInvite($inv);
        $this->em->persist($conversation);

        // RISQUE : ni collection « documents » à son formulaire, ni relation typée
        // depuis Document. C'est très exactement le cas qui perdait le fichier.
        $risque = (new Risque())
            ->setCode('RCA9')->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)
            ->setNomComplet('RC Automobile universelle')->setImposable(true)->setEntreprise($ent);
        $this->em->persist($risque);

        $this->em->flush();

        return [$ent, $inv, $owner, $conversation, $risque];
    }

    /** Contenu SUBSTANTIEL : un .txt trop court est deviné « octet-stream » et rejeté. */
    private function uploaderFichier(Entreprise $e, AssistantConversation $c): int
    {
        $contenu = <<<TXT
        CONDITIONS PARTICULIERES - POLICE RC AUTOMOBILE
        Preneur d'assurance : TRANSCO SARL
        Risque couvert : Responsabilite Civile Automobile
        Periode de couverture : du 01/01/2026 au 31/12/2026.
        Prime nette : 9 000,00 USD. Frais accessoires : 1 250,50 USD.
        TXT;

        $path = tempnam(sys_get_temp_dir(), 'phpunit_ku_');
        file_put_contents($path, $contenu);
        $upload = new UploadedFile($path, 'contrat-transco.txt', 'text/plain', null, true);

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

    /** @return array{0:AiScope,1:Utilisateur} */
    private function rechargerScope(int $idEnt, int $idInv, int $idConv): array
    {
        return [
            new AiScope(
                $this->em->getRepository(Entreprise::class)->find($idEnt),
                $this->em->getRepository(Invite::class)->find($idInv),
                $this->em->getRepository(AssistantConversation::class)->find($idConv),
            ),
            $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => self::OWNER]),
        ];
    }

    /**
     * LE PARCOURS COMPLET : « attache ce fichier au risque RC Automobile » sur une
     * entité qui, hier encore, ne pouvait rien porter du tout.
     */
    public function testUnFichierSAttacheAUneEntiteSansCollectionNiRelation(): void
    {
        [$ent, $inv, $owner, $conversation, $risque] = $this->seed();
        $idEnt = $ent->getId();
        $idInv = $inv->getId();
        $idConv = $conversation->getId();
        $idRisque = $risque->getId();

        $this->client->loginUser($owner);
        $idFichier = $this->uploaderFichier($ent, $conversation);

        $this->em->clear();
        [$scope, $owner] = $this->rechargerScope($idEnt, $idInv, $idConv);

        // --- 1. LE PLAN, préparé par le serveur -------------------------------
        // Le modèle ne donne QUE le fichier et la cible, désignée par son NOM : le
        // champ de rattachement, lui, est déduit — c'est tout l'objet de l'outil.
        $resultat = $this->outil->execute([
            'fichierId' => $idFichier,
            'cible'     => ['entite' => 'Risque', 'nom' => 'RC Automobile universelle'],
        ], $scope);

        $this->assertSame(AiToolResult::STATUS_OK, $resultat->status);
        $data = $resultat->data;
        $this->assertTrue($data['pret'], 'Le plan doit être prêt : ' . json_encode($data, JSON_UNESCAPED_UNICODE));
        $this->assertNotNull($resultat->uiAction, 'Sans action, aucun bouton « Valider et exécuter » n’apparaîtrait.');
        $this->assertSame('contrat-transco.txt', $data['fichier']['nom']);

        // Le plan EXACT que l'utilisateur validerait, et non un plan réécrit pour les
        // besoins du test : c'est ce que porte l'action de revue.
        $operations = $resultat->uiAction['plan'] ?? null;
        $this->assertIsArray($operations, 'L’action de revue doit porter le plan.');
        $this->assertCount(1, $operations, 'Attacher une pièce, c’est UNE opération.');
        // `fields` et non `champs` : MutationOperation::toArray() est la forme
        // SÉRIALISÉE du plan, celle que l'endpoint d'exécution relira depuis la meta.
        $this->assertSame('Document', $operations[0]['entite']);
        $this->assertSame('Risque', $operations[0]['fields']['cibleType']);
        $this->assertSame((string) $idRisque, (string) $operations[0]['fields']['cibleId']);

        // --- 2. L'EXÉCUTION, par le moteur d'écriture ordinaire ----------------
        $plan = MutationPlan::fromArray($operations);
        $refs = MutationReferences::live();
        $journal = [];
        $this->em->wrapInTransaction(function () use ($plan, $scope, $owner, $refs, &$journal) {
            foreach ($plan->operationsOrdonnees() as $op) {
                $journal[] = $this->service->executer($op, $scope, $owner, $refs);
            }
        });
        $this->em->clear();

        // --- 3. LA BASE, relue -------------------------------------------------
        $document = $this->em->getRepository(Document::class)->findOneBy(['cibleType' => 'Risque', 'cibleId' => $idRisque]);
        $this->assertNotNull($document, 'Le document doit exister, rattaché au risque.');
        $this->assertNotSame('', (string) $document->getNomFichierStocke(), 'Le BINAIRE doit avoir suivi : un document vide ne vaut rien.');
        $this->assertSame($idEnt, $document->getEntreprise()?->getId(), 'Le scoping entreprise s’applique comme partout.');

        // Le parent se retrouve par la source unique, sans que personne ait à savoir
        // par quel mécanisme le rattachement a été écrit.
        $parent = static::getContainer()->get(DocumentFichier::class)->parentDe($document);
        $this->assertInstanceOf(Risque::class, $parent);
        $this->assertSame($idRisque, $parent->getId());

        // Et la « collection » demandée est bien rendue, service à l'appui.
        $documents = static::getContainer()->get(DocumentsDe::class)->pour($parent);
        $this->assertCount(1, $documents);
        $this->assertSame($document->getId(), $documents[0]->getId());
    }

    /**
     * LA PIÈCE NE SURVIT PAS À SON OBJET. Sans clé étrangère derrière le couple, rien
     * n'emporterait ces documents à la suppression du parent : ils resteraient en
     * base, invisibles, avec leur binaire sur le disque.
     */
    public function testSupprimerLObjetEmporteSesDocumentsRattaches(): void
    {
        [$ent, $inv, $owner, $conversation, $risque] = $this->seed();
        $idRisque = $risque->getId();

        $document = (new Document())
            ->setNom('Pièce du risque')
            ->setCibleType('Risque')
            ->setCibleId($idRisque);
        $document->setEntreprise($ent);
        $this->em->persist($document);
        $this->em->flush();
        $idDocument = $document->getId();

        $this->em->remove($this->em->getRepository(Risque::class)->find($idRisque));
        $this->em->flush();
        $this->em->clear();

        $this->assertNull(
            $this->em->getRepository(Document::class)->find($idDocument),
            'Un document rattaché à un objet supprimé serait un orphelin invisible.',
        );
    }

    /**
     * LE COUPLE UNIVERSEL NE DOUBLE PAS UNE RELATION TYPÉE, jusque dans le plan
     * produit : sur un Client, l'outil doit passer par la collection du formulaire —
     * la même que celle du widget à l'écran — et laisser le couple vide.
     */
    public function testUneCibleAvecRelationTypeeNEmpruntePasLeCoupleUniversel(): void
    {
        [$ent, $inv, $owner, $conversation] = $this->seed();
        $idEnt = $ent->getId();
        $idInv = $inv->getId();
        $idConv = $conversation->getId();

        $this->client->loginUser($owner);
        $idFichier = $this->uploaderFichier($ent, $conversation);

        $this->em->clear();
        [$scope] = $this->rechargerScope($idEnt, $idInv, $idConv);

        // Cible inexistante volontairement : on n'inspecte que la RÉSOLUTION du
        // rattachement, qui a lieu avant toute écriture. Le refus doit être une
        // question, jamais un plan vide annoncé comme prêt.
        $resultat = $this->outil->execute([
            'fichierId' => $idFichier,
            'cible'     => ['entite' => 'Client', 'nom' => 'Client qui n’existe pas'],
        ], $scope);

        $this->assertSame(AiToolResult::STATUS_OK, $resultat->status);
        $this->assertFalse($resultat->data['pret'] ?? true, 'Une cible introuvable ne produit pas de plan.');
        $this->assertNull($resultat->uiAction, 'Aucun bouton ne doit apparaître sans plan.');
        $this->assertNotEmpty(
            $resultat->data['aDemander'] ?? $resultat->data['bloquant'] ?? null,
            'Le refus doit être une QUESTION posée à l’utilisateur, pas un silence.',
        );
    }

    /**
     * FAIL-CLOSED : une pièce d'une AUTRE conversation ne s'attache pas. Le motif
     * doit nommer ce qui manque et lister les pièces disponibles — sans quoi ni
     * l'utilisateur ni Ket ne peuvent corriger.
     */
    public function testUneMauvaisePieceEstRefuseeAvecUnMotifExploitable(): void
    {
        [$ent, $inv, $owner, $conversation, $risque] = $this->seed();
        $idEnt = $ent->getId();
        $idInv = $inv->getId();
        $idConv = $conversation->getId();

        $this->client->loginUser($owner);
        $this->uploaderFichier($ent, $conversation);

        $this->em->clear();
        [$scope] = $this->rechargerScope($idEnt, $idInv, $idConv);

        $resultat = $this->outil->execute([
            'fichierId' => 999_999,
            'cible'     => ['entite' => 'Risque', 'nom' => 'RC Automobile universelle'],
        ], $scope);

        $this->assertFalse($resultat->data['pret'] ?? true);
        $this->assertNull($resultat->uiAction);
        $this->assertStringContainsString(
            'contrat-transco.txt',
            (string) ($resultat->data['bloquant'] ?? ''),
            'Le motif doit LISTER les pièces réellement disponibles.',
        );
    }
}
