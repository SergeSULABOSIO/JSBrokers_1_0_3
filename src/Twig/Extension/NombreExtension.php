<?php

namespace App\Twig\Extension;

use App\Services\ServiceNombres;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Expose le formatage localisé des nombres aux gabarits : `{{ 8800|format_nombre }}`
 * rend « 8 800 » en français et « 8,800 » en anglais ; `{{ 10|format_montant }}`
 * rend « 10,00 $ » et « $10.00 » (le symbole change de côté avec la langue).
 * Les e-mails et la vitrine, qui rendent dans une langue imposée, passent
 * celle-ci après les décimales : `{{ pack.tokens|format_nombre(0, lang) }}`.
 */
class NombreExtension extends AbstractExtension
{
    public function __construct(private ServiceNombres $serviceNombres) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('format_nombre', [$this, 'formatNombre']),
            new TwigFilter('format_montant', [$this, 'formatMontant']),
        ];
    }

    public function formatNombre(int|float|string|null $valeur, int $decimales = 0, ?string $locale = null): string
    {
        return $this->serviceNombres->format($valeur, $decimales, $locale);
    }

    /** Montant symbole compris, placé selon la langue : « 10,00 $ » / « $10.00 ». */
    public function formatMontant(
        int|float|string|null $valeur,
        int $decimales = 2,
        ?string $locale = null,
        string $symbole = '$',
    ): string {
        return $this->serviceNombres->formatMontant($valeur, $decimales, $locale, $symbole);
    }
}
