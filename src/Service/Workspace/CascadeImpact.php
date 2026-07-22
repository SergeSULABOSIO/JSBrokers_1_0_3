<?php

namespace App\Service\Workspace;

/**
 * Résultat de l'analyse d'impact d'une suppression : les entités enfants qui
 * seront effacées EN CASCADE (avec leur compte) et les BLOCAGES éventuels
 * (contrainte de clé étrangère restrictive qui empêcherait la suppression).
 * Objet de valeur — présentation uniquement (aucune logique métier).
 */
final class CascadeImpact
{
    /**
     * @param array<int, array{entite: string, libelle: string, count: int}> $enfants
     * @param string[]                                                        $blocages
     */
    public function __construct(
        public readonly array $enfants = [],
        public readonly array $blocages = [],
    ) {
    }

    public function estBloque(): bool
    {
        return $this->blocages !== [];
    }

    /**
     * Lignes lisibles pour l'utilisateur (liste d'impacts de la confirmation
     * renforcée + tableau de revue du plan). Vide s'il n'y a aucun impact.
     *
     * @return string[]
     */
    public function descriptions(): array
    {
        $lignes = [];
        foreach ($this->enfants as $enfant) {
            if ($enfant['count'] > 0) {
                $lignes[] = sprintf('%d %s liée(s) seront aussi supprimées', $enfant['count'], $enfant['libelle']);
            }
        }

        return array_merge($lignes, $this->blocages);
    }
}
