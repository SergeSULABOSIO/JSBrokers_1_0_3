<?php

namespace App\Services\Canvas\Provider\List;

/**
 * Interface pour les fournisseurs de canevas de liste spécifiques à une entité.
 * Chaque implémentation saura comment construire le canevas de liste pour UNE seule entité.
 */
interface ListCanvasProviderInterface
{
    /**
     * Indique si ce fournisseur prend en charge la classe d'entité donnée.
     */
    public function supports(string $entityClassName): bool;

    /**
     * Retourne la configuration du canevas de liste pour l'entité prise en charge.
     */
    public function getCanvas(): array;
}
