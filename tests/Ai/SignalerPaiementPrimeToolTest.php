<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\PreparerOperationsTool;
use App\Ai\Tool\SignalerPaiementPrimeTool;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Tranche;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\JSBDynamicSearchService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Outil « signaler_paiement_prime » : GARDES et routage (le chemin nominal — un plan
 * validable est réellement préparé — est couvert par SignalerPaiementPrimeToolIntegrationTest).
 *
 * Depuis la conversion en outil d'écriture, il DÉLÈGUE à preparer_operations (classe
 * finale, non mockable) : on récupère donc le vrai délégué + le vrai helper de calcul
 * du conteneur, et on ne mocke que le contrôle d'accès et la recherche. Les gardes
 * testées ici retournent AVANT toute délégation.
 */
class SignalerPaiementPrimeToolTest extends KernelTestCase
{
    protected function setUp(): void
    {
        static::bootKernel();
    }

    private function makeTool(bool $canWrite, ?JSBDynamicSearchService $search = null): SignalerPaiementPrimeTool
    {
        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('libellesEntites')->willReturn(['Tranche' => 'Tranches', 'PaiementPrime' => 'Paiements de prime']);
        $resolver->method('can')->willReturnCallback(
            static fn (Invite $invite, string $shortName, int $level) => $shortName === 'Tranche' && $canWrite,
        );

        return new SignalerPaiementPrimeTool(
            $resolver,
            $search ?? $this->createMock(JSBDynamicSearchService::class),
            static::getContainer()->get(PreparerOperationsTool::class),
            static::getContainer()->get(IndicatorCalculationHelper::class),
        );
    }

    private function makeScope(): AiScope
    {
        return new AiScope(new Entreprise(), new Invite());
    }

    private function searchRetournant(?Tranche $tranche): JSBDynamicSearchService
    {
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturn([
            'status' => ['error' => null, 'code' => 200, 'message' => 'OK'],
            'data' => $tranche ? [$tranche] : [],
            'totalItems' => $tranche ? 1 : 0,
            'currentPage' => 1,
            'totalPages' => 1,
            'itemsPerPage' => 1,
        ]);

        return $search;
    }

    public function testFailClosedSansEcritureTranche(): void
    {
        // Sans droit d'écriture sur la Tranche, l'outil refuse AVANT toute recherche
        // ou délégation : le signalement est gouverné par le droit Tranche.
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->expects($this->never())->method('search');

        $result = $this->makeTool(false, $search)->execute(['trancheId' => 71], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_HORS_PERIMETRE, $result->status);
        $this->assertNull($result->uiAction);
    }

    public function testTrancheHorsEntrepriseIntrouvable(): void
    {
        $result = $this->makeTool(true, $this->searchRetournant(null))
            ->execute(['trancheId' => 71], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_INTROUVABLE, $result->status);
        $this->assertNull($result->uiAction);
    }

    public function testMatchExtraitLaTranche(): void
    {
        $tool = $this->makeTool(true);
        $scope = $this->makeScope();

        $this->assertSame(
            ['trancheId' => 71],
            $tool->match('Signale le paiement de la prime associée à la tranche 71', $scope),
        );
        $this->assertSame(
            ['trancheId' => 12],
            $tool->match('Enregistre le paiement de prime sur la tranche n°12', $scope),
        );
        $this->assertSame(
            ['trancheId' => 5],
            $tool->match('Déclare que la prime de la tranche 5 a été payée', $scope),
        );

        // Sans id de tranche, le simulé ne peut rien résoudre (le LLM réel enchaîne
        // rechercher_entites) ; et les autres domaines ne déclenchent pas.
        $this->assertNull($tool->match('Signale le paiement de la prime', $scope));
        $this->assertNull($tool->match('Crée un paiement', $scope));
        $this->assertNull($tool->match('Liste les tranches', $scope));

        // Une formulation INTERROGATIVE est une lecture (paiements_prime), pas une saisie.
        $this->assertNull($tool->match('Quels paiements de prime ont été signalés sur la tranche 71 ?', $scope));
    }
}
