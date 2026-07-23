<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\InventaireChampsTool;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Service\Workspace\WorkspaceMutationService;
use App\Services\JSBDynamicSearchService;
use PHPUnit\Framework\TestCase;

/**
 * Outil « inventaire_champs » : expose les champs (obligatoires / facultatifs /
 * auto) d'une entité mutable. Fail-closed (allowlist + droit), n'écrit rien,
 * délègue le classement à WorkspaceMutationService. Tests purs (mocks).
 */
class InventaireChampsToolTest extends TestCase
{
    private function makeTool(bool $canWrite, ?WorkspaceMutationService $mutation = null): InventaireChampsTool
    {
        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('libellesEntites')->willReturn(['Client' => 'Clients']);
        $resolver->method('can')->willReturn($canWrite);

        return new InventaireChampsTool(
            $mutation ?? $this->createMock(WorkspaceMutationService::class),
            $resolver,
            $this->createMock(JSBDynamicSearchService::class),
        );
    }

    private function makeScope(): AiScope
    {
        return new AiScope(new Entreprise(), new Invite());
    }

    public function testFailClosedSansDroit(): void
    {
        $mutation = $this->createMock(WorkspaceMutationService::class);
        $mutation->expects($this->never())->method('inventaireChamps');

        $result = $this->makeTool(false, $mutation)->execute(['entite' => 'Client', 'mode' => 'creation'], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_HORS_PERIMETRE, $result->status);
        $this->assertNull($result->uiAction);
    }

    public function testEntiteHorsAllowlistIntrouvable(): void
    {
        $mutation = $this->createMock(WorkspaceMutationService::class);
        $mutation->expects($this->never())->method('inventaireChamps');

        // RolesEnProduction = gestion des rôles, hors allowlist de mutation.
        $result = $this->makeTool(true, $mutation)->execute(['entite' => 'RolesEnProduction', 'mode' => 'creation'], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_INTROUVABLE, $result->status);
    }

    public function testCreationRetourneLesTroisGroupes(): void
    {
        $mutation = $this->createMock(WorkspaceMutationService::class);
        $mutation->method('inventaireChamps')->willReturn([
            'entite' => 'Client', 'libelle' => 'Clients', 'mode' => 'creation',
            'obligatoires' => [['champ' => 'nom', 'libelle' => 'Nom'], ['champ' => 'exonere', 'libelle' => 'Exonéré de taxes ?']],
            'facultatifs' => [['champ' => 'telephone', 'libelle' => 'Téléphone']],
            'auto' => [['champ' => 'entreprise', 'libelle' => 'Entreprise', 'valeur' => 'ACME']],
        ]);

        $result = $this->makeTool(true, $mutation)->execute(['entite' => 'Client', 'mode' => 'creation'], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame('creation', $result->data['mode']);
        $this->assertSame(['nom', 'exonere'], array_column($result->data['obligatoires'], 'champ'));
        $this->assertSame(['telephone'], array_column($result->data['facultatifs'], 'champ'));
        $this->assertSame(['entreprise'], array_column($result->data['auto'], 'champ'));
        $this->assertArrayHasKey('note', $result->data);
        $this->assertNull($result->uiAction, 'inventaire_champs n’émet aucune action UI (lecture seule).');
    }
}
