<?php

namespace App\Echange\Reprise;

use App\Services\Bordereau\BordereauLigneNormaliseur;

/**
 * UNE CELLULE QUI PORTE PLUSIEURS TERMES : « Prime nette = 10000 ; Accessoires = 500 ».
 *
 * ── POURQUOI CETTE CONVENTION EXISTE ────────────────────────────────────────────────
 * Une ligne du classeur décrit UNE TRANCHE. Or la prime d'une cotation se décompose en
 * plusieurs chargements, et sa rémunération en plusieurs types de revenu : des grandeurs
 * à cardinalité N, qu'une ligne plate ne peut pas porter en colonnes — leur nombre n'est
 * pas connu à l'avance.
 *
 * ⚠ CE N'EST PAS UNE ENTORSE À « UNE GRANDEUR = UNE COLONNE ». Cette règle interdit de
 * mettre deux NOTIONS dans une case (« 1 200 / 800 / 400 »), parce qu'une telle case ne
 * se totalise ni ne se trie plus. Ici la case porte UNE notion — la composition — dont
 * les termes sont énumérés. Elle ne se totalise pas davantage qu'un nom de client, et
 * c'est pourquoi le total de la prime a, lui, sa propre colonne.
 *
 * Le séparateur `;` est celui que le format d'échange emploie déjà pour les cellules
 * multi-valeurs ({@see \App\Echange\Canevas\ColonneDEchange::$multiple}) : un second
 * serait un second à apprendre.
 *
 * ── TROIS FORMES, ET AUCUNE N'EST DEVINÉE ───────────────────────────────────────────
 *
 *   « Commission »          le défaut du type s'applique
 *   « Commission = 12% »    un TAUX, en points, qui déroge au défaut
 *   « Commission = 5000 »   un MONTANT fixe, qui déroge au défaut
 *
 * ⚠ LE SIGNE « % » N'EST PAS DÉCORATIF, IL PORTE LA NATURE. Un revenu déroge soit par
 * son taux, soit par un montant forfaitaire, et rien dans le nombre lui-même ne dit
 * lequel : « 12 » est un taux plausible autant qu'un montant plausible. Mesuré sur les
 * données réelles du cabinet, la dérogation par MONTANT est même le cas dominant —
 * 85 revenus sur 150, contre un seul par taux. Deviner d'après l'ordre de grandeur aurait
 * donc échoué sur la majorité des lignes, et silencieusement.
 *
 * ⚠ ET UN TERME SANS VALEUR N'EST PAS UNE OMISSION. Un taux de revenu ne se recopie
 * pas : il se résout à la lecture, en cascade, un type marqué « pourcentage du risque »
 * allant chercher celui du risque de l'affaire (voir
 * {@see \App\Ai\Proposition\RevenuCourtierPrescrit}). Écrire le taux dans le fichier le
 * FIGERAIT : la commission ne suivrait plus le risque le jour où son taux change.
 */
final class ValeursMultiples
{
    /** Entre deux termes. Les espaces sont tolérés à la lecture, posés à l'écriture. */
    public const SEPARATEUR = ' ; ';

    /** Entre un nom et sa valeur. */
    public const AFFECTATION = ' = ';

    /**
     * ÉCRIT une composition, de façon que `lire()` la reprenne à l'identique.
     *
     * ⚠ NI SÉPARATEUR DE MILLIERS NI VIRGULE DÉCIMALE À L'ÉCRITURE. « 10 000,50 » se
     * relit sans peine — `lire()` le tolère, parce que c'est ce qu'un utilisateur tape —
     * mais l'écrire nous-mêmes ferait dépendre l'aller-retour d'une interprétation
     * typographique. Ce que nous produisons doit se relire sans rien deviner.
     *
     * @param array<string, array{valeur: float|null, estTaux?: bool}> $termes
     */
    public static function ecrire(array $termes): string
    {
        $morceaux = [];

        foreach ($termes as $nom => $terme) {
            $nom = trim((string) $nom);
            if ($nom === '') {
                continue;
            }

            $valeur = $terme['valeur'] ?? null;
            if ($valeur === null) {
                $morceaux[] = $nom;
                continue;
            }

            $morceaux[] = $nom . self::AFFECTATION . self::nombre((float) $valeur)
                . (($terme['estTaux'] ?? false) ? '%' : '');
        }

        return implode(self::SEPARATEUR, $morceaux);
    }

    /**
     * LIT une composition, en signalant ce qu'elle ne sait pas lire.
     *
     * ⚠ UN TERME ILLISIBLE EST UN REFUS, JAMAIS UN SILENCE. Ignorer « Prime nette = abc »
     * écrirait une cotation à laquelle il manque un chargement : une prime fausse,
     * d'aspect parfaitement normal, que rien à l'écran ne signalerait. Le motif nomme le
     * terme fautif, pour qu'on sache quoi corriger.
     *
     * @param string[] $refus rempli des motifs rencontrés, dans l'ordre de lecture
     *
     * @return array<string, array{valeur: float|null, estTaux: bool}>
     */
    public static function lire(?string $cellule, array &$refus = []): array
    {
        $cellule = trim((string) $cellule);
        if ($cellule === '') {
            return [];
        }

        $termes = [];

        foreach (explode(';', $cellule) as $brut) {
            $brut = trim($brut);
            if ($brut === '') {
                continue;
            }

            $position = mb_strpos($brut, '=');
            $nom = trim($position === false ? $brut : mb_substr($brut, 0, $position));
            $valeurBrute = $position === false ? null : trim(mb_substr($brut, $position + 1));

            if ($nom === '') {
                $refus[] = sprintf('« %s » n\'indique pas de quoi il s\'agit avant le signe « = ».', $brut);
                continue;
            }

            // ⚠ UN MÊME TERME DEUX FOIS EST UNE FAUTE DE FRAPPE, PAS UNE INTENTION.
            // « Prime nette = 100 ; Prime nette = 200 », c'est deux primes pour une : la
            // seconde écraserait la première en silence, et le total serait faux.
            if (array_key_exists($nom, $termes)) {
                $refus[] = sprintf('« %s » revient deux fois dans la même cellule.', $nom);
                continue;
            }

            if ($valeurBrute === null || $valeurBrute === '') {
                $termes[$nom] = self::defaut();
                continue;
            }

            $estTaux = str_ends_with($valeurBrute, '%');
            $nombre = $estTaux ? trim(rtrim($valeurBrute, '%')) : $valeurBrute;

            if (!self::estUnNombre($nombre)) {
                $refus[] = sprintf('« %s » attend un nombre, et porte « %s ».', $nom, $valeurBrute);
                continue;
            }

            // ⚠ SOURCE UNIQUE DU NETTOYAGE. « 1.234.567,89 », « 9 000 » : la même
            // typographie approximative qu'un bordereau Excel, et
            // `BordereauLigneNormaliseur` se déclare source unique de son interprétation.
            // En redire une seconde ici, ce serait s'engager à la maintenir deux fois.
            $termes[$nom] = [
                'valeur' => BordereauLigneNormaliseur::nettoyerNombre($nombre),
                'estTaux' => $estTaux,
            ];
        }

        return $termes;
    }

    /** Un terme dont la valeur est un montant. */
    public static function montant(?float $valeur): array
    {
        return ['valeur' => $valeur, 'estTaux' => false];
    }

    /** Un terme dont la valeur est un taux, EN POINTS (12 = 12 %). */
    public static function taux(?float $valeur): array
    {
        return ['valeur' => $valeur, 'estTaux' => true];
    }

    /** Un terme sans valeur : le défaut du type s'applique. */
    public static function defaut(): array
    {
        return ['valeur' => null, 'estTaux' => false];
    }

    /** Sans zéros inutiles : « 500 » plutôt que « 500.00 », qui annonce une précision qu'on n'a pas. */
    private static function nombre(float $valeur): string
    {
        $texte = rtrim(rtrim(number_format($valeur, 2, '.', ''), '0'), '.');

        return $texte === '' || $texte === '-' ? '0' : $texte;
    }

    /**
     * La valeur ressemble-t-elle à un nombre, une fois sa typographie admise ?
     *
     * On contrôle AVANT de nettoyer : `nettoyerNombre` rend `0.0` pour « abc », et un
     * zéro se présente comme une valeur alors que c'est un échec de lecture.
     */
    private static function estUnNombre(string $valeur): bool
    {
        $nu = str_replace([' ', "\u{00A0}", "\u{202F}", '.', ','], '', $valeur);

        return $nu !== '' && preg_match('/^-?\d+$/', $nu) === 1;
    }
}
