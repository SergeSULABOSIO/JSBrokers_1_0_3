<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\AnalysePortefeuilleTool;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\DashboardDataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Analyses agrégées du portefeuille : gating fail-closed par analyse, omission
 * de la sinistralité hors périmètre sinistres, clamp du top, production par
 * année, déclencheurs simulés sans collision avec compter/rechercher.
 */
class AnalysePortefeuilleToolTest extends TestCase
{
    private const LIBELLES = [
        'Avenant'              => 'Avenants',
        'Assureur'             => 'Assureurs',
        'Client'               => 'Clients',
        'Risque'               => 'Risques',
        'Partenaire'           => 'Partenaires',
        'Paiement'             => 'Paiements',
        'NotificationSinistre' => 'Sinistres',
    ];

    private const TOP_ROW = [
        'id' => 7, 'nom' => 'Assureur Alpha', 'nbPolices' => 12,
        'primesTotales' => 1000.456, 'commissionsTtc' => 150.0,
        'sinistresIndemnises' => 200.0, 'ratioSP' => 20.0, 'partMarche' => 45.5,
    ];

    private function makeTool(array $canRead, ?DashboardDataProvider $dashboard = null): AnalysePortefeuilleTool
    {
        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('libellesEntites')->willReturn(self::LIBELLES);
        $resolver->method('canRead')->willReturnCallback(
            static fn (Invite $invite, string $shortName) => $canRead[$shortName] ?? false,
        );

        return new AnalysePortefeuilleTool(
            $resolver,
            $dashboard ?? $this->createMock(DashboardDataProvider::class),
        );
    }

    private function makeScope(): AiScope
    {
        return new AiScope(new Entreprise(), new Invite());
    }

    public function testTopAssureursRestitueLeClassementCompact(): void
    {
        $dashboard = $this->createMock(DashboardDataProvider::class);
        $dashboard->method('getTopAssureursAvecIndicateurs')->willReturn([self::TOP_ROW]);

        $tool = $this->makeTool(['Avenant' => true, 'Assureur' => true, 'NotificationSinistre' => true], $dashboard);
        $result = $tool->execute(['analyse' => 'top_assureurs'], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $ligne = $result->data['lignes'][0];
        $this->assertSame('Assureur Alpha', $ligne['nom']);
        $this->assertSame(1000.46, $ligne['primesTotales']);
        $this->assertSame(20.0, $ligne['ratioSP']);
        $this->assertArrayNotHasKey('id', $ligne);
        $this->assertArrayNotHasKey('note', $result->data);
    }

    public function testSinistraliteOmiseHorsPerimetreSinistres(): void
    {
        $dashboard = $this->createMock(DashboardDataProvider::class);
        $dashboard->method('getTopAssureursAvecIndicateurs')->willReturn([self::TOP_ROW]);

        $tool = $this->makeTool(
            ['Avenant' => true, 'Assureur' => true, 'NotificationSinistre' => false],
            $dashboard,
        );
        $result = $tool->execute(['analyse' => 'top_assureurs'], $this->makeScope());

        $ligne = $result->data['lignes'][0];
        $this->assertArrayNotHasKey('ratioSP', $ligne);
        $this->assertArrayNotHasKey('sinistresIndemnises', $ligne);
        $this->assertStringContainsString('Sinistralité omise', $result->data['note']);
    }

    public function testGatingParAnalyse(): void
    {
        $tool = $this->makeTool(['Avenant' => true, 'Assureur' => false]);
        $result = $tool->execute(['analyse' => 'top_assureurs'], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_HORS_PERIMETRE, $result->status);
        $this->assertSame('Assureurs', $result->data['libelle']);
    }

    public function testLimiteClampee(): void
    {
        $rows = array_map(
            static fn (int $i) => ['nom' => 'A' . $i, 'nbPolices' => 1,
                'primesTotales' => 10.0, 'commissionsTtc' => 1.0, 'partMarche' => 1.0] + self::TOP_ROW,
            range(1, 10),
        );
        $dashboard = $this->createMock(DashboardDataProvider::class);
        $dashboard->method('getTopAssuresAvecIndicateurs')->willReturn($rows);

        $tool = $this->makeTool(['Avenant' => true, 'Client' => true, 'NotificationSinistre' => true], $dashboard);
        $result = $tool->execute(['analyse' => 'top_clients', 'limite' => 50], $this->makeScope());

        $this->assertCount(9, $result->data['lignes']);
    }

    public function testProductionMensuelleAnneeCourante(): void
    {
        $mensuel = array_fill(1, 12, 0.0);
        $mensuel[3] = 500.0;
        $dashboard = $this->createMock(DashboardDataProvider::class);
        $dashboard->method('getProductionMensuelle')->willReturn($mensuel);
        $dashboard->expects($this->never())->method('getProductionParMois');

        $tool = $this->makeTool(['Paiement' => true], $dashboard);
        $result = $tool->execute(['analyse' => 'production_mensuelle'], $this->makeScope());

        $this->assertSame((int) date('Y'), $result->data['annee']);
        $this->assertSame(500.0, $result->data['mois'][3]);
        $this->assertSame(500.0, $result->data['total']);
    }

    public function testProductionMensuelleAnneeExplicite(): void
    {
        $dashboard = $this->createMock(DashboardDataProvider::class);
        $dashboard->expects($this->once())
            ->method('getProductionParMois')
            ->with(
                $this->anything(),
                new \DateTimeImmutable('2025-01-01 00:00:00'),
                new \DateTimeImmutable('2025-12-31 23:59:59'),
            )
            ->willReturn(['labels' => [], 'data' => array_fill(0, 12, 1.0)]);

        $tool = $this->makeTool(['Paiement' => true], $dashboard);
        $result = $tool->execute(['analyse' => 'production_mensuelle', 'annee' => 2025], $this->makeScope());

        $this->assertSame(2025, $result->data['annee']);
        $this->assertSame(12.0, $result->data['total']);
        $this->assertSame(1.0, $result->data['mois'][1]);
    }

    public function testAnalyseInconnueIntrouvable(): void
    {
        $tool = $this->makeTool([]);
        $result = $tool->execute(['analyse' => 'nimporte'], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_INTROUVABLE, $result->status);
    }

    public function testMatchDeclencheursEtNonCollisions(): void
    {
        $tool = $this->makeTool([]);
        $scope = $this->makeScope();

        $this->assertSame(
            ['analyse' => 'top_assureurs', 'limite' => 5],
            $tool->match('Donne-moi le top 5 des assureurs', $scope),
        );
        $this->assertSame(['analyse' => 'top_clients'], $tool->match('Quels sont nos meilleurs clients ?', $scope));
        $this->assertSame(
            ['analyse' => 'production_mensuelle', 'annee' => 2026],
            $tool->match('Production mensuelle 2026', $scope),
        );
        $this->assertSame(['analyse' => 'encaissements'], $tool->match('Quels sont les derniers encaissements ?', $scope));

        // Domaine d'autres outils : jamais de collision avec compter/rechercher.
        $this->assertNull($tool->match('Combien d\'assureurs avons-nous ?', $scope));
        $this->assertNull($tool->match('Liste les clients', $scope));
        $this->assertNull($tool->match('Liste les encaissements', $scope));
    }
}
