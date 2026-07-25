<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\DocumentComptableTool;
use App\Comptabilite\CourtierEcritureComptableService;
use App\Comptabilite\CourtierSuiviFiscalService;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Service\Workspace\WorkspaceAccessResolver;
use PHPUnit\Framework\TestCase;

/**
 * Chaque état comptable restitué à Ket doit porter une « legende » (définition
 * métier) pour qu'il l'EXPLIQUE correctement sans inventer — en particulier que
 * le « chiffre d'affaires » du compte de résultat = commissions HT encaissées.
 */
class DocumentComptableToolTest extends TestCase
{
    private function makeTool(): DocumentComptableTool
    {
        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('canRead')->willReturn(true);

        $comptabilite = $this->createMock(CourtierEcritureComptableService::class);
        $comptabilite->method('exercicesDisponibles')->willReturn([2026]);
        $comptabilite->method('documents')->willReturn([
            'tft'      => ['soldeCloture' => 5.0],
            'resultat' => ['resultatNet' => 10.0],
            'bilan'    => [],
            'tfr'      => [],
            'balance'  => ['totaux' => [], 'lignes' => []],
            'journal'  => ['ecritures' => [], 'totalDebit' => 0.0, 'totalCredit' => 0.0],
        ]);

        $suiviFiscal = $this->createMock(CourtierSuiviFiscalService::class);
        $suiviFiscal->method('suivi')->willReturn([
            'assureur' => ['totaux' => [], 'lignes' => []],
            'courtier' => ['totaux' => [], 'lignes' => []],
        ]);

        return new DocumentComptableTool($resolver, $comptabilite, $suiviFiscal);
    }

    private function makeScope(): AiScope
    {
        return new AiScope(new Entreprise(), new Invite());
    }

    /**
     * @dataProvider documents
     */
    public function testChaqueEtatPorteUneLegendeNonVide(string $document): void
    {
        $result = $this->makeTool()->execute(['document' => $document], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertArrayHasKey('legende', $result->data);
        $this->assertNotSame('', trim($result->data['legende']));
    }

    public static function documents(): array
    {
        return [
            ['tresorerie'], ['resultat'], ['bilan'], ['formation_resultat'],
            ['balance'], ['journal'], ['suivi_fiscal'],
        ];
    }

    public function testLegendeResultatDefinitLeChiffreDAffaires(): void
    {
        $result = $this->makeTool()->execute(['document' => 'resultat'], $this->makeScope());

        $legende = mb_strtolower($result->data['legende']);
        $this->assertStringContainsString("chiffre d'affaires", $legende);
        $this->assertStringContainsString('encaiss', $legende);
    }
}
