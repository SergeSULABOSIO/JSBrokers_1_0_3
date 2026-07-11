<?php

namespace App\Tests\Ai;

use App\Ai\Guide\GuideRepository;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\ConsulterGuideTool;
use App\Entity\Entreprise;
use App\Entity\Invite;
use PHPUnit\Framework\TestCase;

/**
 * Fiches de connaissance (« skills ») : le dépôt scanne les fiches versionnées
 * (catalogue = titre + description, contenu à la demande) et l'outil
 * consulter_guide les sert — y compris l'inventaire des capacités déclenché
 * par « que peux-tu faire ? ». Tests purs, sur les vraies fiches du dépôt.
 */
class ConsulterGuideToolTest extends TestCase
{
    private GuideRepository $repository;
    private ConsulterGuideTool $tool;
    private AiScope $scope;

    protected function setUp(): void
    {
        $this->repository = new GuideRepository(\dirname(__DIR__, 2));
        $this->tool = new ConsulterGuideTool($this->repository);
        $this->scope = new AiScope(new Entreprise(), new Invite());
    }

    public function testCatalogueExposeLesFichesAvecTitreEtDescription(): void
    {
        $catalogue = $this->repository->catalogue();

        $this->assertNotEmpty($catalogue);
        foreach (['capacites-assistant', 'bordereau', 'cycle-production', 'indicateurs-client', 'perimetre-acces', 'recettes-assistant'] as $slug) {
            $this->assertArrayHasKey($slug, $catalogue, sprintf('La fiche « %s » doit être cataloguée.', $slug));
            $this->assertNotSame('', $catalogue[$slug]['titre']);
            $this->assertNotSame('', $catalogue[$slug]['description'], sprintf('La fiche « %s » doit avoir une description (> …).', $slug));
        }
        $this->assertSame($this->repository->slugs(), array_keys($catalogue));
    }

    public function testFicheRendLeContenuCompletOuNull(): void
    {
        $contenu = $this->repository->fiche('capacites-assistant');

        $this->assertNotNull($contenu);
        $this->assertStringContainsString('# Ce que l\'assistant peut faire', $contenu);
        $this->assertNull($this->repository->fiche('fiche-inexistante'));
    }

    public function testMatchQuestionDeCapacitesCibleLInventaire(): void
    {
        foreach (['Que peux-tu faire ?', 'Quelles sont tes capacités ?', 'À quoi sers-tu ?'] as $question) {
            $this->assertSame(
                ['sujet' => 'capacites-assistant'],
                $this->tool->match($question, $this->scope),
                sprintf('« %s » doit cibler la fiche des capacités.', $question),
            );
        }
    }

    public function testMatchQuestionDeMethodeCibleLaFicheParMotsCles(): void
    {
        $this->assertSame(
            ['sujet' => 'bordereau'],
            $this->tool->match('Comment fonctionnent les bordereaux ?', $this->scope),
        );
        $this->assertNull($this->tool->match('Bonjour, combien de clients avons-nous ?', $this->scope));
    }

    public function testExecuteServeLaFicheEtSignaleLInconnu(): void
    {
        $result = $this->tool->execute(['sujet' => 'cycle-production'], $this->scope);
        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertStringContainsString('Piste', $result->data['contenu']);
        $this->assertSame('Cycle de production du courtier', $result->data['titre']);

        $inconnu = $this->tool->execute(['sujet' => 'nimporte-quoi'], $this->scope);
        $this->assertSame(AiToolResult::STATUS_INTROUVABLE, $inconnu->status);
    }
}
