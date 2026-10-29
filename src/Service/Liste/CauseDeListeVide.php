<?php

namespace App\Service\Liste;

use App\Services\Search\PortefeuilleScope;

/**
 * POURQUOI CETTE LISTE EST VIDE (chantier 6, décisions de Serge du 2026-10-21).
 *
 * « Aucun élément trouvé » ne disait pas si le cabinet n'avait rien, ou si l'on avait tout
 * filtré — et « Réinitialiser » promettait autre chose que ce qu'il faisait. Trois causes :
 *
 *   — AUCUNE_DONNEE : le cabinet n'a aucune fiche ici (filtres ou non) ;
 *   — PORTEFEUILLE : « Mon portefeuille » est le SEUL filtre actif. On propose « Afficher tout
 *     le cabinet » à TOUS : la chip est un confort de lecture, pas une frontière (option A) ;
 *   — FILTRES : d'autres filtres sont actifs (terme, chips, critères de Ket…). « Tout
 *     retirer » retire ceux-là, et « Mon portefeuille » seulement pour un ADMINISTRATEUR du
 *     cabinet (WorkspaceAccessResolver::estAdministrateurDuCabinet).
 *
 * Règle pure : le contrôleur lui donne les critères VUS par l'utilisateur (pas les filtres que
 * le serveur ajoute de lui-même) et lui dit s'il existe des fiches sans aucun d'eux.
 */
final class CauseDeListeVide
{
    public const AUCUNE_DONNEE = 'aucune_donnee';
    public const PORTEFEUILLE = 'portefeuille';
    public const FILTRES = 'filtres';

    /**
     * @param array<string, mixed> $criteres        critères tels que l'utilisateur les voit
     * @param bool                 $existeDesFiches le cabinet a-t-il des fiches ici, sans ces critères ?
     * @param bool                 $administrateur  l'invité connecté administre-t-il tout le cabinet ?
     *
     * @return array{cause: string, cles: list<string>} `cles` : les critères que le bouton retire
     */
    public static function determiner(array $criteres, bool $existeDesFiches, bool $administrateur): array
    {
        if (!$existeDesFiches) {
            return ['cause' => self::AUCUNE_DONNEE, 'cles' => []];
        }

        $actifs = array_values(array_filter(array_keys($criteres), static fn ($cle): bool => self::estActif($criteres[$cle])));
        $actifs = array_map('strval', $actifs);
        $portefeuille = \in_array(PortefeuilleScope::CRITERION_KEY, $actifs, true);
        $autres = array_values(array_filter($actifs, static fn (string $cle): bool => $cle !== PortefeuilleScope::CRITERION_KEY));

        if ($autres === []) {
            return $portefeuille
                ? ['cause' => self::PORTEFEUILLE, 'cles' => [PortefeuilleScope::CRITERION_KEY]]
                // Des fiches existent et rien ne filtre : la liste n'est pas vide. Par prudence
                // (parent d'un onglet contextuel, filtre imposé), on ne promet aucun geste.
                : ['cause' => self::AUCUNE_DONNEE, 'cles' => []];
        }

        return ['cause' => self::FILTRES, 'cles' => ($administrateur && $portefeuille) ? [...$autres, PortefeuilleScope::CRITERION_KEY] : $autres];
    }

    /** Au moins un critère filtre-t-il vraiment ? Sinon, inutile de chercher s'il existe des fiches. */
    public static function aDesCriteresActifs(array $criteres): bool
    {
        foreach ($criteres as $critere) {
            if (self::estActif($critere)) {
                return true;
            }
        }

        return false;
    }

    /** Un critère filtre-t-il vraiment ? (valeur vide, plage sans borne : non) */
    public static function estActif(mixed $critere): bool
    {
        if ($critere === null || $critere === '' || $critere === []) {
            return false;
        }
        if (\is_array($critere)) {
            if (\array_key_exists('from', $critere) || \array_key_exists('to', $critere)) {
                return ($critere['from'] ?? '') !== '' || ($critere['to'] ?? '') !== '';
            }
            if (\array_key_exists('value', $critere)) {
                $valeur = $critere['value'];

                return $valeur !== null && $valeur !== '' && $valeur !== [];
            }
        }

        return true;
    }
}
