<?php

namespace App\Twig\Extension;

use App\Util\Pourcentage;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Unifie l'AFFICHAGE des pourcentages dans les templates via le VO Pourcentage
 * (source unique, cf. App\Util\Pourcentage) : plus de « number_format(...) . ' %' »
 * ni de ×100 dispersés dans les vues. Le formatage (décimales, séparateurs,
 * suffixe) vit dans le VO, donc identique partout.
 *
 * Usage :
 *   {{ 16|pourcentage }}              => « 16,00 % » (la valeur est DÉJÀ en pour-cent)
 *   {{ 0.16|pourcentage('fraction') }} => « 16,00 % » (la valeur est une fraction)
 *   {{ taux|pourcentage('pourcent', 0) }} => « 16 % »
 */
class PourcentageExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('pourcentage', [$this, 'pourcentage']),
        ];
    }

    /**
     * @param int|float|string|null $valeur Nombre en pour-cent (défaut) ou fraction (selon $depuis).
     * @param string                $depuis 'pourcent' (défaut) ou 'fraction'.
     */
    public function pourcentage(int|float|string|null $valeur, string $depuis = 'pourcent', int $scale = 2): string
    {
        $p = $depuis === 'fraction'
            ? Pourcentage::fromFraction((float) $valeur)
            : Pourcentage::fromPourcent($valeur);

        return $p->format($scale);
    }
}
