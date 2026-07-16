<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\SignalerPaiementPrimeTool;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Tranche;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use PHPUnit\Framework\TestCase;

/**
 * Outil « signaler_paiement_prime » : ouvre le formulaire PaiementPrime (déclaratif,
 * rattaché à la tranche, prérempli côté serveur) — PAS le formulaire Paiement
 * (trésorerie). Fail-closed sur l'Écriture Tranche, tranche résolue strictement
 * dans l'entreprise du scope, uiAction dédiée. Tests purs.
 */
class SignalerPaiementPrimeToolTest extends TestCase
{
    private function makeTool(bool $canWrite, ?JSBDynamicSearchService $search = null): SignalerPaiementPrimeTool
    {
        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('libellesEntites')->willReturn(['Tranche' => 'Tranches']);
        $resolver->method('can')->willReturnCallback(
            static fn (Invite $invite, string $shortName, int $level) => $shortName === 'Tranche' && $canWrite,
        );

        return new SignalerPaiementPrimeTool(
            $resolver,
            $search ?? $this->createMock(JSBDynamicSearchService::class),
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

    public function testOuvreLeFormulairePreremplViaUiActionDediee(): void
    {
        $tranche = (new Tranche())
            ->setNom('Tranche unique')
            ->setPayableAt(new \DateTimeImmutable('-30 days'));

        $result = $this->makeTool(true, $this->searchRetournant($tranche))
            ->execute(['trancheId' => 71], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame('Tranche unique', $result->data['tranche']);
        $this->assertStringContainsString('prérempli', $result->data['note']);
        $this->assertSame(
            ['type' => 'signaler-paiement-prime', 'trancheId' => 71],
            $result->uiAction,
            'La uiAction dédiée rejoue l\'action de liste (dialogue PaiementPrime prérempli), pas open-dialog/Paiement.'
        );
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
    }
}
