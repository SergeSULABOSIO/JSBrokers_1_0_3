<?php

namespace App\Tests\Workspace;

use App\Ai\Mutation\MutationPlan;
use App\Ai\Mutation\MutationReferences;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\AnalyserFichierPourSaisieTool;
use App\Entity\AssistantConversation;
use App\Entity\Assureur;
use App\Entity\Chargement;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use App\Service\Workspace\WorkspaceMutationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * DE LA PIÈCE JOINTE À L'ENREGISTREMENT : parcours complet de la saisie assistée
 * depuis un document — analyse (état des lieux + gabarit) puis exécution du plan
 * par le moteur d'écriture ordinaire.
 *
 * Ce que ce test protège avant tout : la pièce source ne se détache pas de la
 * donnée qu'elle a produite. Après exécution, le Document doit être MEMBRE de la
 * collection « documents » de la cotation — pas un document flottant, pas un
 * simple identifiant posé sur une colonne.
 */
class KetSaisieDepuisFichierTest extends WebTestCase
{
    private const ENT = 'PHPUnit-KetSaisie SARL';
    private const OWNER = 'phpunit-ketsaisie-owner@test.local';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private WorkspaceMutationService $service;
    private AnalyserFichierPourSaisieTool $outil;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(WorkspaceMutationService::class);
        $this->outil = static::getContainer()->get(AnalyserFichierPourSaisieTool::class);
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
        $conn->executeStatement('DELETE c FROM chargement_pour_prime c JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE t FROM tranche t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE c FROM cotation c JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE p FROM piste p JOIN entreprise e ON p.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE f FROM assistant_conversation_fichier f JOIN assistant_conversation c ON f.conversation_id = c.id JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE m FROM assistant_message m JOIN assistant_conversation c ON m.conversation_id = c.id JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE c FROM assistant_conversation c JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE c FROM chargement c JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE a FROM assureur a JOIN entreprise e ON a.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE r FROM risque r JOIN entreprise e ON r.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
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

        // Invité PROPRIÉTAIRE : bypass des contrôles d'accès.
        $inv = (new Invite())->setNom('Owner')->setUtilisateur($owner)->setEntreprise($ent)->setProprietaire(true);
        $this->em->persist($inv);

        $conversation = (new AssistantConversation())->setEntreprise($ent)->setInvite($inv);
        $this->em->persist($conversation);

        $this->em->flush();

        return [$ent, $inv, $owner, $conversation];
    }

    /** Référentiels et acteurs que la proposition NOMME (à résoudre par le serveur). */
    private function seedReferentiels(Entreprise $ent): void
    {
        $assureur = (new Assureur())
            ->setNom('SUNU Assurances IARD')->setEmail('contact@sunu.test')
            ->setNumimpot('N1')->setIdnat('I1')->setRccm('R1')->setEntreprise($ent);
        $this->em->persist($assureur);

        $client = (new Client())->setNom('TRANSCO SARL')->setExonere(false)->setEntreprise($ent);
        $this->em->persist($client);

        $risque = (new Risque())
            ->setCode('RCA')->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)
            ->setNomComplet('Responsabilité Civile Automobile')->setImposable(true)->setEntreprise($ent);
        $this->em->persist($risque);

        $piste = (new Piste())
            ->setNom('Flotte TRANSCO 2026')->setTypeAvenant(1)->setDescriptionDuRisque('Flotte de 12 véhicules')
            ->setExercice(2026)->setClient($client)->setRisque($risque)->setEntreprise($ent);
        $this->em->persist($piste);

        // Référentiel des composantes de prime (Chargement.nom : 20 caractères max).
        foreach (['Prime nette', 'Frais accessoires'] as $nom) {
            $this->em->persist((new Chargement())->setNom($nom)->setEntreprise($ent));
        }

        $this->em->flush();
    }

    /** Upload réel via l'endpoint : stockage Vich privé + extraction du texte. */
    private function uploaderFichier(Entreprise $e, AssistantConversation $c, string $nom, string $contenu): int
    {
        $path = tempnam(sys_get_temp_dir(), 'phpunit_ks_');
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

    /** Contenu SUBSTANTIEL : un .txt trop court est deviné « octet-stream » et rejeté. */
    private function propositionTexte(): string
    {
        return <<<TXT
        PROPOSITION D'ASSURANCE N° PRO-2026-0142
        Assureur : SUNU Assurances IARD
        Preneur d'assurance : TRANSCO SARL
        Objet : Flotte automobile 2026 - 12 vehicules de transport de marchandises
        Risque couvert : Responsabilite Civile Automobile
        Periode de couverture : du 01/01/2026 au 31/12/2026, soit douze (12) mois.

        DECOMPTE DE LA PRIME
        Prime nette ......................... 9 000,00 USD
        Frais accessoires ................... 1 250,50 USD
        --------------------------------------------------
        Prime totale a payer ................ 10 250,50 USD

        Cette proposition est valable trente jours a compter de sa date d'emission.
        Fait a Kinshasa, le 12 mars 2026.
        TXT;
    }

    /** Recharge un scope propre après em->clear() (entités managées obligatoires). */
    private function rechargerScope(int $idEnt, int $idInv, int $idConv): array
    {
        $ent = $this->em->getRepository(Entreprise::class)->find($idEnt);
        $inv = $this->em->getRepository(Invite::class)->find($idInv);
        $conv = $this->em->getRepository(AssistantConversation::class)->find($idConv);
        $owner = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => self::OWNER]);

        return [new AiScope($ent, $inv, $conv), $owner];
    }

    /**
     * Le parcours complet : une proposition PDF-like attachée au chat devient une
     * cotation en base, avec sa composition de prime ET la pièce source classée
     * DANS sa collection « documents ».
     */
    public function testDeLaPieceJointeALaCotationAvecSaPieceClassee(): void
    {
        [$ent, $inv, $owner, $conversation] = $this->seed();
        $this->seedReferentiels($ent);
        $idEnt = $ent->getId();
        $idInv = $inv->getId();
        $idConv = $conversation->getId();

        $this->client->loginUser($owner);
        $idFichier = $this->uploaderFichier($ent, $conversation, 'proposition-transco.txt', $this->propositionTexte());

        $this->em->clear();
        [$scope, $owner] = $this->rechargerScope($idEnt, $idInv, $idConv);

        // --- 1. ÉTAT DES LIEUX -------------------------------------------------
        $resultat = $this->outil->execute([
            'fichierId' => $idFichier,
            'entite'    => 'Cotation',
            'valeurs'   => [
                ['champ' => 'nom', 'valeur' => 'Flotte automobile 2026', 'source' => 'Objet : Flotte automobile 2026'],
                ['champ' => 'duree', 'valeur' => '12', 'source' => 'soit douze (12) mois'],
                ['champ' => 'assureur', 'valeur' => 'SUNU Assurances IARD', 'source' => 'Assureur : SUNU Assurances IARD'],
                ['champ' => 'piste', 'valeur' => 'Flotte TRANSCO 2026', 'source' => 'Preneur : TRANSCO SARL'],
                ['champ' => 'nom', 'valeur' => 'Prime nette', 'source' => 'Prime nette 9 000,00', 'collection' => 'chargements', 'ligne' => 0],
                ['champ' => 'montantFlatExceptionel', 'valeur' => '9 000,00', 'source' => 'Prime nette 9 000,00 USD', 'collection' => 'chargements', 'ligne' => 0],
                ['champ' => 'type', 'valeur' => 'Prime nette', 'source' => 'Prime nette', 'collection' => 'chargements', 'ligne' => 0],
                ['champ' => 'nom', 'valeur' => 'Frais accessoires', 'source' => 'Frais accessoires 1 250,50', 'collection' => 'chargements', 'ligne' => 1],
                ['champ' => 'montantFlatExceptionel', 'valeur' => '1 250,50', 'source' => 'Frais accessoires 1 250,50 USD', 'collection' => 'chargements', 'ligne' => 1],
                ['champ' => 'type', 'valeur' => 'Frais accessoires', 'source' => 'Frais accessoires', 'collection' => 'chargements', 'ligne' => 1],
            ],
        ], $scope);

        $this->assertSame(AiToolResult::STATUS_OK, $resultat->status);
        $data = $resultat->data;
        $this->assertTrue($data['pret'], 'L’analyse aboutit : ' . json_encode($data, JSON_UNESCAPED_UNICODE));

        // AUCUN bouton : l'analyse n'est pas un plan, elle précède l'autorisation.
        $this->assertNull($resultat->uiAction, 'L’état des lieux ne doit JAMAIS faire apparaître de barre de décision.');

        // Les montants mal typographiés sont normalisés, les relations résolues.
        $valeurs = array_column($data['trouve'], 'valeur', 'champ');
        $this->assertSame(12, $valeurs['duree'], 'La durée s’écrit en MOIS (label du formulaire).');
        $this->assertSame('SUNU Assurances IARD', $valeurs['assureur']);
        $this->assertSame([], $data['aResoudre'], 'Aucune ambiguïté : un seul candidat par nom.');
        $this->assertSame([], $data['manquants'], 'nom et duree sont fournis.');

        // Chaque valeur porte sa SOURCE : c'est ce que l'utilisateur vérifiera.
        foreach ($data['trouve'] as $ligne) {
            $this->assertNotSame('', $ligne['source'], sprintf('Le champ « %s » doit citer le fichier.', $ligne['champ']));
        }

        // La pièce est rattachable par la collection du formulaire : aucun avertissement.
        $this->assertTrue($data['pieceSource']['rattachable']);
        $this->assertSame('documents', $data['pieceSource']['collection']);
        $this->assertNull($data['pieceSource']['avertissement']);

        // --- 2. LE GABARIT EST EXÉCUTABLE TEL QUEL -----------------------------
        $gabarit = $data['gabaritPlan'];
        $this->assertCount(1, $gabarit, 'Une seule opération de tête : la cotation porte tout.');
        $this->assertSame('Cotation', $gabarit[0]['entite']);

        $collections = array_column($gabarit[0]['collections'], 'elements', 'collection');
        $this->assertArrayHasKey('chargements', $collections);
        $this->assertCount(2, $collections['chargements'], 'Les deux composantes de prime sont dans le gabarit.');
        $this->assertArrayHasKey('documents', $collections, 'La pièce source est classée par la collection « documents ».');
        $this->assertSame('@fichier:' . $idFichier, $collections['documents'][0]['champs']['fichier']);

        // --- 3. EXÉCUTION par le moteur d'écriture ordinaire -------------------
        $plan = MutationPlan::fromArray($gabarit);
        $this->assertFalse($plan->estVide());

        $refs = MutationReferences::live();
        $journal = [];
        $this->em->wrapInTransaction(function () use ($plan, $scope, $owner, $refs, &$journal) {
            foreach ($plan->operationsOrdonnees() as $op) {
                $journal[] = $this->service->executer($op, $scope, $owner, $refs);
            }
        });
        $idCotation = $journal[0]['id'] ?? null;
        $this->assertNotNull($idCotation);

        // --- 4. LE RÉSULTAT EN BASE -------------------------------------------
        $this->em->clear();
        $cotation = $this->em->getRepository(Cotation::class)->find($idCotation);
        $this->assertNotNull($cotation);
        $this->assertSame('Flotte automobile 2026', $cotation->getNom());
        $this->assertSame(12, $cotation->getDuree());
        $this->assertSame('SUNU Assurances IARD', $cotation->getAssureur()?->getNom());
        $this->assertSame('Flotte TRANSCO 2026', $cotation->getPiste()?->getNom());

        // La composition de la prime : sans le TYPE, la commission resterait à 0.
        $this->assertCount(2, $cotation->getChargements());
        foreach ($cotation->getChargements() as $chargement) {
            $this->assertNotNull($chargement->getType(), 'Chaque composante porte son type de chargement.');
        }
        $montants = array_map(
            static fn ($c) => $c->getMontantFlatExceptionel(),
            $cotation->getChargements()->toArray(),
        );
        sort($montants);
        $this->assertEqualsWithDelta([1250.50, 9000.0], $montants, 0.001, 'Les séparateurs de milliers sont interprétés.');

        // L'EXIGENCE CENTRALE : la pièce source est MEMBRE de la collection.
        $documents = $cotation->getDocuments();
        $this->assertCount(1, $documents, 'Le fichier source est classé dans les documents de la cotation.');
        $document = $documents->first();
        $this->assertSame('proposition-transco.txt', $document->getNom());
        $this->assertSame($idCotation, $document->getCotation()?->getId(), 'Le lien inverse est bien posé.');
        $this->assertNotNull($document->getNomFichierStocke(), 'Le binaire est attaché au Document.');

        $storage = static::getContainer()->get(StorageInterface::class);
        $this->assertFileExists($storage->resolvePath($document, 'fichier'));

        // Le binaire ORIGINAL de la conversation est préservé (copie temporaire déplacée).
        $piece = $this->em->getRepository(\App\Entity\AssistantConversationFichier::class)->find($idFichier);
        $this->assertNotNull($piece, 'La pièce jointe subsiste dans la conversation.');
        $this->assertFileExists($storage->resolvePath($piece, 'fichier'));
    }

    /**
     * Niveau 2 : sur une rubrique sans collection « documents » au formulaire mais
     * que Document sait référencer, le classement devient une opération de tête
     * chaînée — et il aboutit réellement.
     */
    public function testRubriqueSansCollectionClasseParOperationChainee(): void
    {
        [$ent, $inv, $owner, $conversation] = $this->seed();
        $idEnt = $ent->getId();
        $idInv = $inv->getId();
        $idConv = $conversation->getId();

        $this->client->loginUser($owner);
        $idFichier = $this->uploaderFichier($ent, $conversation, 'statuts-groupe.txt', $this->propositionTexte());

        $this->em->clear();
        [$scope, $owner] = $this->rechargerScope($idEnt, $idInv, $idConv);

        $resultat = $this->outil->execute([
            'fichierId' => $idFichier,
            'entite'    => 'Classeur',
            'valeurs'   => [
                ['champ' => 'nom', 'valeur' => 'Dossiers 2026', 'source' => 'PROPOSITION'],
                ['champ' => 'description', 'valeur' => 'Propositions reçues en 2026', 'source' => 'PROPOSITION D\'ASSURANCE'],
            ],
        ], $scope);

        $data = $resultat->data;
        $this->assertTrue($data['pret']);
        $this->assertTrue($data['pieceSource']['rattachable']);
        $this->assertSame('classeur', $data['pieceSource']['champ']);

        // Deux opérations de tête : le classeur (ref socle), puis son document.
        $gabarit = $data['gabaritPlan'];
        $this->assertCount(2, $gabarit);
        $this->assertSame('Classeur', $gabarit[0]['entite']);
        $this->assertSame('socle', $gabarit[0]['ref']);
        $this->assertSame('Document', $gabarit[1]['entite']);
        $this->assertSame('@socle', $gabarit[1]['champs']['classeur']);

        $plan = MutationPlan::fromArray($gabarit);
        $refs = MutationReferences::live();
        $journal = [];
        $this->em->wrapInTransaction(function () use ($plan, $scope, $owner, $refs, &$journal) {
            foreach ($plan->operationsOrdonnees() as $op) {
                $journal[] = $this->service->executer($op, $scope, $owner, $refs);
            }
        });

        $this->em->clear();
        $document = $this->em->getRepository(\App\Entity\Document::class)->find($journal[1]['id']);
        $this->assertNotNull($document);
        $this->assertSame('Dossiers 2026', $document->getClasseur()?->getNom(), 'Le renvoi « @socle » a été résolu.');
        $this->assertNotNull($document->getNomFichierStocke());
    }

    /**
     * Rubrique sans aucun lien Document : l'analyse aboutit, mais elle porte
     * l'AVERTISSEMENT que le fichier ne sera pas conservé — et la consigne impose
     * de le restituer mot pour mot avant toute autorisation.
     */
    public function testRubriqueSansRattachementAvertitAvantAutorisation(): void
    {
        [$ent, $inv, $owner, $conversation] = $this->seed();
        $idEnt = $ent->getId();
        $idInv = $inv->getId();
        $idConv = $conversation->getId();

        $this->client->loginUser($owner);
        $idFichier = $this->uploaderFichier($ent, $conversation, 'note-risque.txt', $this->propositionTexte());

        $this->em->clear();
        [$scope] = $this->rechargerScope($idEnt, $idInv, $idConv);

        $resultat = $this->outil->execute([
            'fichierId' => $idFichier,
            'entite'    => 'Risque',
            'valeurs'   => [
                ['champ' => 'code', 'valeur' => 'RCA2', 'source' => 'Risque couvert'],
                ['champ' => 'nomComplet', 'valeur' => 'RC Automobile', 'source' => 'Responsabilite Civile Automobile'],
            ],
        ], $scope);

        $data = $resultat->data;
        $this->assertTrue($data['pret']);
        $this->assertFalse($data['pieceSource']['rattachable']);
        $this->assertStringContainsString('NE SERA PAS CONSERVÉ EN BASE', $data['pieceSource']['avertissement']);

        // Le gabarit ne contient AUCUN classement : rien ne doit laisser croire l'inverse.
        $gabarit = $data['gabaritPlan'];
        $this->assertCount(1, $gabarit);
        $this->assertArrayNotHasKey('collections', $gabarit[0]);
        $this->assertArrayNotHasKey('fichier', $gabarit[0]['champs']);

        // La consigne EXIGE la restitution mot pour mot, dans le même message.
        $this->assertStringContainsString($data['pieceSource']['avertissement'], $data['note']);
        $this->assertStringContainsString('MOT POUR MOT', $data['note']);
    }
}
