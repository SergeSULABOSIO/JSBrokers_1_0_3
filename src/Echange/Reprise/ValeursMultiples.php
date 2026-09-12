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
 * ── UN NOMBRE NU EST UN TAUX ────────────────────────────────────────────────────────
 *
 *   « Commission »                    le défaut du type s'applique
 *   « Commission = 12 »               un TAUX de 12 %, qui déroge au défaut
 *   « Commission = 12% »              le même, avec son signe — toujours accepté
 *   « Commission = 5000 (forfait) »   un MONTANT fixe, qui déroge au défaut
 *   « Commission = 12% (du risque) »  le taux EFFECTIF, hérité — pour information
 *   « Commission = 10% (du type) »    idem, hérité du type de revenu
 *
 * ⚠ C'EST LE FORFAIT QUI SE DÉCLARE, ET NON LE TAUX. La règle était l'inverse : un
 * nombre nu valait un montant, et le signe pourcent portait seul la nature. Elle se
 * défendait tant que cette colonne ne servait qu'à relire un export — mesuré sur le
 * cabinet réel, 85 revenus sur 150 y dérogent par un MONTANT, contre un seul par un taux.
 *
 * Mais un COURTIER qui remplit ce gabarit n'y écrit jamais un forfait : il y écrit le
 * taux de sa commission. L'aide de la colonne le lui disait d'ailleurs mot pour mot
 * (« Commission = 12 » … « elle est alors EN POINTS ») pendant que la lecture en faisait
 * un forfait de douze unités — deux modes d'emploi contradictoires pour la même case, et
 * c'est la saisie humaine qui en payait le prix.
 *
 * Les 85 forfaits mesurés viennent donc de l'EXPORT, jamais d'une saisie. C'est à
 * l'écriture de les déclarer, pas à l'utilisateur de deviner.
 *
 * ── UNE VALEUR PEUT ÊTRE INFORMATIVE ────────────────────────────────────────────────
 * ⚠ « (du risque) » ET « (du type) » NE SONT PAS DES DÉROGATIONS. Le classeur doit dire
 * à quel taux une affaire tourne réellement — sans quoi le courtier exporte son
 * portefeuille et n'y lit rien. Mais ce taux-là est HÉRITÉ : le recopier comme une
 * dérogation le figerait, et la commission cesserait de suivre le risque le jour où son
 * taux change. Le marqueur porte cette différence, et {@see lire()} la rend dans
 * `source` pour que l'appelant s'abstienne d'écrire quoi que ce soit.
 */
final class ValeursMultiples
{
    /** Entre deux termes. Les espaces sont tolérés à la lecture, posés à l'écriture. */
    public const SEPARATEUR = ' ; ';

    /** Entre un nom et sa valeur. */
    public const AFFECTATION = ' = ';

    /** Une valeur qui DÉROGE : c'est elle qui sera écrite sur le revenu. */
    public const SOURCE_PROPRE = '';

    /** Une valeur HÉRITÉE du risque de l'affaire : informative, jamais recopiée. */
    public const SOURCE_RISQUE = 'risque';

    /** Une valeur HÉRITÉE du type de revenu : informative, jamais recopiée. */
    public const SOURCE_TYPE = 'type';

    /** Ce qui suit la valeur et dit sa nature, ou son origine. */
    private const SUFFIXE_FORFAIT = '(forfait)';
    private const SUFFIXE_RISQUE = '(du risque)';
    private const SUFFIXE_TYPE = '(du type)';

    /**
     * ÉCRIT une composition, de façon que `lire()` la reprenne à l'identique.
     *
     * ⚠ NI SÉPARATEUR DE MILLIERS NI VIRGULE DÉCIMALE À L'ÉCRITURE. « 10 000,50 » se
     * relit sans peine — `lire()` le tolère, parce que c'est ce qu'un utilisateur tape —
     * mais l'écrire nous-mêmes ferait dépendre l'aller-retour d'une interprétation
     * typographique. Ce que nous produisons doit se relire sans rien deviner.
     *
     * @param array<string, array{valeur: float|null, estTaux?: bool, source?: string}> $termes
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
                . (($terme['estTaux'] ?? true) ? '%' : ' ' . self::SUFFIXE_FORFAIT)
                . self::suffixeDeSource($terme['source'] ?? self::SOURCE_PROPRE);
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
     * ⚠ ELLE REND CE QU'ELLE LIT, ELLE NE DÉCIDE RIEN. Une valeur marquée « (du risque) »
     * revient avec sa `source`, valeur comprise : l'aller-retour reste fidèle au
     * caractère près, et c'est à l'appelant de comprendre qu'il n'a rien à écrire. Une
     * lecture qui gommerait la valeur ici ferait perdre au fichier ce qu'il annonce.
     *
     * @param string[] $refus rempli des motifs rencontrés, dans l'ordre de lecture
     *
     * @return array<string, array{valeur: float|null, estTaux: bool, source: string}>
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

            // Les marqueurs partent d'abord : ils suivent le signe pourcent, et
            // `estUnNombre()` refuse tout ce qui n'est pas un nombre nu.
            [$nombre, $source] = self::detacherLaSource($valeurBrute);
            [$nombre, $estTaux] = self::detacherLaNature($nombre);

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
                'source' => $source,
            ];
        }

        return $termes;
    }

    /** Un terme dont la valeur est un montant forfaitaire, et qui déroge. */
    public static function montant(?float $valeur): array
    {
        return ['valeur' => $valeur, 'estTaux' => false, 'source' => self::SOURCE_PROPRE];
    }

    /** Un terme dont la valeur est un taux, EN POINTS (12 = 12 %), et qui déroge. */
    public static function taux(?float $valeur): array
    {
        return ['valeur' => $valeur, 'estTaux' => true, 'source' => self::SOURCE_PROPRE];
    }

    /** Le taux EFFECTIF, hérité du risque de l'affaire : pour information, jamais recopié. */
    public static function tauxDuRisque(?float $valeur): array
    {
        return ['valeur' => $valeur, 'estTaux' => true, 'source' => self::SOURCE_RISQUE];
    }

    /** Le taux EFFECTIF, hérité du type de revenu : pour information, jamais recopié. */
    public static function tauxDuType(?float $valeur): array
    {
        return ['valeur' => $valeur, 'estTaux' => true, 'source' => self::SOURCE_TYPE];
    }

    /** Un terme sans valeur : le défaut du type s'applique. */
    public static function defaut(): array
    {
        return ['valeur' => null, 'estTaux' => true, 'source' => self::SOURCE_PROPRE];
    }

    /**
     * Cette valeur est-elle HÉRITÉE, donc à ne surtout pas recopier en dérogation ?
     *
     * @param array{valeur: float|null, estTaux?: bool, source?: string} $terme
     */
    public static function estInformatif(array $terme): bool
    {
        return ($terme['source'] ?? self::SOURCE_PROPRE) !== self::SOURCE_PROPRE;
    }

    /** Ce qui, après la valeur, dit d'où elle vient. */
    private static function suffixeDeSource(string $source): string
    {
        return match ($source) {
            self::SOURCE_RISQUE => ' ' . self::SUFFIXE_RISQUE,
            self::SOURCE_TYPE => ' ' . self::SUFFIXE_TYPE,
            default => '',
        };
    }

    /**
     * Détache le marqueur d'origine, s'il y en a un.
     *
     * @return array{string, string} la valeur sans son marqueur, et la source
     */
    private static function detacherLaSource(string $valeur): array
    {
        $marqueurs = [
            self::SUFFIXE_RISQUE => self::SOURCE_RISQUE,
            self::SUFFIXE_TYPE => self::SOURCE_TYPE,
        ];

        foreach ($marqueurs as $suffixe => $source) {
            $nu = self::sansSuffixe($valeur, $suffixe);
            if ($nu !== null) {
                return [$nu, $source];
            }
        }

        return [$valeur, self::SOURCE_PROPRE];
    }

    /**
     * Taux ou forfait ? Le nombre nu vaut un TAUX ; seul « (forfait) » dit le contraire.
     *
     * Le signe pourcent reste accepté, et il le doit : tous les classeurs déjà exportés
     * le portent sur leurs taux, et les relire comme des forfaits les détruirait.
     *
     * @return array{string, bool} la valeur sans son marqueur, et « est-ce un taux »
     */
    private static function detacherLaNature(string $valeur): array
    {
        $nu = self::sansSuffixe($valeur, self::SUFFIXE_FORFAIT);
        if ($nu !== null) {
            return [$nu, false];
        }

        return [trim(rtrim($valeur, '%')), true];
    }

    /**
     * La valeur privée de ce suffixe, ou null s'il n'y figure pas.
     *
     * Comparaison insensible à la casse : ce marqueur sera recopié à la main par des
     * utilisateurs, et « (Forfait) » veut dire la même chose.
     */
    private static function sansSuffixe(string $valeur, string $suffixe): ?string
    {
        $longueur = mb_strlen($suffixe);

        if (mb_strtolower(mb_substr($valeur, -$longueur)) !== mb_strtolower($suffixe)) {
            return null;
        }

        return trim(mb_substr($valeur, 0, mb_strlen($valeur) - $longueur));
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
