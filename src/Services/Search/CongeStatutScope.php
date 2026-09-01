<?php

namespace App\Services\Search;

use App\Entity\DemandeConge;

/**
 * Vocabulaire UNIQUE des statuts d'une demande de congé.
 *
 * Le statut est une VRAIE colonne (DemandeConge.statut) : il n'y a donc aucun critère
 * synthétique à traduire, et le filtrage se fait par le chemin générique de
 * JSBDynamicSearchService. Ce qui doit être partagé, ce sont les LIBELLÉS — écran,
 * chips, indicateur de liste, e-mails et assistant doivent nommer un même état d'un même
 * mot. Deux traductions du même statut, c'est un utilisateur qui croit voir deux choses.
 *
 * `ECHUE` n'est PAS un statut : une demande approuvée dont la date de fin est passée
 * reste APPROUVEE en base. L'échéance est une lecture de la date, jamais un état de plus
 * à faire basculer par une tâche nocturne.
 */
final class CongeStatutScope
{
    /** Le champ filtré. Colonne réelle, d'où l'absence de clé synthétique. */
    public const CRITERION_KEY = 'statut';

    /**
     * Statut => libellé affiché. Source unique.
     *
     * @var array<string, string>
     */
    public const VALEURS = [
        DemandeConge::STATUT_BROUILLON => 'Brouillon',
        DemandeConge::STATUT_SOUMISE => 'En attente',
        DemandeConge::STATUT_APPROUVEE => 'Approuvée',
        DemandeConge::STATUT_REFUSEE => 'Refusée',
        DemandeConge::STATUT_ANNULEE => 'Annulée',
    ];

    /**
     * Icône de chip par statut (alias IconCanvasProvider).
     *
     * @var array<string, string>
     */
    private const ICONES = [
        DemandeConge::STATUT_BROUILLON => 'action:edit',
        DemandeConge::STATUT_SOUMISE => 'action:ongoing',
        DemandeConge::STATUT_APPROUVEE => 'action:completed',
        DemandeConge::STATUT_REFUSEE => 'action:cancel',
        DemandeConge::STATUT_ANNULEE => 'action:annulation',
    ];

    public static function estValide(?string $statut): bool
    {
        return $statut !== null && isset(self::VALEURS[$statut]);
    }

    public static function libelle(?string $statut): string
    {
        return self::VALEURS[$statut] ?? (string) $statut;
    }

    /**
     * Options du chip « Statut ».
     *
     * L'option de valeur vide RETIRE le filtre : elle doit rester présente en toutes
     * circonstances, sinon un filtre posé ne peut plus être levé qu'en rechargeant la page.
     *
     * @return array<int, array{value: string, label: string, icon: string}>
     */
    public static function optionsChips(): array
    {
        $options = [];
        foreach (self::VALEURS as $valeur => $libelle) {
            $options[] = [
                'value' => $valeur,
                'label' => $libelle,
                'icon' => self::ICONES[$valeur] ?? 'action:filter',
            ];
        }
        $options[] = ['value' => '', 'label' => 'Tous', 'icon' => 'action:filter'];

        return $options;
    }

    /**
     * Critère de recherche pour un statut donné — même forme que celle produite par un
     * chip côté navigateur, pour que le serveur et l'écran posent exactement le même
     * filtre.
     *
     * @return array<string, array{operator: string, value: string, label: string}>
     */
    public static function critereRecherche(string $statut): array
    {
        return [
            self::CRITERION_KEY => [
                'operator' => '=',
                'value' => $statut,
                'label' => self::libelle($statut),
            ],
        ];
    }
}
