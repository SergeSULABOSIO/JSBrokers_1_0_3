<?php

namespace App\Services\Canvas\Provider\Entity;

interface EntityCanvasProviderInterface
{
    /**
     * Détermine si ce fournisseur peut gérer le canevas pour la classe d'entité donnée.
     *
     * @param string $entityClassName Le nom de la classe de l'entité.
     * @return bool
     */
    public function supports(string $entityClassName): bool;

    /**
     * Construit et retourne le tableau de canevas pour l'entité supportée.
     *
     * @return array
     */
    public function getCanvas(): array;
}