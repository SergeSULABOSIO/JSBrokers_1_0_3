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
            new TwigFunction('monnaie_pays', [$this, 'getMonnaiePays']),
        ];
    }

    /**
     * Code monnaie (ISO 4217) local du pays. C'est la monnaie dans laquelle est
     * toujours saisi le capital social (cf. EntrepriseType, qui dérive la monnaie
     * du MoneyType du pays). À utiliser pour afficher le capital social, et non la
     * monnaie d'affichage du workspace.
     */
    public function getMonnaiePays(?int $codePays): ?string
    {
        return $codePays !== null ? $this->serviceGeographie->getMonnaie($codePays) : null;
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
