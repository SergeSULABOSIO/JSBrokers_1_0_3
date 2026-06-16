<?php

namespace App\Twig\Extension;

use App\Services\ServiceGeographie;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose les libellés géographiques aux templates : le pays et la ville d'une
 * entreprise sont stockés sous forme de codes numériques (ISO 3166-1 / id ville)
 * et résolus en noms via App\Services\ServiceGeographie.
 */
class GeographieExtension extends AbstractExtension
{
    public function __construct(private ServiceGeographie $serviceGeographie) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('nom_pays', [$this, 'getNomPays']),
            new TwigFunction('nom_ville', [$this, 'getNomVille']),
        ];
    }

    public function getNomPays(?int $codePays): ?string
    {
        return $codePays !== null ? $this->serviceGeographie->getNomPays($codePays) : null;
    }

    public function getNomVille(?int $idVille): ?string
    {
        return $idVille !== null ? $this->serviceGeographie->getNomVille($idVille) : null;
    }
}
