<?php

namespace App\Tests\Ai;

use App\Ai\Action\TypeAction;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\EtatConfigurationTool;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Service\Onboarding\OnboardingCompletude;
use PHPUnit\Framework\TestCase;

/**
 * `etat_configuration` : le détail derrière le rappel de la boussole.
 *
 * L'outil ne se contente pas de nommer la dette, il tend le bouton qui ouvre le guide —
 * dire à quelqu'un ce qui lui manque sans lui donner l'écran où le régler, c'est lui
 * laisser tout le travail de navigation. Tests purs : le service est mocké.
 */
class EtatConfigurationToolTest extends TestCase
{
    /**
     * @param array<int, array<string, mixed>> $etapes
     */
    private function outil(array $etapes, bool $complet): EtatConfigurationTool
    {
        $completude = $this->createMock(OnboardingCompletude::class);
        $completude->method('pour')->willReturn([
            'score' => $complet ? 100 : 45,
            'etapes' => $etapes,
            'restantes' => array_values(array_filter($etapes, static fn (array $e): bool => !$e['fait'])),
            'complet' => $complet,
        ]);

        return new EtatConfigurationTool($completude);
    }

    /** @return array<int, array<string, mixed>> */
    private function etapes(): array
    {
        return [
            ['cle' => 'assureurs', 'libelle' => 'Assureurs', 'bloc' => 'Production', 'poids' => 3, 'fait' => false, 'nombre' => 0],
            ['cle' => 'classeurs', 'libelle' => 'Classeurs', 'bloc' => 'Administration', 'poids' => 2, 'fait' => true, 'nombre' => 4],
            ['cle' => 'jours_feries', 'libelle' => 'Jours fériés', 'bloc' => 'Administration', 'poids' => 1, 'fait' => false, 'nombre' => 0],
        ];
    }

    private function scope(bool $proprietaire): AiScope
    {
        return new AiScope(new Entreprise(), (new Invite())->setProprietaire($proprietaire));
    }

    public function testRendLeScoreEtLeDetailDesEtapes(): void
    {
        $resultat = $this->outil($this->etapes(), false)->execute([], $this->scope(true));

        $this->assertSame(AiToolResult::STATUS_OK, $resultat->status);
        $this->assertSame(45, $resultat->data['score']);
        $this->assertFalse($resultat->data['complet']);
        $this->assertSame(2, $resultat->data['restantes']);
        $this->assertCount(3, $resultat->data['etapes']);

        // L'importance est TRADUITE pour le modèle : « bloquant » se comprend, « 3 » non.
        $this->assertSame('bloquant', $resultat->data['etapes'][0]['importance']);
        $this->assertSame('structurant', $resultat->data['etapes'][1]['importance']);
        $this->assertSame('confort', $resultat->data['etapes'][2]['importance']);
    }

    public function testProposeLeGuideQuandIlResteAFaire(): void
    {
        $resultat = $this->outil($this->etapes(), false)->execute([], $this->scope(true));

        $this->assertNotNull($resultat->uiAction);
        $this->assertSame(TypeAction::OUVRIR_RUBRIQUE->value, $resultat->uiAction['type']);
        $this->assertSame('Onboarding', $resultat->uiAction['entite']);
    }

    public function testNeProposeRienQuandToutEstConfigure(): void
    {
        $etapes = array_map(static function (array $e): array {
            $e['fait'] = true;

            return $e;
        }, $this->etapes());

        $resultat = $this->outil($etapes, true)->execute([], $this->scope(true));

        // Proposer d'ouvrir un guide vide ferait douter l'utilisateur de ce qu'on vient
        // de lui annoncer.
        $this->assertNull($resultat->uiAction);
        $this->assertTrue($resultat->data['complet']);
    }

    public function testSeulementRestantesFiltreCeQuiEstFait(): void
    {
        $resultat = $this->outil($this->etapes(), false)
            ->execute(['seulement_restantes' => true], $this->scope(true));

        $this->assertCount(2, $resultat->data['etapes']);
        foreach ($resultat->data['etapes'] as $etape) {
            $this->assertFalse($etape['fait']);
        }
    }

    /**
     * Fail-closed par la PROPRIÉTÉ et non par un droit de lecture : configurer le
     * cabinet n'est pas une entité dont on lit les lignes.
     */
    public function testRefuseAUnInvite(): void
    {
        $resultat = $this->outil($this->etapes(), false)->execute([], $this->scope(false));

        $this->assertSame(AiToolResult::STATUS_HORS_PERIMETRE, $resultat->status);
    }

    public function testMatchReconnaitLesFormulationsDuCourtier(): void
    {
        $outil = $this->outil($this->etapes(), false);
        $scope = $this->scope(true);

        $this->assertNotNull($outil->match('où en est la configuration du cabinet ?', $scope));
        $this->assertNotNull($outil->match("qu'est-ce qu'il me reste à configurer", $scope));
        // Et ne se déclenche pas sur ce qui ne le concerne pas.
        $this->assertNull($outil->match('combien de clients ai-je ?', $scope));
    }
}
