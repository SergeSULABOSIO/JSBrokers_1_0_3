<?php

namespace App\Twig\Extension;

use App\Services\ServiceNombres;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Expose le formatage localisé des nombres aux gabarits : `{{ 8800|format_nombre }}`
 * rend « 8 800 » en français et « 8,800 » en anglais. Les e-mails et la vitrine,
 * qui rendent dans une langue imposée, passent celle-ci en 2e argument :
 * `{{ pack.tokens|format_nombre(0, lang) }}`.
 */
class NombreExtension extends AbstractExtension
{
    public function __construct(private ServiceNombres $serviceNombres) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('format_nombre', [$this, 'formatNombre']),
        ];
    }

    public function formatNombre(int|float|string|null $valeur, int $decimales = 0, ?string $locale = null): string
    {
        return $this->serviceNombres->format($valeur, $decimales, $locale);
    }
}
