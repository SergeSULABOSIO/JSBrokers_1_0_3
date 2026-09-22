<?php

namespace App\Ai\Dictee;

use App\Ai\Fournisseur\Fournisseur;

/**
 * L'APPEL au fournisseur pour la finition d'une dictée — et rien d'autre.
 *
 * Tout ce qui protège l'utilisateur reste dans FinisseurDeDictee : le plancher de
 * longueur, le contrôle de fidélité (aucun nom, aucun nombre ne doit bouger), la
 * remise en liste, et le repli sur le texte brut au moindre doute. Ce contrat ne
 * couvre que la conversation avec le modèle.
 *
 * Même raison de le séparer que pour la phase de compréhension : la finition
 * parlait à Google en direct, quelle que soit la clé posée. Un cabinet qui tourne
 * sur Claude n'a aucune raison de devoir garder une clé Gemini pour que sa dictée
 * soit mise au propre.
 */
interface AppelDeFinition extends Fournisseur
{
    public function modele(): string;

    /** La clé du compteur de débit — elle ne se déduit pas du nom du modèle. */
    public function cleDeDebit(): string;

    /**
     * Met la dictée au propre et rend le texte fini, plus les tokens d'entrée
     * consommés — déjà déclarés au compteur de débit.
     *
     * @param string $consigne      la règle de réécriture, identique quel que soit le fournisseur
     * @param int    $plafondSortie dimensionné sur la longueur de la dictée
     *
     * @return array{texte: string, tokens: int}
     */
    public function finir(string $consigne, string $brut, int $plafondSortie): array;
}
