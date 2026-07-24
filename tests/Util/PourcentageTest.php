<?php

namespace App\Tests\Util;

use App\Util\Pourcentage;
use PHPUnit\Framework\TestCase;

/**
 * Le VO Pourcentage est la source UNIQUE d'interprétation des pourcentages
 * (fraction ⇄ pourcent), pour supprimer les ×100 / ÷100 éparpillés.
 */
class PourcentageTest extends TestCase
{
    public function testDepuisFraction(): void
    {
        $p = Pourcentage::fromFraction(0.16);
        $this->assertEqualsWithDelta(0.16, $p->fraction(), 1e-9);
        $this->assertEqualsWithDelta(16.0, $p->pourcent(), 1e-9);
        $this->assertEqualsWithDelta(144.0, $p->appliquerA(900.0), 1e-9);
    }

    public function testDepuisPourcentEntier(): void
    {
        // Convention Taxe : « 16 » signifie 16 %.
        $p = Pourcentage::fromPourcent(16);
        $this->assertEqualsWithDelta(0.16, $p->fraction(), 1e-9);
        $this->assertEqualsWithDelta(16.0, $p->pourcent(), 1e-9);
        $this->assertEqualsWithDelta(144.0, $p->appliquerA(900.0), 1e-9);
    }

    public function testDepuisPourcentAccepteStringDecimalDoctrine(): void
    {
        // getTauxIARD() renvoie un string décimal ("16.00").
        $this->assertEqualsWithDelta(0.16, Pourcentage::fromPourcent('16.00')->fraction(), 1e-9);
    }

    public function testValeursNullesEtZero(): void
    {
        $this->assertTrue(Pourcentage::fromFraction(null)->estNul());
        $this->assertTrue(Pourcentage::fromPourcent(null)->estNul());
        $this->assertSame(0.0, Pourcentage::zero()->appliquerA(1000.0));
    }

    public function testFormatFrancais(): void
    {
        $this->assertSame('16,00 %', Pourcentage::fromPourcent(16)->format());
        $this->assertSame('10,0 %', Pourcentage::fromFraction(0.1)->format(1));
        $this->assertSame('2 %', Pourcentage::fromPourcent(2)->format(0));
    }

    public function testDeuxConventionsMemeResultat(): void
    {
        // 16 % exprimé dans les deux conventions => calcul identique.
        $viaFraction = Pourcentage::fromFraction(0.16)->appliquerA(2500.0);
        $viaEntier = Pourcentage::fromPourcent(16)->appliquerA(2500.0);
        $this->assertEqualsWithDelta($viaFraction, $viaEntier, 1e-9);
    }
}
