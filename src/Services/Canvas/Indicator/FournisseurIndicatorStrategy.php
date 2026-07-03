<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Fournisseur;

class FournisseurIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Fournisseur::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Fournisseur $entity */
        $coordonnees = array_filter([
            $entity->getPersonneContact(),
            $entity->getTelephone(),
            $entity->getEmail(),
        ]);

        return [
            'coordonnees'     => $coordonnees === [] ? 'Coordonnées non renseignées' : implode(' · ', $coordonnees),
            'actifLabel'      => $entity->isActif() ? 'Actif' : 'Inactif',
            'nombreDocuments' => $entity->getDocuments()->count(),
        ];
    }
}
