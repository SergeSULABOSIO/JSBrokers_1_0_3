<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolProduisantUnPlan;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\AnalyserFichierPourSaisieTool;
use App\Entity\AssistantConversation;
use App\Entity\Assureur;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * L'outil d'ÉTAT DES LIEUX d'une saisie depuis une pièce jointe : gardes
 * fail-closed, résolution des relations par leur nom (résolu / ambigu / absent),
 * normalisation des valeurs lues, et surtout — il ne produit JAMAIS de bouton.
 *
 * WebTestCase : l'outil construit des FormType (inventaire, arbre des
 * collections), ce qui exige un utilisateur authentifié porteur d'un connectedTo.
 */
class AnalyserFichierPourSaisieToolTest extends WebTestCase
{
    private const ENT = 'PHPUnit-AnalyseFic SARL';
    private const OWNER = 'phpunit-analysefic-owner@test.local';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AnalyserFichierPourSaisieTool $outil;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
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
        $conn->executeStatement('DELETE f FROM assistant_conversation_fichier f JOIN assistant_conversation c ON f.conversation_id = c.id JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE c FROM assistant_conversation c JOIN entreprise e ON c.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE a FROM assureur a JOIN entreprise e ON a.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        foreach (['roles_en_production', 'roles_en_administration'] as $table) {
            $conn->executeStatement("DELETE r FROM {$table} r JOIN entreprise e ON r.entreprise_id = e.id WHERE e.nom = :n", ['n' => self::ENT]);
        }
        $conn->executeStatement('DELETE i FROM invite i JOIN entreprise e ON i.entreprise_id = e.id WHERE e.nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :n', ['n' => self::ENT]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :e', ['e' => self::OWNER]);
        $this->em->clear();
    }

    /** @return array{0:AiScope,1:int} scope rechargé + id de la pièce jointe */
    private function seedAvecFichier(?string $contenu = null): array
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

        $inv = (new Invite())->setNom('Owner')->setUtilisateur($owner)->setEntreprise($ent)->setProprietaire(true);
        $this->em->persist($inv);

        $conversation = (new AssistantConversation())->setEntreprise($ent)->setInvite($inv);
        $this->em->persist($conversation);
        $this->em->flush();

        [$idEnt, $idInv, $idConv] = [$ent->getId(), $inv->getId(), $conversation->getId()];
        $this->client->loginUser($owner);

        $contenu ??= 'PROPOSITION D ASSURANCE. Assureur : SUNU Assurances IARD. Objet : flotte automobile '
            . 'de douze vehicules. Prime nette 9 000,00 USD. Periode du 01/01/2026 au 31/12/2026.';
        $path = tempnam(sys_get_temp_dir(), 'phpunit_af_');
        file_put_contents($path, $contenu);
        $this->client->request(
            'POST',
            sprintf('/admin/assistant-ia/api/fichiers/%d/%d', $idEnt, $idConv),
            [],
            ['fichiers' => [new UploadedFile($path, 'proposition.txt', 'text/plain', null, true)]],
        );
        $this->assertResponseIsSuccessful();
        @unlink($path);
        $idFichier = (int) json_decode((string) $this->client->getResponse()->getContent(), true)['fichiers'][0]['id'];

        $this->em->clear();

        return [
            new AiScope(
                $this->em->getRepository(Entreprise::class)->find($idEnt),
                $this->em->getRepository(Invite::class)->find($idInv),
                $this->em->getRepository(AssistantConversation::class)->find($idConv),
            ),
            $idFichier,
        ];
    }

    /**
     * L'INVARIANT LE PLUS IMPORTANT : cet outil précède l'autorisation, il ne
     * chiffre aucun budget et ne doit donc jamais faire apparaître de barre
     * « Valider et exécuter ». Le marqueur des outils de plan lui est interdit.
     */
    public function testNeProduitJamaisUnPlanNiUnBouton(): void
    {
        $this->assertNotInstanceOf(
            AiToolProduisantUnPlan::class,
            $this->outil,
            'Marqué producteur de plan, le prompt annoncerait un bouton qui n’existe pas.',
        );

        [$scope, $idFichier] = $this->seedAvecFichier();
        $resultat = $this->outil->execute([
            'fichierId' => $idFichier,
            'entite'    => 'Cotation',
            'valeurs'   => [['champ' => 'nom', 'valeur' => 'Flotte 2026', 'source' => 'Objet : flotte automobile']],
        ], $scope);

        $this->assertSame(AiToolResult::STATUS_OK, $resultat->status);
        $this->assertNull($resultat->uiAction);
        $this->assertStringContainsString('ARRÊTE-TOI', $resultat->data['note'], 'La consigne impose la pause avant le plan.');
        $this->assertStringContainsString('Puis-je préparer le plan', $resultat->data['note']);
    }

    /** Une entité hors du périmètre d'écriture de Ket n'est jamais analysable. */
    public function testEntiteHorsAllowlistRefusee(): void
    {
        [$scope, $idFichier] = $this->seedAvecFichier();

        $resultat = $this->outil->execute([
            'fichierId' => $idFichier,
            'entite'    => 'RolesEnProduction',
            'valeurs'   => [],
        ], $scope);

        $this->assertSame(AiToolResult::STATUS_INTROUVABLE, $resultat->status);
    }

    /** Un identifiant de pièce inventé ou étranger à la conversation est refusé. */
    public function testFichierHorsConversationRefuse(): void
    {
        [$scope, $idFichier] = $this->seedAvecFichier();

        $resultat = $this->outil->execute([
            'fichierId' => $idFichier + 9999,
            'entite'    => 'Cotation',
            'valeurs'   => [],
        ], $scope);

        $this->assertSame(AiToolResult::STATUS_OK, $resultat->status);
        $this->assertFalse($resultat->data['pret']);
        $this->assertSame('fichier_introuvable', $resultat->data['bloquant']);
        $this->assertStringContainsString('EXACTEMENT', $resultat->data['note'], 'La consigne renvoie aux identifiants réels.');
    }

    /** Deux enregistrements portent un nom voisin : l'utilisateur tranche, jamais Ket. */
    public function testNomAmbiguRemonteLesCandidats(): void
    {
        [$scope, $idFichier] = $this->seedAvecFichier();
        foreach (['SUNU Assurances IARD', 'SUNU Assurances Vie'] as $nom) {
            $this->em->persist(
                (new Assureur())->setNom($nom)->setEmail(strtolower(str_replace(' ', '', $nom)) . '@t.test')
                    ->setNumimpot('N')->setIdnat('I')->setRccm('R')->setEntreprise($scope->entreprise),
            );
        }
        $this->em->flush();

        $resultat = $this->outil->execute([
            'fichierId' => $idFichier,
            'entite'    => 'Cotation',
            'valeurs'   => [
                ['champ' => 'nom', 'valeur' => 'Flotte 2026', 'source' => 'Objet'],
                ['champ' => 'assureur', 'valeur' => 'SUNU Assurances', 'source' => 'Assureur : SUNU Assurances IARD'],
            ],
        ], $scope);

        $data = $resultat->data;
        $this->assertCount(1, $data['aResoudre']);
        $this->assertSame('assureur', $data['aResoudre'][0]['champ']);
        $this->assertCount(2, $data['aResoudre'][0]['candidats']);
        $this->assertSame('SUNU Assurances', $data['aResoudre'][0]['lu'], 'Ce qui a été LU est rappelé.');

        // Une relation ambiguë n'est SURTOUT pas devinée dans le gabarit.
        $this->assertArrayNotHasKey('assureur', $data['gabaritPlan'][0]['champs']);
    }

    /** Un nom introuvable en base devient une proposition de création, pas un silence. */
    public function testNomIntrouvableEstProposeALaCreation(): void
    {
        [$scope, $idFichier] = $this->seedAvecFichier();

        $resultat = $this->outil->execute([
            'fichierId' => $idFichier,
            'entite'    => 'Cotation',
            'valeurs'   => [
                ['champ' => 'nom', 'valeur' => 'Flotte 2026', 'source' => 'Objet'],
                ['champ' => 'assureur', 'valeur' => 'Assureur Inexistant SA', 'source' => 'Assureur : Assureur Inexistant SA'],
            ],
        ], $scope);

        $data = $resultat->data;
        $this->assertCount(1, $data['aCreer']);
        $this->assertSame('assureur', $data['aCreer'][0]['champ']);
        $this->assertSame('Assureur', $data['aCreer'][0]['entite']);
        $this->assertSame('aucun enregistrement de ce nom', $data['aCreer'][0]['motif']);
    }

    /**
     * Une Cotation n'a que « nom » et « duree » de bloquants : sans ce garde-fou,
     * une cotation sans piste ni assureur serait annoncée comme un succès.
     */
    public function testRelationsVidesRemonteesMemeQuandLaBaseLesTolere(): void
    {
        [$scope, $idFichier] = $this->seedAvecFichier();

        $resultat = $this->outil->execute([
            'fichierId' => $idFichier,
            'entite'    => 'Cotation',
            'valeurs'   => [
                ['champ' => 'nom', 'valeur' => 'Flotte 2026', 'source' => 'Objet'],
                ['champ' => 'duree', 'valeur' => '12', 'source' => 'douze mois'],
            ],
        ], $scope);

        $data = $resultat->data;
        $this->assertSame([], $data['manquants'], 'nom et duree suffisent à la validation Doctrine…');

        $champs = array_column($data['relationsNonResolues'], 'champ');
        $this->assertContains('piste', $champs, '…mais une cotation sans piste doit être signalée.');
        $this->assertContains('assureur', $champs);
        $this->assertNotContains('entreprise', $champs, 'Les relations auto-scopées ne se demandent pas.');
        $this->assertNotContains('invite', $champs);
    }

    /** Les étapes du parcours métier qu'aucune donnée ne couvre sont énoncées. */
    public function testEtapesNonCouvertesSontEnoncees(): void
    {
        [$scope, $idFichier] = $this->seedAvecFichier();

        $resultat = $this->outil->execute([
            'fichierId' => $idFichier,
            'entite'    => 'Cotation',
            'valeurs'   => [['champ' => 'nom', 'valeur' => 'Flotte 2026', 'source' => 'Objet']],
        ], $scope);

        $collections = array_column($resultat->data['etapesNonCouvertes'], 'collection');
        $this->assertContains('chargements', $collections, 'Sans composante, la prime resterait à 0 : il faut le dire.');
        $this->assertContains('tranches', $collections);
    }

    /** Une collection absente du formulaire n'est jamais écrite (surface = celle de l'écran). */
    public function testCollectionHorsFormulaireIgnoree(): void
    {
        [$scope, $idFichier] = $this->seedAvecFichier();

        $resultat = $this->outil->execute([
            'fichierId' => $idFichier,
            'entite'    => 'Cotation',
            'valeurs'   => [
                ['champ' => 'nom', 'valeur' => 'Flotte 2026', 'source' => 'Objet'],
                ['champ' => 'truc', 'valeur' => 'x', 'source' => 's', 'collection' => 'inexistante', 'ligne' => 0],
            ],
        ], $scope);

        $this->assertSame([], $resultat->data['lignes'], 'La collection inconnue est écartée.');

        // Seule subsiste la collection « documents » posée par le classement de la
        // pièce source : rien de ce que le modèle a inventé n'atteint le gabarit.
        $collections = array_column($resultat->data['gabaritPlan'][0]['collections'], 'collection');
        $this->assertSame(['documents'], $collections);
    }

    /** Un champ inconnu de l'entité n'est jamais soumis au formulaire. */
    public function testChampInconnuIgnore(): void
    {
        [$scope, $idFichier] = $this->seedAvecFichier();

        $resultat = $this->outil->execute([
            'fichierId' => $idFichier,
            'entite'    => 'Cotation',
            'valeurs'   => [
                ['champ' => 'nom', 'valeur' => 'Flotte 2026', 'source' => 'Objet'],
                ['champ' => 'primeTotale', 'valeur' => '10250.50', 'source' => 'Prime totale'],
            ],
        ], $scope);

        $this->assertArrayNotHasKey('primeTotale', $resultat->data['gabaritPlan'][0]['champs'], 'Un attribut CALCULÉ ne s’écrit pas.');
    }

    /** Un format illisible se dit franchement — il n'autorise aucune invention. */
    public function testFormatNonLisibleRefuseFranchement(): void
    {
        [$scope, $idFichier] = $this->seedAvecFichier();

        // Efface l'extrait et pose un mime non lisible en vision : la pièce est muette.
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'UPDATE assistant_conversation_fichier SET texte_extrait = NULL, mime_type = :m WHERE id = :id',
            ['m' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'id' => $idFichier],
        );
        $this->em->clear();
        $scope = new AiScope(
            $this->em->getRepository(Entreprise::class)->find($scope->entreprise->getId()),
            $this->em->getRepository(Invite::class)->find($scope->invite->getId()),
            $this->em->getRepository(AssistantConversation::class)->find($scope->conversation->getId()),
        );

        $resultat = $this->outil->execute([
            'fichierId' => $idFichier,
            'entite'    => 'Cotation',
            'valeurs'   => [],
        ], $scope);

        $this->assertFalse($resultat->data['pret']);
        $this->assertSame('format_non_lisible', $resultat->data['bloquant']);
        $this->assertStringContainsString('n\'invente aucune donnée', $resultat->data['note']);
    }
}
