<?php

namespace App\Tests\Workspace;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Piste;
use App\Entity\ReversementRetroAgent;
use App\Entity\Risque;
use App\Entity\Utilisateur;
use App\Service\Retro\LotDeVersement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * CORRIGER UN VIREMENT DÉJÀ ENREGISTRÉ.
 *
 * ── POURQUOI CETTE FENÊTRE ET PAS LE DIALOGUE GÉNÉRIQUE ─────────────────────────────
 * Depuis que la rubrique replie chaque lot sur son porteur, une ligne REPRÉSENTE un
 * virement entier. Le dialogue générique n'en aurait montré qu'une échéance sur six, et
 * laissé corriger un montant sans voir les autres.
 *
 * ── LES QUATRE RÈGLES QUE CE TEST TIENT ─────────────────────────────────────────────
 *  1. ON MET À JOUR, ON NE RECRÉE PAS. Recréer aurait changé l'identifiant des lignes —
 *     donc perdu les documents qui y sont rattachés.
 *  2. CE QUI N'EST PAS REPOSTÉ SORT du virement.
 *  3. ⚠ LE PIÈGE DU PORTEUR : la pièce justificative est écrite sur le membre au plus
 *     petit id. Retirer CETTE ligne-là détruirait le bordereau avec elle, et le
 *     décaissement se retrouverait sans preuve sans que rien ne le dise.
 *  4. UN VIREMENT ROUVERT A DÉJÀ SA PREUVE : corriger une date ne doit pas exiger qu'on
 *     redépose le même bordereau.
 */
class ReversementEditionTest extends WebTestCase
{
    private const OWNER_EMAIL = 'phpunit-vir-owner@test.local';
    private const ENTREPRISE_NOM = 'PHPUnit Virement SARL';
    private const LOT = 'VIR-2026-EDIT';

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

    private function cleanUp(): void
    {
        $conn = $this->em()->getConnection();
        foreach ([
            'document', 'reversement_retro_agent', 'avenant', 'cotation', 'piste',
            'client', 'risque', 'invite',
        ] as $table) {
            $conn->executeStatement(
                "DELETE t FROM {$table} t JOIN entreprise e ON t.entreprise_id = e.id WHERE e.nom = :nom",
                ['nom' => self::ENTREPRISE_NOM],
            );
        }
        // LE WORKSPACE ACTIF POINTE SUR L'ENTREPRISE : on dénoue le lien avant de la
        // supprimer, sinon la contrainte de clé étrangère refuse — et le nettoyage
        // échouerait AVANT le test, ce qui se lit comme un échec du code testé.
        $conn->executeStatement(
            'UPDATE utilisateur u JOIN entreprise e ON u.connected_to_id = e.id
             SET u.connected_to_id = NULL WHERE e.nom = :nom',
            ['nom' => self::ENTREPRISE_NOM],
        );
        $conn->executeStatement('DELETE FROM entreprise WHERE nom = :nom', ['nom' => self::ENTREPRISE_NOM]);
        $conn->executeStatement('DELETE FROM utilisateur WHERE email = :email', ['email' => self::OWNER_EMAIL]);
        $this->em()->clear();
    }

    /**
     * Un virement de trois échéances — 10, 20, 30 — dont le PORTEUR tient le bordereau.
     *
     * CHAQUE LIGNE DÉSIGNE UNE AFFAIRE DISTINCTE, et il le faut : le rapprochement se fait
     * sur le couple (échéance, affaire). Trois lignes sans affaire auraient toutes porté la
     * même clé « 0:0 », et le test aurait vérifié un cas que l'écriture refuse de toute
     * façon — une ligne qui ne désigne rien n'est pas enregistrable.
     *
     * @return array{ids: int[], avenantIds: int[], entrepriseId: int}
     */
    private function semer(): array
    {
        $em = $this->em();

        $owner = (new Utilisateur())->setEmail(self::OWNER_EMAIL)->setNom('Virement')
            ->setVerified(true)->setPassword('x');
        $em->persist($owner);

        $entreprise = (new Entreprise())->setNom(self::ENTREPRISE_NOM)->setLicence('LIC')
            ->setAdresse('1 rue')->setTelephone('+2430000')->setRccm('R')->setIdnat('I')->setNumimpot('N')
            ->setUtilisateur($owner);
        $em->persist($entreprise);

        // L'INVITÉ DU PROPRIÉTAIRE, et le workspace ACTIF : `getInvite()` résout l'invité
          // de l'entreprise vers laquelle l'utilisateur est connecté. Sans `connectedTo`,
          // toutes les routes rendent « Aucun invité trouvé ».
        $owner->setConnectedTo($entreprise);

        $gestionnaire = (new Invite())->setNom('Gestionnaire')->setProprietaire(true);
        $gestionnaire->setUtilisateur($owner)->setEntreprise($entreprise);
        $em->persist($gestionnaire);

        $agent = (new Invite())->setNom('Alice Virement')->setProprietaire(false)->setEntreprise($entreprise);
        $em->persist($agent);
        $em->flush();

        $risque = (new Risque())->setCode('VIR')->setNomComplet('Risque Virement')
            ->setDescription('Risque du virement')->setBranche(Risque::BRANCHE_IARD_OU_NON_VIE)
            ->setImposable(true);
        $risque->setEntreprise($entreprise);
        $em->persist($risque);

        $clientAssure = (new Client())->setNom('Client Virement')->setExonere(false);
        $clientAssure->setEntreprise($entreprise);
        $em->persist($clientAssure);

        // TROIS AFFAIRES : c'est ce qui donne trois clés de rapprochement distinctes.
        $avenants = [];
        for ($i = 0; $i < 3; ++$i) {
            $piste = (new Piste())->setNom('Piste Virement ' . $i)->setTypeAvenant(0)
                ->setDescriptionDuRisque('Risque virement')->setExercice((int) date('Y'))
                ->setClient($clientAssure)->setRisque($risque);
            $piste->setEntreprise($entreprise)->setInvite($gestionnaire);
            $em->persist($piste);

            $cotation = (new Cotation())->setNom('Cotation Virement ' . $i)->setDuree(365);
            $cotation->setPiste($piste)->setEntreprise($entreprise);
            $em->persist($cotation);

            $avenant = (new Avenant())->setReferencePolice('POL-VIR-' . $i)->setNumero('0')
                ->setDescription('Police virement')
                ->setStartingAt(new \DateTimeImmutable('-30 days'))
                ->setEndingAt(new \DateTimeImmutable('+335 days'));
            $avenant->setEntreprise($entreprise)->setInvite($gestionnaire);
            $cotation->addAvenant($avenant);
            $em->persist($avenant);
            $avenants[] = $avenant;
        }
        $em->flush();

        $ids = [];
        $membres = [];
        foreach ([10.0, 20.0, 30.0] as $i => $montant) {
            $r = (new ReversementRetroAgent())
                ->setAgent($agent)
                ->setAvenant($avenants[$i])
                ->setMontant($montant)
                ->setPaidAt(new \DateTimeImmutable('2026-08-01'))
                ->setReference(self::LOT)
                ->setLotReference(self::LOT);
            $r->setEntreprise($entreprise)->setInvite($agent);
            $em->persist($r);
            $membres[] = $r;
        }
        $em->flush();

        // L'ordre d'écriture est celui des identifiants : le porteur règle l'affaire 0.
        $avenantIds = [];
        foreach ($membres as $membre) {
            $ids[] = $membre->getId();
            $avenantIds[] = $membre->getAvenant()->getId();
        }

        // LE BORDEREAU EST SUR LE PORTEUR — le plus petit id. C'est la convention de
        // l'écran, et c'est elle qui rend le retrait de cette ligne dangereux.
        $piece = (new Document())->setNom('bordereau.pdf');
        $piece->setReversementRetroAgent($em->getRepository(ReversementRetroAgent::class)->find($ids[0]));
        $piece->setEntreprise($entreprise)->setInvite($agent);
        $em->persist($piece);
        $em->flush();

        return ['ids' => $ids, 'avenantIds' => $avenantIds, 'entrepriseId' => $entreprise->getId()];
    }

    /**
     * @param array<int, array{montant: float}> $lignes
     */
    private function poster(
        int $id,
        array $lignes,
        string $paidAt = '2026-08-01',
        array $piecesRetirees = [],
        bool $avecPiece = false,
    ): array {
        $this->client->request(
            'POST',
            '/admin/retro-agent/reversement/' . $id . '/editer',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'lignes' => $lignes,
                'paidAt' => $paidAt,
                'reference' => self::LOT,
                'piecesRetirees' => $piecesRetirees,
                'avecPiece' => $avecPiece,
            ]),
        );

        $reponse = $this->client->getResponse();
        $decode = json_decode((string) $reponse->getContent(), true);
        if (!is_array($decode)) {
            $decode = ['__statut' => $reponse->getStatusCode(), '__corps' => substr((string) $reponse->getContent(), 0, 300)];
        }

        return $decode;
    }

    private function connecter(): void
    {
        $utilisateur = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::OWNER_EMAIL]);
        $this->client->loginUser($utilisateur);
    }

    /**
     * LES MEMBRES DU LOT SONT CEUX QUE L'ÉDITION ROUVRE — et le porteur est le plus petit id.
     *
     * C'est la règle partagée par les trois usages : rouvrir un virement, le supprimer, et
     * relire ses pièces. Trois copies auraient fini par diverger sur le cas du versement
     * isolé — d'où ce contrôle sur les deux formes.
     */
    public function testLeLotSeLitDeNimporteLequelDeSesMembres(): void
    {
        $s = $this->semer();
        /** @var LotDeVersement $lots */
        $lots = static::getContainer()->get(LotDeVersement::class);
        $repo = $this->em()->getRepository(ReversementRetroAgent::class);

        foreach ($s['ids'] as $id) {
            $membres = $lots->membresDuLot($repo->find($id));
            self::assertCount(3, $membres, 'Le virement se lit entier depuis chacune de ses lignes.');
            self::assertSame($s['ids'][0], $membres[0]->getId(), 'Le porteur est le plus petit id.');
        }
    }

    /**
     * ⚠ LE PIÈGE DU PORTEUR — retirer la ligne qui tient le bordereau ne le détruit pas.
     *
     * Sans le transfert, la suppression du porteur emportait son document en cascade : le
     * virement restait justifié à l'écran (le compte est lu sur le lot) mais la pièce
     * n'existait plus. Un décaissement sans preuve, silencieusement.
     */
    public function testRetirerLePorteurNeDetruitPasSonBordereau(): void
    {
        $s = $this->semer();
        $this->connecter();

        // On ne repose QUE deux lignes : le porteur — celle à 10 — sort du virement.
        // On ne repose QUE l'affaire n°1 : le porteur — qui règle l'affaire n°0 et tient
        // le bordereau — sort du virement.
        $reponse = $this->poster($s['ids'][0], [
            ['trancheId' => 0, 'avenantId' => $s['avenantIds'][1], 'montant' => 20.0],
        ]);

        self::assertSame(1, $reponse['crees'] ?? null, 'Une seule ligne reste.');
        self::assertSame(2, $reponse['retires'] ?? null, 'Les deux autres sortent du virement.');

        $this->em()->clear();
        $pieces = $this->em()->getRepository(Document::class)->findBy(['nom' => 'bordereau.pdf']);
        self::assertCount(1, $pieces, 'Le bordereau a survécu au retrait de la ligne qui le portait.');
        self::assertNotNull(
            $pieces[0]->getReversementRetroAgent(),
            'Et il est rattaché au membre qui reste, pas laissé orphelin.',
        );
    }

    /**
     * CORRIGER UNE DATE N'EXIGE PAS DE REDÉPOSER LE BORDEREAU.
     *
     * La garde « pas de versement sans preuve » vaut à la création. Appliquée telle quelle à
     * un virement rouvert, elle aurait refusé toute correction tant qu'on n'aurait pas
     * redéposé la même pièce — donc fait exister deux fois le même bordereau, ou renoncé à
     * la correction.
     */
    public function testCorrigerUneDateSansRedeposerLaPiece(): void
    {
        $s = $this->semer();
        $this->connecter();

        $reponse = $this->poster(
            $s['ids'][0],
            [['trancheId' => 0, 'avenantId' => $s['avenantIds'][0], 'montant' => 10.0]],
            '2026-09-15',
        );

        self::assertResponseIsSuccessful('Le virement a déjà sa preuve : la correction passe.');
        self::assertSame(1, $reponse['crees'] ?? null);

        $this->em()->clear();
        $restant = $this->em()->getRepository(ReversementRetroAgent::class)->find($s['ids'][0]);
        self::assertNotNull($restant, 'La ligne conservée garde son identifiant : on met à jour, on ne recrée pas.');
        self::assertSame('2026-09-15', $restant->getPaidAt()?->format('Y-m-d'));
    }

    /**
     * UN VIREMENT QUI RETOMBE À UNE SEULE LIGNE N'EST PLUS UN LOT.
     *
     * La `lotReference` n'est posée qu'à partir de deux lignes — sans quoi un versement
     * isolé pourrait être fondu dans le lot d'un autre. La règle valait à la création ;
     * elle doit valoir aussi quand une correction fait maigrir le virement.
     */
    public function testUnVirementRamenéAUneLigneCesseDEtreUnLot(): void
    {
        $s = $this->semer();
        $this->connecter();

        $this->poster($s['ids'][0], [['trancheId' => 0, 'avenantId' => $s['avenantIds'][0], 'montant' => 42.0]]);

        $this->em()->clear();
        $restant = $this->em()->getRepository(ReversementRetroAgent::class)->find($s['ids'][0]);
        self::assertSame(42.0, $restant->getMontant(), 'Le montant corrigé est écrit.');
        self::assertNull($restant->getLotReference(), 'Seul, il n\'est plus membre d\'un lot.');
    }

    /** La fenêtre s'ouvre en édition, cochée sur ce que le virement règle déjà. */
    public function testLaFenetreSOuvreCocheeSurLesEcheancesDuVirement(): void
    {
        $s = $this->semer();
        $this->connecter();

        $this->client->request('GET', '/admin/retro-agent/reversement/' . $s['ids'][0] . '/editer');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringStartsWith('<div', ltrim($html), 'Un fragment, comme le picker de création.');
        self::assertStringContainsString('data-controller="reversement-retro-picker"', $html);
        // Le titre DIT qu'on corrige, et nomme le virement.
        self::assertStringContainsString('Corriger le virement', $html);
        self::assertStringContainsString(self::LOT, $html);
        // La pièce déjà déposée est annoncée, et la garde du bouton la connaît.
        self::assertStringContainsString('data-reversement-retro-picker-piece-deja-value="true"', $html);
        self::assertStringContainsString('déjà justifié', $html);

        // ET LA PIÈCE EST NOMMÉE, avec de quoi la retirer. Annoncer « 1 pièce » sans la
        // montrer laissait devant un compte qu'on ne pouvait ni vérifier ni corriger.
        self::assertStringContainsString('bordereau.pdf', $html);
        self::assertStringContainsString('click->reversement-retro-picker#basculerPiece', $html);
    }

    /**
     * REMPLACER UNE PIÈCE : la retirer et en annoncer une autre dans le même geste.
     */
    public function testUnePieceSeRetirePourEtreRemplacee(): void
    {
        $s = $this->semer();
        $this->connecter();
        $pieceId = $this->em()->getRepository(Document::class)
            ->findOneBy(['nom' => 'bordereau.pdf'])->getId();

        $this->poster(
            $s['ids'][0],
            [['trancheId' => 0, 'avenantId' => $s['avenantIds'][0], 'montant' => 10.0]],
            '2026-08-01',
            [$pieceId],
            avecPiece: true,
        );

        self::assertResponseIsSuccessful();
        $this->em()->clear();
        self::assertCount(
            0,
            $this->em()->getRepository(Document::class)->findBy(['nom' => 'bordereau.pdf']),
            'La pièce marquée est bien supprimée.',
        );
    }

    /**
     * ⚠ RETIRER LA DERNIÈRE PREUVE SANS EN DÉPOSER UNE AUTRE EST REFUSÉ.
     *
     * C'est l'ordre des opérations qui le rend vrai : les pièces marquées partent
     * AVANT que la garde ne compte ce qui reste. Compter d'abord aurait laissé passer
     * un enregistrement qui efface la dernière preuve du virement — un décaissement
     * nu, accepté par la règle même qui l'interdit.
     */
    public function testRetirerLaDernierePreuveSansLaRemplacerEstRefuse(): void
    {
        $s = $this->semer();
        $this->connecter();
        $pieceId = $this->em()->getRepository(Document::class)
            ->findOneBy(['nom' => 'bordereau.pdf'])->getId();

        $this->poster(
            $s['ids'][0],
            [['trancheId' => 0, 'avenantId' => $s['avenantIds'][0], 'montant' => 10.0]],
            '2026-08-01',
            [$pieceId],
        );

        self::assertResponseStatusCodeSame(422, 'Un virement ne reste pas sans preuve.');
    }
}
