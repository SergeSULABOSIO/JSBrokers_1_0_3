<?php

namespace App\Tests\Services;

use App\Entity\Tranche;
use App\Services\Canvas\Provider\Numeric\TrancheNumericCanvasProvider;
use PHPUnit\Framework\TestCase;

/**
 * Barre des totaux (liste Tranches) : le trait générique partagé
 * (CalculatedIndicatorsNumericProviderTrait) attend une nomenclature
 * primeTotale/montantTTC/montantHT/commissionPartageable/primeNette/tauxCommission,
 * héritée de Cotation/Avenant/Client/Piste. TrancheIndicatorStrategy calcule sous
 * des noms propres (primeTranche, montantCalculeHT/TTC…) : sans complément, la
 * barre des totaux perdait silencieusement « Prime Tranche » et n'a JAMAIS eu
 * « Commission Exigible »/« Rétro Exigible » (indicateurs sans équivalent
 * générique, pourtant les deux têtes de proue du suivi des impayés).
 */
class TrancheNumericCanvasProviderTest extends TestCase
{
    public function testCanvasExposesTrancheSpecificAndSharedIndicators(): void
    {
        $tranche = new Tranche();
        // Valeurs telles que les poserait TrancheIndicatorStrategy::calculate().
        $tranche->primeTranche = 1000.0;
        $tranche->primePayee = 400.0;
        $tranche->primeSoldeDue = 600.0;
        $tranche->montantCalculeHT = 200.0;
        $tranche->montantCalculeTTC = 232.0;
        $tranche->montant_du = 232.0;
        $tranche->montant_paye = 100.0;
        $tranche->solde_restant_du = 132.0;
        $tranche->montantPur = 180.0;
        $tranche->reserve = 150.0;
        $tranche->retroCommission = 30.0;
        $tranche->retroCommissionReversee = 10.0;
        $tranche->retroCommissionSolde = 20.0;
        $tranche->taxeCourtierMontant = 20.0;
        $tranche->taxeCourtierPayee = 5.0;
        $tranche->taxeCourtierSolde = 15.0;
        $tranche->taxeAssureurMontant = 12.0;
        $tranche->taxeAssureurPayee = 3.0;
        $tranche->taxeAssureurSolde = 9.0;
        $tranche->commissionExigible = 132.0;
        $tranche->retroCommissionExigible = 20.0;
        $tranche->primeDeclareePayee = 0.0;
        $tranche->resteAPayer = 600.0;

        $canvas = (new TrancheNumericCanvasProvider())->getCanvas($tranche);

        // Indicateurs propres à Tranche, désormais exposés (valeurs en centimes).
        $this->assertSame(100000.0, $canvas['primeTranche']['value'], 'Prime Tranche = 1000 x 100.');
        $this->assertSame('Prime Tranche', $canvas['primeTranche']['description']);
        $this->assertSame(20000.0, $canvas['montantCalculeHT']['value']);
        $this->assertSame(23200.0, $canvas['montantCalculeTTC']['value']);
        $this->assertSame(23200.0, $canvas['montant_du']['value']);
        $this->assertSame(13200.0, $canvas['commissionExigible']['value'], 'Commission exigible : indicateur clé du suivi des impayés — doit être totalisable.');
        $this->assertSame(2000.0, $canvas['retroCommissionExigible']['value'], 'Rétro exigible : idem, indicateur clé.');
        $this->assertSame(0.0, $canvas['primeDeclareePayee']['value']);
        $this->assertSame(60000.0, $canvas['resteAPayer']['value']);

        // Indicateurs déjà correctement nommés par le trait générique (property_exists
        // match) : toujours exposés, sans doublon ni perte après le complément.
        $this->assertSame(40000.0, $canvas['primePayee']['value']);
        $this->assertSame(60000.0, $canvas['primeSoldeDue']['value']);
        $this->assertSame(10000.0, $canvas['montant_paye']['value']);
        $this->assertSame(13200.0, $canvas['solde_restant_du']['value']);
        $this->assertSame(18000.0, $canvas['montantPur']['value']);
        $this->assertSame(15000.0, $canvas['reserve']['value']);
        $this->assertSame(3000.0, $canvas['retroCommission']['value']);
        $this->assertSame(1000.0, $canvas['retroCommissionReversee']['value']);
        $this->assertSame(2000.0, $canvas['retroCommissionSolde']['value']);
        $this->assertSame(2000.0, $canvas['taxeCourtierMontant']['value']);
        $this->assertSame(500.0, $canvas['taxeCourtierPayee']['value']);
        $this->assertSame(1500.0, $canvas['taxeCourtierSolde']['value']);
        $this->assertSame(1200.0, $canvas['taxeAssureurMontant']['value']);
        $this->assertSame(300.0, $canvas['taxeAssureurPayee']['value']);
        $this->assertSame(900.0, $canvas['taxeAssureurSolde']['value']);

        // Clés génériques SANS équivalent sur Tranche : absentes (pas de faux zéro
        // trompeur dans le sélecteur de la barre des totaux).
        $this->assertArrayNotHasKey('primeTotale', $canvas);
        $this->assertArrayNotHasKey('montantTTC', $canvas);
        $this->assertArrayNotHasKey('montantHT', $canvas);
        $this->assertArrayNotHasKey('commissionPartageable', $canvas);
        $this->assertArrayNotHasKey('primeNette', $canvas);
        $this->assertArrayNotHasKey('tauxCommission', $canvas);
    }

    public function testMissingValuesDefaultToZeroNotError(): void
    {
        $canvas = (new TrancheNumericCanvasProvider())->getCanvas(new Tranche());

        $this->assertEquals(0, $canvas['primeTranche']['value']);
        $this->assertEquals(0, $canvas['commissionExigible']['value']);
        $this->assertEquals(0, $canvas['retroCommissionExigible']['value']);
    }
}
