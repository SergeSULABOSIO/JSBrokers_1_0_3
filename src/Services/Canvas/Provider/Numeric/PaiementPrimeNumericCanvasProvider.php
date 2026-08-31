<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\PaiementPrime;

/**
 * UN CHAMP PRIVÉ SE LIT PAR SON ACCESSEUR — ce que l'ancien lecteur ne savait pas faire.
 *
 * La rubrique n'a qu'une colonne, « Montant réglé », et sa barre était muette : aucun
 * NumericCanvasProvider n'existait pour elle.
 *
 * Le rétablir ne suffisait pas. `PaiementPrime::$montant` est une colonne Doctrine PRIVÉE,
 * exposée par getMontant() : le tableau l'affiche parce que Twig essaie l'accesseur quand
 * la propriété n'est pas publique, mais l'ancien mécanisme s'en tenait à un
 * `property_exists()` suivi d'un accès direct. Il aurait rendu la seule colonne de la
 * rubrique intotalisable, en silence — une barre muette au-dessus d'une colonne pleine.
 * C'est ce cas précis que ListColumnsNumericCanvasBuilder traite par son second chemin
 * de lecture.
 *
 * À noter : cette entité n'a aucune IndicatorStrategy, et n'en a pas besoin. Son montant
 * est un fait persisté, pas un indicateur calculé.
 */
class PaiementPrimeNumericCanvasProvider extends AbstractListColumnsNumericCanvasProvider
{
    protected function entityClass(): string
    {
        return PaiementPrime::class;
    }
}
