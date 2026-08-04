<?php

namespace App\Tests\Services;

use App\Services\Search\AvenantEcheanceScope;
use PHPUnit\Framework\TestCase;

/**
 * L'ARITHMÉTIQUE DES FENÊTRES D'ÉCHÉANCE, éprouvée seule.
 *
 * Ce scope est la source unique des bornes pour les chips de la rubrique Avenants, la
 * boussole, le programme du jour ET la vigie de l'assistant. La vigie accepte un horizon
 * LIBRE que les quatre statuts figés ne savent pas exprimer : elle passe donc par
 * bornesHorizon(). Ce test verrouille l'IDENTITÉ entre les deux expressions — sans elle,
 * la vigie a refabriqué ses propres bornes et annoncé « plus aucune police échue » quand
 * la rubrique en affichait cinq.
 */
class AvenantEcheanceScopeTest extends TestCase
{
    private const REF = '2026-08-04 17:42:11';

    private function ref(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::REF);
    }

    /** LE VERROU : « sous 30 jours » ne doit avoir qu'une seule définition. */
    public function testHorizon30SansEchuesEstExactementLeChipSous30Jours(): void
    {
        $this->assertEquals(
            AvenantEcheanceScope::bornes(AvenantEcheanceScope::STATUT_30J, $this->ref()),
            AvenantEcheanceScope::bornesHorizon(30, $this->ref(), false),
        );
    }

    /** Les bornes sont ramenées à MINUIT : l'heure de la requête ne décale rien. */
    public function testLesBornesSontRameneesAMinuit(): void
    {
        $bornes = AvenantEcheanceScope::bornesHorizon(30, $this->ref(), false);

        $this->assertSame('2026-08-04 00:00:00', $bornes['min']->format('Y-m-d H:i:s'));
        // Borne haute EXCLUSIVE : « sous 30 jours » contient le 30ᵉ jour entier, donc
        // s'arrête au début du 31ᵉ.
        $this->assertSame('2026-09-04 00:00:00', $bornes['max']->format('Y-m-d H:i:s'));
    }

    /**
     * Une fenêtre qui inclut les échues n'a PAS de plancher : une police expirée il y a
     * dix ans réclame toujours une action, exactement comme dans le chip « Échus ».
     */
    public function testInclureLesEchuesOuvreLaBorneBasse(): void
    {
        $bornes = AvenantEcheanceScope::bornesHorizon(30, $this->ref());

        $this->assertNull($bornes['min']);
        $this->assertSame('2026-09-04 00:00:00', $bornes['max']->format('Y-m-d H:i:s'));
    }

    /**
     * La borne haute de la fenêtre « échues + N jours » couvre les DEUX chips qu'elle
     * remplace : elle doit coïncider avec celle du chip « Sous 30 jours ».
     */
    public function testFenetreEchuesPlus30CouvreLesDeuxChipsSansTrou(): void
    {
        $echus = AvenantEcheanceScope::bornes(AvenantEcheanceScope::STATUT_ECHUS, $this->ref());
        $sous30 = AvenantEcheanceScope::bornes(AvenantEcheanceScope::STATUT_30J, $this->ref());
        $fenetre = AvenantEcheanceScope::bornesHorizon(30, $this->ref());

        // Pas de trou : la fin des échues est le début de « sous 30 jours ».
        $this->assertEquals($echus['max'], $sous30['min']);
        $this->assertNull($fenetre['min']);
        $this->assertEquals($sous30['max'], $fenetre['max']);
    }

    /** Un horizon d'un seul jour couvre aujourd'hui, et rien de plus. */
    public function testHorizonDUnJourNeCouvreQueAujourdhui(): void
    {
        $bornes = AvenantEcheanceScope::bornesHorizon(1, $this->ref(), false);

        $this->assertSame('2026-08-04 00:00:00', $bornes['min']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-06 00:00:00', $bornes['max']->format('Y-m-d H:i:s'));
    }

    /** Les deux fenêtres lointaines restent inchangées et contiguës. */
    public function testLesFenetresLointainesRestentContigues(): void
    {
        $sous30 = AvenantEcheanceScope::bornes(AvenantEcheanceScope::STATUT_30J, $this->ref());
        $de31a60 = AvenantEcheanceScope::bornes(AvenantEcheanceScope::STATUT_31_60J, $this->ref());
        $auDela = AvenantEcheanceScope::bornes(AvenantEcheanceScope::STATUT_60_PLUS, $this->ref());

        $this->assertEquals($sous30['max'], $de31a60['min']);
        $this->assertEquals($de31a60['max'], $auDela['min']);
        $this->assertNull($auDela['max']);
    }
}
