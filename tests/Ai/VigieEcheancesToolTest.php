<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\VigieEcheancesTool;
use App\Entity\Avenant;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Tache;
use App\Entity\Tranche;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\DashboardDataProvider;
use App\Services\Search\PortefeuilleCritereFactory;
use App\Services\Tranche\TranchePaiementService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Vigie des échéances : gating fail-closed PAR VOLET (un volet hors périmètre
 * est omis avec mention, jamais d'échec global tant qu'un volet reste lisible),
 * clamp de l'horizon, plafond de lignes, déclencheurs simulés. Tests purs :
 * résolveur d'accès, provider de tableau de bord et suivi des paiements
 * doublés en mémoire.
 */
class VigieEcheancesToolTest extends TestCase
{
    private const LIBELLES = [
        'Avenant'              => 'Avenants',
        'Tache'                => 'Tâches',
        'Piste'                => 'Pistes',
        'NotificationSinistre' => 'Sinistres',
        'Tranche'              => 'Tranches',
    ];

    private function makeTool(
        array $canRead,
        ?DashboardDataProvider $dashboard = null,
        ?TranchePaiementService $tranchePaiement = null,
    ): VigieEcheancesTool {
        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('libellesEntites')->willReturn(self::LIBELLES);
        $resolver->method('canRead')->willReturnCallback(
            static fn (Invite $invite, string $shortName) => $canRead[$shortName] ?? false,
        );

        if ($tranchePaiement === null) {
            $tranchePaiement = $this->createMock(TranchePaiementService::class);
            $tranchePaiement->method('lister')->willReturn([
                'items' => [],
                'totaux' => ['nb' => 0, 'totalPrime' => 0.0, 'totalSoldePrime' => 0.0, 'totalSoldeCommission' => 0.0],
                'totalItems' => 0,
                'currentPage' => 1,
                'totalPages' => 1,
            ]);
        }

        return new VigieEcheancesTool(
            $resolver,
            $dashboard ?? $this->createMock(DashboardDataProvider::class),
            $tranchePaiement,
            // Invité sans id : la fabrique rend un critère vide, donc aucune
            // restriction de portefeuille — l'outil interroge le cabinet entier,
            // comportement historique que ces tests décrivent.
            new PortefeuilleCritereFactory($this->createMock(EntityManagerInterface::class)),
        );
    }

    private function makeScope(): AiScope
    {
        return new AiScope(new Entreprise(), new Invite());
    }

    public function testBriefCompletRestitueLesQuatreVolets(): void
    {
        $avenant = (new Avenant())->setEndingAt(new \DateTimeImmutable('+10 days'));
        $tache = (new Tache())->setDescription('Relancer le client Alpha');

        $dashboard = $this->createMock(DashboardDataProvider::class);
        $dashboard->method('getAllRenouvellements')->willReturn([$avenant]);
        $dashboard->method('getTachesNonCloses')->willReturn([$tache]);
        $dashboard->method('getPistesEnCours')->willReturn([]);
        $dashboard->method('getDerniersSinistres')->willReturn([]);

        $tool = $this->makeTool(array_fill_keys(array_keys(self::LIBELLES), true), $dashboard);
        $result = $tool->execute(['volet' => 'tout'], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame(['renouvellements', 'taches', 'pistes', 'sinistres', 'impayes'], array_keys($result->data['volets']));
        $this->assertSame(30, $result->data['horizonJours']);
        $this->assertArrayNotHasKey('horsPerimetre', $result->data);
        $this->assertSame('Relancer le client Alpha', $result->data['volets']['taches']['lignes'][0]['description']);
        $this->assertSame(10, $result->data['volets']['renouvellements']['aVenir']['lignes'][0]['joursRestants']);
        $this->assertNull($result->uiAction);
    }

    /**
     * LE CONTRESENS À RENDRE IMPOSSIBLE. Avec un unique « total », deux lignes toutes
     * à venir se lisaient « aucune police échue » — ce que l'assistant a affirmé alors
     * que la rubrique en affichait cinq. Chaque population porte désormais son compte.
     */
    public function testRenouvellementsSeparentEchuesEtAVenirAvecLeursComptes(): void
    {
        $dashboard = $this->createMock(DashboardDataProvider::class);
        $dashboard->method('getAllRenouvellements')->willReturn([
            (new Avenant())->setEndingAt(new \DateTimeImmutable('-93 days')),
            (new Avenant())->setEndingAt(new \DateTimeImmutable('-2 days')),
            (new Avenant())->setEndingAt(new \DateTimeImmutable('+16 days')),
        ]);

        $tool = $this->makeTool(['Avenant' => true], $dashboard);
        $volet = $tool->execute(['volet' => 'renouvellements'], $this->makeScope())
            ->data['volets']['renouvellements'];

        $this->assertSame(2, $volet['echues']['total']);
        $this->assertSame(1, $volet['aVenir']['total']);
        $this->assertSame(3, $volet['total']);
        $this->assertFalse($volet['tronque']);

        // Le retard est NOMMÉ, pas laissé au signe d'un nombre.
        $this->assertSame(93, $volet['echues']['lignes'][0]['joursRetard']);
        $this->assertSame(-93, $volet['echues']['lignes'][0]['joursRestants']);
        $this->assertArrayNotHasKey('joursRetard', $volet['aVenir']['lignes'][0]);
        $this->assertStringContainsString('ÉCHUES', $volet['rappel']);
    }

    /** Une police expirant AUJOURD'HUI n'est pas échue : elle est à venir (borne à minuit). */
    public function testPoliceExpirantAujourdhuiEstAVenir(): void
    {
        $dashboard = $this->createMock(DashboardDataProvider::class);
        $dashboard->method('getAllRenouvellements')->willReturn([
            (new Avenant())->setEndingAt(new \DateTimeImmutable('today')),
        ]);

        $tool = $this->makeTool(['Avenant' => true], $dashboard);
        $volet = $tool->execute(['volet' => 'renouvellements'], $this->makeScope())
            ->data['volets']['renouvellements'];

        $this->assertSame(0, $volet['echues']['total']);
        $this->assertSame(1, $volet['aVenir']['total']);
        $this->assertSame(0, $volet['aVenir']['lignes'][0]['joursRestants']);
    }

    /**
     * Le plafond s'applique PAR POPULATION : neuf échéances lointaines ne doivent
     * jamais évincer de l'échantillon la seule police échue, qui est l'urgence.
     */
    public function testPlafondRenouvellementsSAppliqueParPopulation(): void
    {
        $avenants = [(new Avenant())->setEndingAt(new \DateTimeImmutable('-5 days'))];
        for ($i = 1; $i <= 9; ++$i) {
            $avenants[] = (new Avenant())->setEndingAt(new \DateTimeImmutable('+' . $i . ' days'));
        }

        $dashboard = $this->createMock(DashboardDataProvider::class);
        $dashboard->method('getAllRenouvellements')->willReturn($avenants);

        $tool = $this->makeTool(['Avenant' => true], $dashboard);
        $volet = $tool->execute(['volet' => 'renouvellements'], $this->makeScope())
            ->data['volets']['renouvellements'];

        $this->assertCount(1, $volet['echues']['lignes']);
        $this->assertSame(1, $volet['echues']['total']);
        $this->assertCount(8, $volet['aVenir']['lignes']);
        $this->assertSame(9, $volet['aVenir']['total']);
        $this->assertTrue($volet['tronque']);
    }

    public function testHorizonEstClampe(): void
    {
        $dashboard = $this->createMock(DashboardDataProvider::class);
        $dashboard->expects($this->once())
            ->method('getAllRenouvellements')
            ->with($this->anything(), 180)
            ->willReturn([]);

        $tool = $this->makeTool(['Avenant' => true], $dashboard);
        $result = $tool->execute(['volet' => 'renouvellements', 'horizonJours' => 999], $this->makeScope());

        $this->assertSame(180, $result->data['horizonJours']);
    }

    public function testVoletHorsPerimetreEstOmisAvecMention(): void
    {
        $dashboard = $this->createMock(DashboardDataProvider::class);
        $dashboard->method('getAllRenouvellements')->willReturn([]);
        $dashboard->method('getTachesNonCloses')->willReturn([]);
        $dashboard->method('getPistesEnCours')->willReturn([]);
        $dashboard->expects($this->never())->method('getDerniersSinistres');

        $tool = $this->makeTool(
            ['Avenant' => true, 'Tache' => true, 'Piste' => true, 'NotificationSinistre' => false, 'Tranche' => true],
            $dashboard,
        );
        $result = $tool->execute(['volet' => 'tout'], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertArrayNotHasKey('sinistres', $result->data['volets']);
        $this->assertSame(['Sinistres'], $result->data['horsPerimetre']);
    }

    /**
     * VOLET IMPAYÉS PARTITIONNÉ PAR DETTE. Comme pour les renouvellements (echues/aVenir),
     * un total unique mélangeait ici deux débiteurs — l'assuré pour la prime, l'assureur
     * pour la commission — au point de faire dire « 5 impayés » puis « une seule prime
     * réellement due » du même jeu de lignes. Les deux sous-ensembles sont disjoints par
     * construction : le second exige une prime PAYÉE.
     */
    public function testVoletImpayesEstPartitionneParDette(): void
    {
        $primeDue = (new Tranche())
            ->setNom('Tranche 1')
            ->setPayableAt(new \DateTimeImmutable('-40 days'))
            ->setEcheanceAt(new \DateTimeImmutable('-10 days'));
        $primeDue->clientNom = 'Client Alpha';
        $primeDue->statutPaiement = 'Non payée';
        $primeDue->urgenceRecouvrement = 'Critique · retard 10 j';
        $primeDue->primeSoldeDue = 800.0;
        $primeDue->solde_restant_du = 120.0;

        $commissionSeule = (new Tranche())
            ->setNom('Tranche 2')
            ->setPayableAt(new \DateTimeImmutable('-40 days'))
            ->setEcheanceAt(new \DateTimeImmutable('-3 days'));
        $commissionSeule->clientNom = 'Client Bêta';
        $commissionSeule->statutPaiement = 'Prime payée, commission due';
        $commissionSeule->urgenceRecouvrement = 'Critique · retard 3 j';
        $commissionSeule->primeSoldeDue = 0.0;
        $commissionSeule->solde_restant_du = 90.0;

        // Deux appels distincts (prime due, puis commission exigible) : le mock répond
        // dans l'ordre où l'outil les émet.
        $tranchePaiement = $this->createMock(TranchePaiementService::class);
        $tranchePaiement->method('lister')->willReturnOnConsecutiveCalls(
            [
                'items' => [$primeDue],
                'totaux' => ['nb' => 1, 'totalPrime' => 1000.0, 'totalSoldePrime' => 800.0, 'totalSoldeCommission' => 120.0],
                'totalItems' => 1, 'currentPage' => 1, 'totalPages' => 1,
            ],
            [
                'items' => [$commissionSeule],
                'totaux' => ['nb' => 1, 'totalPrime' => 500.0, 'totalSoldePrime' => 0.0, 'totalSoldeCommission' => 90.0],
                'totalItems' => 1, 'currentPage' => 1, 'totalPages' => 1,
            ],
        );

        $tool = $this->makeTool(['Tranche' => true], null, $tranchePaiement);
        $result = $tool->execute(['volet' => 'impayes'], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $volet = $result->data['volets']['impayes'];

        $ligne = $volet['primes']['lignes'][0];
        $this->assertSame('Client Alpha', $ligne['client']);
        $this->assertSame(10, $ligne['joursRetard']);
        $this->assertSame(800.0, $ligne['soldePrime']);
        $this->assertSame(120.0, $ligne['soldeCommission']);
        $this->assertSame('Critique · retard 10 j', $ligne['urgence']);
        $this->assertSame(800.0, $volet['primes']['totaux']['totalSoldePrime']);
        $this->assertSame(1, $volet['primes']['total']);
        $this->assertSame('l\'assuré', $volet['primes']['debiteur']);
        $this->assertFalse($volet['primes']['tronque']);

        $this->assertSame('Client Bêta', $volet['commissions']['lignes'][0]['client']);
        $this->assertSame(0.0, $volet['commissions']['lignes'][0]['soldePrime']);
        $this->assertSame(1, $volet['commissions']['total']);
        $this->assertSame('l\'assureur', $volet['commissions']['debiteur']);

        $this->assertSame(2, $volet['total']);
        // Le rappel nomme la partition : sans lui, le total global se relit comme un
        // compte de primes dues.
        $this->assertStringContainsString('DISJOINTS', $volet['rappel']);
        $this->assertStringContainsString('primes.total', $volet['rappel']);
    }

    public function testTousVoletsHorsPerimetreRefuse(): void
    {
        $tool = $this->makeTool(array_fill_keys(array_keys(self::LIBELLES), false));
        $result = $tool->execute(['volet' => 'tout'], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_HORS_PERIMETRE, $result->status);
    }

    public function testPlafondDeLignesEtDrapeauTronque(): void
    {
        $taches = [];
        for ($i = 1; $i <= 9; ++$i) {
            $taches[] = (new Tache())->setDescription('Tâche ' . $i);
        }
        $dashboard = $this->createMock(DashboardDataProvider::class);
        $dashboard->method('getTachesNonCloses')->willReturn($taches);

        $tool = $this->makeTool(['Tache' => true], $dashboard);
        $result = $tool->execute(['volet' => 'taches'], $this->makeScope());

        $volet = $result->data['volets']['taches'];
        $this->assertCount(8, $volet['lignes']);
        $this->assertTrue($volet['tronque']);
    }

    public function testTacheEchueEstMarqueeEnRetard(): void
    {
        $tache = (new Tache())
            ->setDescription('Tâche échue')
            ->setToBeEndedAt(new \DateTimeImmutable('-3 days'));
        $dashboard = $this->createMock(DashboardDataProvider::class);
        $dashboard->method('getTachesNonCloses')->willReturn([$tache]);

        $tool = $this->makeTool(['Tache' => true], $dashboard);
        $result = $tool->execute(['volet' => 'taches'], $this->makeScope());

        $this->assertTrue($result->data['volets']['taches']['lignes'][0]['enRetard']);
    }

    public function testVoletInconnuIntrouvable(): void
    {
        $tool = $this->makeTool(array_fill_keys(array_keys(self::LIBELLES), true));
        $result = $tool->execute(['volet' => 'inconnu'], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_INTROUVABLE, $result->status);
    }

    public function testMatchDeclencheursEtNonCollisions(): void
    {
        $tool = $this->makeTool([]);
        $scope = $this->makeScope();

        $this->assertSame(['volet' => 'tout'], $tool->match('Quelles sont mes échéances ?', $scope));
        $this->assertSame(['volet' => 'tout'], $tool->match('Donne-moi le brief du jour', $scope));
        $this->assertSame(
            ['volet' => 'renouvellements', 'horizonJours' => 60],
            $tool->match('Quels renouvellements sous 60 jours ?', $scope),
        );
        $this->assertSame(['volet' => 'taches'], $tool->match('Quelles tâches en retard ?', $scope));

        // Les polices ÉCHUES relèvent de ce volet depuis qu'il les couvre : sans cela,
        // la question ne trouvait aucun outil et le modèle répondait de mémoire.
        $this->assertSame(['volet' => 'renouvellements'], $tool->match('Quelles polices sont échues chez moi ?', $scope));
        $this->assertSame(['volet' => 'renouvellements'], $tool->match('Mes polices expirées', $scope));

        // Domaine d'autres outils : liste => rechercher_entites, combien => compter_entites.
        $this->assertNull($tool->match('Liste les tâches', $scope));
        $this->assertNull($tool->match('Combien de renouvellements ?', $scope));
        $this->assertNull($tool->match('Combien de polices échues ?', $scope));
        $this->assertNull($tool->match('Combien de clients avons-nous ?', $scope));
    }
}
