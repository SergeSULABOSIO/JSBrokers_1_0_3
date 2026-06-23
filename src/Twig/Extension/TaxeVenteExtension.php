<?php

namespace App\Twig\Extension;

use App\Services\ServiceTaxesVente;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose la fiscalité des ventes JS Brokers aux templates de la Console afin
 * d'afficher, à côté de tout montant TTC, le revenu hors taxe et les taxes dues.
 * Le calcul lit la même source de vérité que le backend (App\Services\
 * ServiceTaxesVente : taxes actives configurées en Console), si bien que toute
 * édition des taxes se reflète partout. Réservé à l'usage interne (agents) : le
 * HT est le revenu NET de JS Brokers, pas une taxe facturée au client.
 */
class TaxeVenteExtension extends AbstractExtension
{
    public function __construct(private ServiceTaxesVente $taxesVente) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('prix_ht', [$this, 'prixHorsTaxe']),
            new TwigFunction('taxe_due', [$this, 'taxeDue']),
            new TwigFunction('taux_taxe_global', [$this, 'tauxGlobal']),
        ];
    }

    /** Revenu hors taxe d'un montant TTC (montant / (1 + Σtaux/100)). */
    public function prixHorsTaxe(float $ttc): float
    {
        return $this->taxesVente->revenuHorsTaxe($ttc);
    }

    /** Montant total des taxes dues sur un montant TTC. */
    public function taxeDue(float $ttc): float
    {
        return $this->taxesVente->montantTaxes($ttc);
    }

    /** Somme des taux (en %) des taxes actives. */
    public function tauxGlobal(): float
    {
        return $this->taxesVente->tauxGlobal();
    }
}
