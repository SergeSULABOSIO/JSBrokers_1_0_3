<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\VigieEcheancesTool;
use App\Entity\Avenant;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Tache;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\DashboardDataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Vigie des échéances : gating fail-closed PAR VOLET (un volet hors périmètre
 * est omis avec mention, jamais d'échec global tant qu'un volet reste lisible),
 * clamp de l'horizon, plafond de lignes, déclencheurs simulés. Tests purs :
 * résolveur d'accès et provider de tableau de bord doublés en mémoire.
 */
class VigieEcheancesToolTest extends TestCase
{
    private const LIBELLES = [
        'Avenant'              => 'Avenants',
        'Tache'                => 'Tâches',
        'Piste'                => 'Pistes',
        'NotificationSinistre' => 'Sinistres',
    ];

    private function makeTool(array $canRead, ?DashboardDataProvider $dashboard = null): VigieEcheancesTool
    {
        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('libellesEntites')->willReturn(self::LIBELLES);
        $resolver->method('canRead')->willReturnCallback(
            static fn (Invite $invite, string $shortName) => $canRead[$shortName] ?? false,
        );

        return new VigieEcheancesTool(
            $resolver,
            $dashboard ?? $this->createMock(DashboardDataProvider::class),
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
        $this->assertSame(['renouvellements', 'taches', 'pistes', 'sinistres'], array_keys($result->data['volets']));
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
            ['Avenant' => true, 'Tache' => true, 'Piste' => true, 'NotificationSinistre' => false],
            $dashboard,
        );
        $result = $tool->execute(['volet' => 'tout'], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertArrayNotHasKey('sinistres', $result->data['volets']);
        $this->assertSame(['Sinistres'], $result->data['horsPerimetre']);
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
