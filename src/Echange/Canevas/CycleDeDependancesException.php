<?php

namespace App\Echange\Canevas;

/**
 * Un cycle de dépendances que rien ne peut trancher : toutes ses arêtes sont des
 * clés étrangères NON nullables. Aucun ordre d'écriture ne le satisferait, et la
 * base elle-même refuserait la première insertion.
 *
 * Levée au moment de bâtir le canevas, donc au premier accès à la rubrique — jamais
 * au milieu d'un import. C'est une erreur de MODÈLE, pas de données : elle ne peut
 * apparaître qu'en ajoutant une entité au périmètre, et doit se voir tout de suite.
 */
final class CycleDeDependancesException extends \RuntimeException
{
}
