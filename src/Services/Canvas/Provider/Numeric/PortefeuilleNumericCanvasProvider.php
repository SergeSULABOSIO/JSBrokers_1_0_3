<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\Portefeuille;

/**
 * QUATRE COLONNES D'ARGENT SOUS UNE BARRE QUI DISAIT N'AVOIR RIEN À COMPTER.
 *
 * La rubrique affiche Primes, Sinistres, Commissions et Réserve — et sa barre annonçait
 * « Aucune valeur numérique à calculer », faute d'un NumericCanvasProvider. Les quatre
 * valeurs étaient pourtant là, déclarées sur l'entité et hydratées par
 * PortefeuilleIndicatorStrategy avant même que le canevas ne soit construit.
 *
 * Rien à calculer ici : les colonnes suffisent à décrire ce qui est totalisable.
 */
class PortefeuilleNumericCanvasProvider extends AbstractListColumnsNumericCanvasProvider
{
    protected function entityClass(): string
    {
        return Portefeuille::class;
    }
}
