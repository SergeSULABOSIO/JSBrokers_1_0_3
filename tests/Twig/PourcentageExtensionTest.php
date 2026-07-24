<?php

namespace App\Tests\Twig;

use App\Twig\Extension\PourcentageExtension;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Le filtre |pourcentage formate selon la LANGUE ACTIVE (séparateurs + signe %),
 * pour unifier console et workspace sans séparateur codé en dur.
 */
class PourcentageExtensionTest extends KernelTestCase
{
    private function ext(): PourcentageExtension
    {
        self::bootKernel();

        return static::getContainer()->get(PourcentageExtension::class);
    }

    public function testFormatFrancais(): void
    {
        $rendu = $this->ext()->pourcentage(16, 'pourcent', 2, 'fr');
        $this->assertStringContainsString('16', $rendu);
        $this->assertStringContainsString(',', $rendu, 'Décimale FR = virgule.');
        $this->assertStringContainsString('%', $rendu);
    }

    public function testFormatAnglais(): void
    {
        $rendu = $this->ext()->pourcentage(16, 'pourcent', 2, 'en');
        $this->assertStringContainsString('16.00', $rendu, 'Décimale EN = point.');
        $this->assertStringContainsString('%', $rendu);
    }

    public function testDepuisFraction(): void
    {
        // 0.16 (fraction) → 16 % ; convention explicite.
        $this->assertStringContainsString('16', $this->ext()->pourcentage(0.16, 'fraction', 2, 'en'));
        $this->assertStringContainsString('16.00', $this->ext()->pourcentage(0.16, 'fraction', 2, 'en'));
    }

    public function testSuitLaLocaleActiveParDefaut(): void
    {
        self::bootKernel();
        $switcher = static::getContainer()->get(LocaleSwitcher::class);
        $ext = static::getContainer()->get(PourcentageExtension::class);

        $switcher->setLocale('en');
        $this->assertStringContainsString('.', $ext->pourcentage(16), 'Sans locale explicite, suit la langue active (en).');
        $switcher->setLocale('fr');
        $this->assertStringContainsString(',', $ext->pourcentage(16), 'Puis fr → virgule.');
    }
}
