<?php

namespace App\Services\Search;

/**
 * LA POLICE D'UNE TRANCHE, COMME CRITÈRE DE RECHERCHE (demande de Serge, 2026-10-26).
 *
 * « Montre-moi les tranches de la police 6645SDF… » : la référence vit sur l'AVENANT, et une
 * tranche l'atteint par sa cotation (tranche → cotation → avenants). Ce chemin est écrit UNE
 * fois, ici : le canevas de la Tranche le déclare (barre simple et recherche avancée), Ket
 * ouvre la rubrique avec lui (`ouvrir_rubrique`, argument `police`).
 *
 * Le moteur le cherche par une sous-requête EXISTS (JSBDynamicSearchService) : tous les
 * avenants d'une police portent sa référence, une jointure doublerait les tranches.
 */
final class PoliceDeLaTranche
{
    public const ENTITE = 'Tranche';
    public const CHEMIN = 'cotation.avenants.referencePolice';
    public const LIBELLE = 'Police';

    /**
     * Le critère que l'écran poserait pour ce terme : « contient », comme la barre simple.
     * Vide hors de la rubrique Tranches, ou pour un terme vide.
     *
     * @return array<string, array{operator: string, value: string, mode: string, label: string}>
     */
    public static function critereRecherche(string $entityShortName, mixed $police): array
    {
        $terme = trim((string) $police);
        if ($entityShortName !== self::ENTITE || $terme === '') {
            return [];
        }

        return [self::CHEMIN => [
            'operator' => 'LIKE',
            'value' => $terme,
            'mode' => 'contains',
            'label' => $terme,
        ]];
    }

    /** @return array{type: string, description: string} */
    public static function proprieteSchema(): array
    {
        return [
            'type' => 'string',
            'description' => 'TRANCHE uniquement : la référence de police (ou un fragment) dont on veut '
                . 'voir les tranches — la même recherche que la barre « Police » de l\'écran.',
        ];
    }
}
