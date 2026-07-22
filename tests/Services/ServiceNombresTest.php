<?php

namespace App\Tests\Services;

use App\Services\ServiceNombres;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Règle d'écriture des nombres : anglais « 8,800 », français « 8 800 ».
 * Le miroir navigateur (assets/number-format.js) doit produire les mêmes chaînes.
 */
class ServiceNombresTest extends TestCase
{
    /** @param string|null $langueActive Langue portée par le LocaleSwitcher (défaut applicatif : fr). */
    private function service(?string $langueActive = null): ServiceNombres
    {
        return new ServiceNombres(new LocaleSwitcher($langueActive ?? 'fr', []));
    }

    public function testNotationAnglaise(): void
    {
        $svc = $this->service('en');

        $this->assertSame('8,800', $svc->format(8800));
        $this->assertSame('1,234,567', $svc->format(1234567));
        $this->assertSame('800', $svc->format(800));
        $this->assertSame('1,234.56', $svc->format(1234.5649, 2));
    }

    public function testNotationFrancaise(): void
    {
        $svc = $this->service('fr');

        $this->assertSame('8 800', $svc->format(8800));
        $this->assertSame('1 234 567', $svc->format(1234567));
        $this->assertSame('800', $svc->format(800));
        $this->assertSame('1 234,56', $svc->format(1234.5649, 2));
    }

    /** La langue passée en argument prime (e-mails et vitrine rendus hors requête). */
    public function testLocaleExpliciteEmporteSurLaRequete(): void
    {
        $svc = $this->service('fr');

        $this->assertSame('8,800', $svc->format(8800, 0, 'en'));
        $this->assertSame('8 800', $svc->format(8800, 0, 'fr'));
    }

    /** Variantes régionales : seule la langue compte (en_GB, fr_BE…). */
    public function testVariantesRegionales(): void
    {
        $svc = $this->service();

        $this->assertSame('8,800', $svc->format(8800, 0, 'en_GB'));
        $this->assertSame('8 800', $svc->format(8800, 0, 'fr_BE'));
    }

    /** Hors bascule de langue (CLI, worker) : notation française par défaut. */
    public function testNotationFrancaiseParDefaut(): void
    {
        $this->assertSame('8 800', $this->service()->format(8800));
    }

    public function testValeursNonNumeriques(): void
    {
        $svc = $this->service('fr');

        $this->assertSame('', $svc->format(null));
        $this->assertSame('', $svc->format(''));
        $this->assertSame('0', $svc->format(0));
    }
}
