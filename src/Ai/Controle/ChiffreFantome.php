<?php

namespace App\Ai\Controle;

/**
 * LE CHIFFRE FANTÔME — un montant inscrit dans un tableau de Ket qu'AUCUN outil n'a rendu.
 *
 * L'INCIDENT (2026-09-19, fil 74). À « donne-moi le top 5 de nos clients », Ket a rendu
 * un tableau avec une colonne RÉSERVE : 2 922,14 $ pour l'un, 9 396,11 $ pour l'autre,
 * 0,00 $ pour les trois derniers, et un total de 12 318,25 $. L'outil interrogé ne
 * renvoyait aucune réserve : la colonne entière était inventée. Les commissions, elles,
 * valaient exactement 1,16 fois les vraies — une taxe appliquée de son propre chef à une
 * colonne déjà nommée TTC. L'utilisateur a dû la contredire pour obtenir le bon chiffre.
 *
 * LA CAUSE PREMIÈRE EST CORRIGÉE À LA SOURCE (l'outil transmet désormais la réserve), et
 * le prompt interdit depuis toujours d'inventer un montant. Ceci est la ceinture qui
 * accompagne la bretelle, exactement comme le plan fantôme ou le démenti de pièce
 * jointe : le contenu d'une bulle est écrit par un modèle, et aucune consigne ne l'oblige
 * absolument. Le serveur, lui, SAIT ce que les outils ont rendu.
 *
 * ON NE CORRIGE PAS, ON SIGNALE. Réécrire le tableau demanderait de savoir ce que Ket
 * voulait dire ; on ne le sait pas. Ce qu'on sait, et qui suffit, c'est que tel montant
 * ne vient d'aucune donnée — et un courtier qui le lit doit l'apprendre avant de le
 * porter dans un dossier.
 *
 * CONSERVATEUR PAR CONSTRUCTION, parce qu'une fausse alerte coûterait la confiance :
 *  - seuls les MONTANTS sont examinés (symbole monétaire ou deux décimales), jamais un
 *    nombre de polices, une année ou un pourcentage ;
 *  - seuls les TABLEAUX le sont : une phrase peut légitimement arrondir ou comparer ;
 *  - un total de colonne est accepté, puisqu'il se calcule ;
 *  - l'arrondi du modèle est accepté à la précision qu'il a choisie ;
 *  - sans aucun chiffre d'outil (réponse de pure conversation), on ne dit rien.
 */
final class ChiffreFantome
{
    /** Au-delà, on cesse de nommer : la liste deviendrait illisible. */
    private const MAX_NOMMES = 8;

    /**
     * Les montants d'un tableau que rien ne justifie.
     *
     * @param string             $prose    la réponse rédigée par le modèle
     * @param list<float|int>    $connus   tous les nombres rendus par les outils du tour
     *
     * @return array{montants: list<string>, total: int}|null null quand tout est justifié
     */
    public static function detecter(string $prose, array $connus): ?array
    {
        if ($connus === [] || trim($prose) === '') {
            return null;
        }

        $fantomes = [];
        foreach (self::tableaux($prose) as $tableau) {
            foreach (self::fantomesDuTableau($tableau, $connus) as $fantome) {
                $fantomes[$fantome] = true;
            }
        }
        if ($fantomes === []) {
            return null;
        }

        $noms = array_keys($fantomes);

        return ['montants' => array_slice($noms, 0, self::MAX_NOMMES), 'total' => count($noms)];
    }

    /**
     * TOUS LES NOMBRES d'un résultat d'outil, si profond soit-il.
     *
     * On ne cherche pas à savoir lequel est un montant : ce qui compte est qu'un chiffre
     * écrit par Ket se retrouve QUELQUE PART dans ce que les outils ont rendu.
     *
     * @return list<float>
     */
    public static function nombresDe(mixed $donnees): array
    {
        $nombres = [];
        self::collecter($donnees, $nombres);

        return array_values(array_unique($nombres, SORT_REGULAR));
    }

    private static function collecter(mixed $valeur, array &$nombres): void
    {
        if (\is_int($valeur) || \is_float($valeur)) {
            $nombres[] = (float) $valeur;

            return;
        }
        if (\is_string($valeur) && is_numeric(trim($valeur))) {
            $nombres[] = (float) trim($valeur);

            return;
        }
        if (\is_array($valeur)) {
            foreach ($valeur as $element) {
                self::collecter($element, $nombres);
            }
        }
    }

    /**
     * Les lignes de chaque tableau Markdown, cellule par cellule.
     *
     * @return list<list<list<string>>> tableaux => lignes => cellules
     */
    private static function tableaux(string $prose): array
    {
        $tableaux = [];
        $courant = [];
        foreach (preg_split('/\R/', $prose) ?: [] as $ligne) {
            $ligne = trim($ligne);
            if ($ligne === '' || !str_starts_with($ligne, '|')) {
                if ($courant !== []) {
                    $tableaux[] = $courant;
                    $courant = [];
                }
                continue;
            }
            $cellules = array_map('trim', explode('|', trim($ligne, '|')));
            // La ligne de séparation (|---|:--:|) ne porte aucune donnée.
            if (preg_match('/^[\s:|-]+$/', $ligne) === 1) {
                continue;
            }
            $courant[] = $cellules;
        }
        if ($courant !== []) {
            $tableaux[] = $courant;
        }

        return $tableaux;
    }

    /**
     * @param list<list<string>> $tableau
     * @param list<float|int>    $connus
     *
     * @return list<string>
     */
    private static function fantomesDuTableau(array $tableau, array $connus): array
    {
        // Les montants, rangés par colonne : la LIGNE DE TOTAL se calcule à partir des
        // autres, et doit donc être acceptée même si aucun outil ne l'a rendue telle
        // quelle. Ailleurs, un montant qui se trouve égaler la somme de ses voisins reste
        // un montant à justifier — la coïncidence ne vaut pas preuve.
        $parColonne = [];
        foreach ($tableau as $ligne) {
            $estTotal = self::estLigneDeTotal($ligne);
            foreach ($ligne as $colonne => $cellule) {
                $montant = self::montant($cellule);
                if ($montant !== null) {
                    $parColonne[$colonne][] = [...$montant, $estTotal];
                }
            }
        }

        $fantomes = [];
        foreach ($parColonne as $montants) {
            $sommeDesLignes = 0.0;
            foreach ($montants as [$valeur, , , $estTotal]) {
                if (!$estTotal) {
                    $sommeDesLignes += $valeur;
                }
            }
            foreach ($montants as [$valeur, $ecrit, $decimales, $estTotal]) {
                if (self::justifie($valeur, $decimales, $connus)) {
                    continue;
                }
                if ($estTotal && self::proche($valeur, $sommeDesLignes, $decimales)) {
                    continue;
                }
                $fantomes[] = $ecrit;
            }
        }

        return $fantomes;
    }

    /**
     * La ligne porte-t-elle un total ? Elle se nomme : « TOTAL », « Total général ».
     *
     * @param list<string> $ligne
     */
    private static function estLigneDeTotal(array $ligne): bool
    {
        $premiere = mb_strtolower(trim(preg_replace('/[*_`|]/', '', $ligne[0] ?? '') ?? ''));

        return $premiere !== '' && str_contains($premiere, 'total');
    }

    /**
     * Un montant, et rien d'autre : symbole monétaire ou deux décimales. Rend la valeur,
     * le texte tel qu'écrit et le nombre de décimales choisi par le modèle.
     *
     * @return array{0: float, 1: string, 2: int}|null
     */
    private static function montant(string $cellule): ?array
    {
        $nu = trim(preg_replace('/[*_`]/', '', $cellule) ?? '');
        if ($nu === '' || preg_match('/%/', $nu) === 1) {
            return null;
        }
        // Un nombre français ou anglais, éventuellement suivi/précédé d'une monnaie.
        if (preg_match('/^(?<signe>-)?\s*(?<monnaieAvant>[$€]|USD|CDF|FC)?\s*(?<nombre>\d[\d  \x{00A0}\x{202F}.,]*)\s*(?<monnaieApres>[$€]|USD|CDF|FC)?$/u', $nu, $m) !== 1) {
            return null;
        }
        $aMonnaie = ($m['monnaieAvant'] ?? '') !== '' || ($m['monnaieApres'] ?? '') !== '';
        $brut = preg_replace('/[  \x{00A0}\x{202F}]/u', '', $m['nombre']) ?? '';
        // Séparateur décimal : la virgule française, ou le point anglais.
        $decimales = 0;
        if (preg_match('/[.,](\d{1,2})$/', $brut, $d) === 1) {
            $decimales = \strlen($d[1]);
            $brut = substr($brut, 0, -\strlen($d[0]));
        }
        $entier = preg_replace('/[.,]/', '', $brut) ?? '';
        if ($entier === '' || !ctype_digit($entier)) {
            return null;
        }
        if (!$aMonnaie && $decimales !== 2) {
            return null; // ni monnaie ni centimes : ce n'est pas un montant
        }

        $valeur = (float) $entier + ($decimales > 0 ? (float) ($d[1] ?? 0) / (10 ** $decimales) : 0.0);
        if (($m['signe'] ?? '') === '-') {
            $valeur = -$valeur;
        }

        return [$valeur, trim($cellule), $decimales];
    }

    /** @param list<float|int> $connus */
    private static function justifie(float $valeur, int $decimales, array $connus): bool
    {
        foreach ($connus as $connu) {
            if (self::proche($valeur, (float) $connu, $decimales)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deux montants se valent-ils à la précision que le modèle a choisie ? Écrire
     * « 11 383 » pour 11 383,05 est un arrondi, pas une invention.
     */
    private static function proche(float $ecrit, float $connu, int $decimales): bool
    {
        return abs($ecrit - round($connu, $decimales)) < 0.005;
    }
}
