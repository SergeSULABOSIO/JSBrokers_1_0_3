<?php

namespace App\Tests\Ai;

use App\Ai\Engine\SimulatedAiEngine;
use PHPUnit\Framework\TestCase;

/**
 * Le moteur simulé émet un graphique (bloc ```chart) dans les analyses
 * mensuelles / ventilées, décodé côté front par assistant-chart-render.js.
 * On invoque le formateur privé par réflexion (aucune dépendance, aucune base) :
 * ce qui compte est la CHAÎNE produite, pas le circuit d'outils.
 */
class SimulatedAiEngineChartTest extends TestCase
{
    /** Extrait et décode le premier bloc ```chart d'une réponse, ou échoue. */
    private function extraireSpecChart(string $texte): array
    {
        $this->assertMatchesRegularExpression('/```chart\n.*\n```/s', $texte, 'Aucun bloc ```chart trouvé.');
        preg_match('/```chart\n(.*?)\n```/s', $texte, $m);
        $spec = json_decode($m[1], true);
        $this->assertIsArray($spec, 'La spec du graphique doit être un JSON valide.');

        return $spec;
    }

    private function formater(array $data): string
    {
        $engine = (new \ReflectionClass(SimulatedAiEngine::class))->newInstanceWithoutConstructor();
        $methode = new \ReflectionMethod($engine, 'formatAnalysePortefeuille');

        return $methode->invoke($engine, $data);
    }

    public function testProductionMensuelleEmetUnHistogramme(): void
    {
        $texte = $this->formater([
            'analyse' => 'production_mensuelle',
            'annee'   => 2026,
            'total'   => 3000.0,
            'mois'    => [1 => 1000.0, 2 => 0.0, 3 => 2000.0],
        ]);

        $spec = $this->extraireSpecChart($texte);
        $this->assertSame('bar', $spec['type']);
        $this->assertCount(3, $spec['labels']);
        $this->assertSame('Jan', $spec['labels'][0]);
        // JSON n'a qu'un type numérique : 1000.0 est décodé en 1000 → comparaison lâche.
        $this->assertEquals([1000, 0, 2000], $spec['series'][0]['data']);
        $this->assertNotEmpty($spec['legende'], 'La légende explicative est obligatoire.');
    }

    public function testChiffreAffairesMensuelEmetDeuxSeriesHtEtTtc(): void
    {
        $texte = $this->formater([
            'analyse'                 => 'chiffre_affaires_mensuel',
            'annee'                   => 2026,
            'totalHt'                 => 900.0,
            'totalTtc'                => 1050.0,
            'commissionEncaisseeHt'   => [3 => 900.0],
            'commissionEncaisseeTtc'  => [3 => 1050.0],
        ]);

        $spec = $this->extraireSpecChart($texte);
        $this->assertSame('bar', $spec['type']);
        $this->assertCount(12, $spec['labels']); // année complète
        $this->assertCount(2, $spec['series']);
        $this->assertSame('HT', $spec['series'][0]['label']);
        $this->assertSame('TTC', $spec['series'][1]['label']);
        $this->assertEquals(900, $spec['series'][0]['data'][2]); // mois 3 (index 2)
        $this->assertNotEmpty($spec['legende']);
    }

    public function testChiffreAffairesVentileEmetUnGraphiqueParDimension(): void
    {
        $texte = $this->formater([
            'analyse'   => 'chiffre_affaires',
            'annee'     => 2026,
            'dimension' => 'assureur',
            'totalHt'   => 300.0,
            'totalTtc'  => 350.0,
            'lignes'    => [
                ['libelle' => 'Assureur A', 'caHt' => 200.0, 'caTtc' => 230.0],
                ['libelle' => 'Assureur B', 'caHt' => 100.0, 'caTtc' => 120.0],
            ],
        ]);

        $spec = $this->extraireSpecChart($texte);
        $this->assertSame('bar', $spec['type']);
        $this->assertSame(['Assureur A', 'Assureur B'], $spec['labels']);
        $this->assertCount(2, $spec['series']);
    }
}
