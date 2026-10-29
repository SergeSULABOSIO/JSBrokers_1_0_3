<?php

namespace App\Services\Search;

use App\Services\CanvasBuilder;

/**
 * LA LIGNE DES FILTRES ACTIFS, côté serveur — ce que l'écran écrit sous la recherche, mot
 * pour mot (refonte de la colonne 3, C2+).
 *
 * L'écran la calcule dans `assets/controllers/search-bar-filtres.js` (`filtresActifs`) ; Ket
 * la lit ici pour annoncer ce que montre la rubrique qu'il ouvre (`ouvrir_rubrique`). Les
 * deux écritures de la règle jouent le MÊME fichier de cas
 * (`tests/js/fixtures/ligne-des-filtres.json`) : node pour l'une, PHPUnit pour l'autre.
 * Modifier l'une impose de modifier l'autre.
 */
final class LigneDesFiltres
{
    public function __construct(private readonly CanvasBuilder $canvasBuilder)
    {
    }

    /**
     * La ligne d'une rubrique, lue sur ses canevas (chips de la liste, critères de recherche).
     *
     * @param string               $entite   la classe de l'entité, ou le nom d'une rubrique sans classe
     * @param array<string, mixed> $criteres les critères en vigueur
     * @param array<string, mixed> $defauts  les critères par défaut de la liste
     * @param string[]             $imposes  les clés que le serveur impose
     *
     * @return list<array{cle: string, texte: string, parDefaut: bool, retirable: bool}>
     */
    public function pour(string $entite, array $criteres, array $defauts = [], array $imposes = []): array
    {
        return self::calculer(
            $criteres,
            $this->canvasBuilder->getSearchCanvas($entite),
            self::groupesDuCanevas($this->canvasBuilder->getListeCanvas($entite)['filtres_predefinis'] ?? []),
            $defauts,
            $imposes,
        );
    }

    /**
     * Les groupes de chips tels que la barre de recherche les lit dans le DOM
     * (`search-bar_controller.js#_groupesDeChips`) : titre, options À VALEUR (un chip-sélecteur
     * n'en porte pas), défaut d'un groupe sans « Toutes », maille de lecture.
     *
     * @param array<int, array<string, mixed>> $filtresPredefinis
     *
     * @return list<array{cle: string, titre: string, options: array<string, string>, defaut: ?string, presentation: bool}>
     */
    public static function groupesDuCanevas(array $filtresPredefinis): array
    {
        $groupes = [];
        foreach ($filtresPredefinis as $groupe) {
            $options = [];
            foreach ($groupe['options'] ?? [] as $option) {
                // Un chip-sélecteur (`option.selecteur is defined`) ne porte pas de valeur.
                if (array_key_exists('selecteur', $option)) {
                    continue;
                }
                $options[(string) ($option['value'] ?? '')] = trim((string) ($option['label'] ?? ''));
            }
            // Le défaut n'existe dans le DOM que s'il est l'une des options (`data-criterion-defaut`),
            // et seulement pour un groupe sans « Toutes ».
            $defaut = isset($groupe['defaut']) && !array_key_exists('', $options) && array_key_exists((string) $groupe['defaut'], $options)
                ? (string) $groupe['defaut']
                : null;
            // `groupe.titre|default(groupe.libelle|default('Filtrer'))` : une chaîne vide passe au suivant.
            $titre = array_values(array_filter(
                [(string) ($groupe['titre'] ?? ''), (string) ($groupe['libelle'] ?? ''), 'Filtrer'],
                static fn (string $t): bool => $t !== '',
            ))[0];
            $groupes[] = [
                'cle' => (string) $groupe['critere'],
                'titre' => trim($titre),
                'options' => $options,
                'defaut' => $defaut,
                'presentation' => (bool) ($groupe['presentation'] ?? false),
            ];
        }

        return $groupes;
    }

    /**
     * LA RÈGLE, écrite comme `filtresActifs` : les chips d'abord, dans l'ordre de leurs groupes
     * (« Prime : Impayée ») ; puis les autres critères dans l'ordre reçu. Un critère égal au
     * défaut porte `parDefaut` ; le défaut d'un groupe sans « Toutes » se montre même absent,
     * sans × ; un critère imposé se lit sans × ; la maille de lecture n'apparaît jamais.
     *
     * @param array<string, mixed>             $criteres
     * @param array<int, array<string, mixed>> $definitions le searchCanvas
     * @param array<int, array<string, mixed>> $groupes     cf. groupesDuCanevas
     * @param array<string, mixed>             $defauts
     * @param string[]                         $imposes
     *
     * @return list<array{cle: string, texte: string, parDefaut: bool, retirable: bool}>
     */
    public static function calculer(array $criteres, array $definitions, array $groupes, array $defauts = [], array $imposes = []): array
    {
        $parDefaut = static fn (string $cle, string $valeur): bool => array_key_exists($cle, $defauts) && self::valeurDe($defauts[$cle]) === $valeur;
        $impose = static fn (string $cle): bool => in_array($cle, $imposes, true);
        $vus = [];
        $filtres = [];

        foreach ($groupes as $groupe) {
            $cle = (string) $groupe['cle'];
            $vus[$cle] = true;
            if (!empty($groupe['presentation'])) {
                continue;
            }
            $options = $groupe['options'] ?? [];
            $defaut = isset($groupe['defaut']) && $groupe['defaut'] !== '' ? (string) $groupe['defaut'] : null;
            $valeur = self::valeurDe($criteres[$cle] ?? null);
            if ($valeur === '') {
                if ($defaut !== null) {
                    $filtres[] = ['cle' => $cle, 'texte' => $groupe['titre'] . ' : ' . ($options[$defaut] ?? $defaut), 'parDefaut' => true, 'retirable' => false];
                }
                continue;
            }
            $critere = $criteres[$cle];
            $libelle = $options[$valeur] ?? ((is_array($critere) && !empty($critere['label'])) ? (string) $critere['label'] : $valeur);
            $fixe = $defaut === $valeur || $impose($cle);
            $filtres[] = ['cle' => $cle, 'texte' => $groupe['titre'] . ' : ' . $libelle, 'parDefaut' => $fixe || $parDefaut($cle, $valeur), 'retirable' => !$fixe];
        }

        foreach ($criteres as $cle => $critere) {
            $cle = (string) $cle;
            if (isset($vus[$cle])) {
                continue;
            }
            $valeur = self::valeurDe($critere);
            $plage = is_array($critere) && (!empty($critere['from']) || !empty($critere['to']));
            if ($valeur === '' && !$plage) {
                continue;
            }
            $def = null;
            foreach ($definitions as $candidate) {
                if (($candidate['Nom'] ?? null) === $cle) {
                    $def = $candidate;
                    break;
                }
            }
            $libelle = !empty($def['Display']) ? (string) $def['Display'] : $cle;
            $filtres[] = ['cle' => $cle, 'texte' => self::texte($libelle, $critere, $def), 'parDefaut' => $impose($cle) || $parDefaut($cle, $valeur), 'retirable' => !$impose($cle)];
        }

        return $filtres;
    }

    /**
     * LES CRITÈRES SONT-ILS DÉJÀ CEUX PAR DÉFAUT ? — `auxDefauts`. Alors « Réinitialiser » ne
     * changerait rien, et ne s'offre ni sur la ligne des filtres ni sur l'état vide. Comparés
     * par opérateur (absent = « = ») et valeur ; une valeur vide compte comme absente ; la maille
     * de lecture, un critère imposé et le défaut d'un groupe sans « Toutes » ne comptent pas.
     *
     * @param array<string, mixed>             $criteres
     * @param array<string, mixed>             $defauts
     * @param array<int, array<string, mixed>> $groupes cf. groupesDuCanevas
     * @param string[]                         $imposes
     */
    public static function auxDefauts(array $criteres, array $defauts, array $groupes = [], array $imposes = []): bool
    {
        $horsComparaison = static function (string $cle, mixed $critere) use ($groupes, $imposes): bool {
            if (in_array($cle, $imposes, true)) {
                return true;
            }
            foreach ($groupes as $groupe) {
                if (($groupe['cle'] ?? null) === $cle && (!empty($groupe['presentation'])
                    || (isset($groupe['defaut']) && (string) $groupe['defaut'] === self::valeurDe($critere)))) {
                    return true;
                }
            }

            return false;
        };
        $actifs = static function (array $liste) use ($horsComparaison): array {
            $signatures = [];
            foreach ($liste as $cle => $critere) {
                $signature = self::signature($critere);
                if (!$horsComparaison((string) $cle, $critere) && $signature !== '') {
                    $signatures[(string) $cle] = $signature;
                }
            }
            ksort($signatures);

            return $signatures;
        };

        return $actifs($criteres) === $actifs($defauts);
    }

    /** Ce qui distingue un critère : son opérateur et sa valeur, ou les bornes d'une plage (`signature`). */
    private static function signature(mixed $critere): string
    {
        if (is_array($critere) && (!empty($critere['from']) || !empty($critere['to']))) {
            return ($critere['from'] ?? '') . '…' . ($critere['to'] ?? '');
        }
        $valeur = self::valeurDe($critere);

        return $valeur === '' ? '' : ((is_array($critere) && !empty($critere['operator']) ? $critere['operator'] : '=') . ' ' . $valeur);
    }

    /**
     * Le libellé lisible d'UN filtre, selon son type — `formatterTexteFiltre`.
     *
     * @param array<string, mixed>|null $def
     */
    public static function texte(string $libelle, mixed $valeur, ?array $def): string
    {
        $type = $def['Type'] ?? null;

        if ($type === 'DateTimeRange' || (is_array($valeur) && (!empty($valeur['from']) || !empty($valeur['to'])))) {
            $du = is_array($valeur) ? (string) ($valeur['from'] ?? '') : '';
            $au = is_array($valeur) ? (string) ($valeur['to'] ?? '') : '';
            if ($du !== '' && $au !== '') {
                return sprintf('%s : du %s au %s', $libelle, $du, $au);
            }
            if ($du !== '') {
                return sprintf('%s : à partir du %s', $libelle, $du);
            }
            if ($au !== '') {
                return sprintf("%s : jusqu'au %s", $libelle, $au);
            }
        }

        if ($type === 'Relation' && is_array($valeur)) {
            return $libelle . ' : ' . self::enTexte(!empty($valeur['label']) ? $valeur['label'] : ($valeur['value'] ?? null));
        }

        if ($type === 'Boolean') {
            $brut = is_array($valeur) ? ($valeur['value'] ?? null) : $valeur;
            $mot = is_array($def['Valeur'] ?? null) ? ($def['Valeur'][self::enTexte($brut)] ?? null) : $brut;

            return $libelle . ' : ' . self::enTexte($mot ?? $brut);
        }

        if (is_array($valeur) && !empty($valeur['operator']) && $type === 'Number') {
            return sprintf('%s %s %s', $libelle, $valeur['operator'], self::enTexte($valeur['value'] ?? null));
        }

        return sprintf('%s : "%s"', $libelle, self::enTexte(is_array($valeur) ? ($valeur['value'] ?? null) : $valeur));
    }

    /** La valeur d'un critère, quelle que soit sa forme (`valeurDe`). */
    private static function valeurDe(mixed $critere): string
    {
        if ($critere === null) {
            return '';
        }

        return self::enTexte(is_array($critere) ? ($critere['value'] ?? '') : $critere);
    }

    /** Une valeur écrite comme JavaScript l'écrit (`String(x)`) : true → « true ». */
    private static function enTexte(mixed $valeur): string
    {
        return match (true) {
            $valeur === null => 'null',
            is_bool($valeur) => $valeur ? 'true' : 'false',
            default => (string) $valeur,
        };
    }
}
