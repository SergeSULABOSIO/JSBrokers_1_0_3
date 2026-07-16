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
use App\Services\Tranche\TranchePaiementService;
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
        $this->assertEqualsWithDelta(10, $result->data['volets']['renouvellements']['lignes'][0]['joursRestants'], 1);
        $this->assertNull($result->uiAction);
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

    public function testVoletImpayesRestitueSoldesEtTotaux(): void
    {
        $tranche = (new Tranche())
            ->setNom('Tranche 1')
            ->setPayableAt(new \DateTimeImmutable('-40 days'))
            ->setEcheanceAt(new \DateTimeImmutable('-10 days'));
        $tranche->clientNom = 'Client Alpha';
        $tranche->statutPaiement = 'Non payée';
        $tranche->urgenceRecouvrement = 'Critique · retard 10 j';
        $tranche->primeSoldeDue = 800.0;
        $tranche->solde_restant_du = 120.0;

        $tranchePaiement = $this->createMock(TranchePaiementService::class);
        $tranchePaiement->method('lister')->willReturn([
            'items' => [$tranche],
            'totaux' => ['nb' => 1, 'totalPrime' => 1000.0, 'totalSoldePrime' => 800.0, 'totalSoldeCommission' => 120.0],
            'totalItems' => 1,
            'currentPage' => 1,
            'totalPages' => 1,
        ]);

        $tool = $this->makeTool(['Tranche' => true], null, $tranchePaiement);
        $result = $tool->execute(['volet' => 'impayes'], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $volet = $result->data['volets']['impayes'];
        $ligne = $volet['lignes'][0];
        $this->assertSame('Client Alpha', $ligne['client']);
        $this->assertSame(10, $ligne['joursRetard']);
        $this->assertSame(800.0, $ligne['soldePrime']);
        $this->assertSame(120.0, $ligne['soldeCommission']);
        $this->assertSame('Critique · retard 10 j', $ligne['urgence']);
        $this->assertSame(800.0, $volet['totaux']['totalSoldePrime']);
        $this->assertFalse($volet['tronque']);
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

        // Domaine d'autres outils : liste => rechercher_entites, combien => compter_entites.
        $this->assertNull($tool->match('Liste les tâches', $scope));
        $this->assertNull($tool->match('Combien de renouvellements ?', $scope));
        $this->assertNull($tool->match('Combien de clients avons-nous ?', $scope));
    }
}
