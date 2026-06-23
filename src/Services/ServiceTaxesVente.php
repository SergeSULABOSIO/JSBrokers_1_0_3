<?php

namespace App\Services;

use App\Repository\TaxeVenteRepository;

/**
 * @file Calculs de fiscalité sur les ventes propres de JS Brokers.
 * @description Lecture seule. À partir des taxes actives (TaxeVente), dégage le
 * revenu hors taxe d'un chiffre d'affaires TTC. Les taxes sont combinées en
 * additif sur base commune : diviseur = 1 + (Σ des taux)/100 (ex. 16 % + 5 %
 * → /1,21 ; cohérent avec l'exemple /1,16 pour une taxe seule). Distinct de
 * ServiceTaxes, qui reste dédié aux taxes du domaine assurance.
 */
class ServiceTaxesVente
{
    /** Taux global mémoïsé : les helpers Twig appellent les calculs par ligne. */
    private ?float $tauxGlobalCache = null;

    public function __construct(private TaxeVenteRepository $taxeVenteRepository)
    {
    }

    /** Somme des taux (en %) des taxes actives (mémoïsée pour les appels en boucle). */
    public function tauxGlobal(): float
    {
        if ($this->tauxGlobalCache === null) {
            $total = 0.0;
            foreach ($this->taxeVenteRepository->findActives() as $taxe) {
                $total += $taxe->getTauxFloat();
            }
            $this->tauxGlobalCache = $total;
        }

        return $this->tauxGlobalCache;
    }

    /** Revenu hors taxe d'un montant TTC : montant / (1 + Σtaux/100). */
    public function revenuHorsTaxe(float $ttc): float
    {
        return $ttc / (1 + $this->tauxGlobal() / 100);
    }

    /** Montant total des taxes dues sur un montant TTC. */
    public function montantTaxes(float $ttc): float
    {
        return $ttc - $this->revenuHorsTaxe($ttc);
    }

    /**
     * Ventilation du montant de taxe due par taxe active. Le montant de chaque
     * taxe s'applique sur la base commune (revenu hors taxe) ; la somme des
     * montants est donc égale à montantTaxes().
     *
     * @return array<int, array{taxe: \App\Entity\TaxeVente, montant: float}>
     */
    public function ventilation(float $ttc): array
    {
        $base = $this->revenuHorsTaxe($ttc);

        $lignes = [];
        foreach ($this->taxeVenteRepository->findActives() as $taxe) {
            $lignes[] = [
                'taxe'    => $taxe,
                'montant' => $base * $taxe->getTauxFloat() / 100,
            ];
        }

        return $lignes;
    }
}
