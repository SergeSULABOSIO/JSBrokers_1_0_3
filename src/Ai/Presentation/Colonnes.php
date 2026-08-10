<?php

namespace App\Ai\Presentation;

/**
 * LE RÔLE D'UNE COLONNE — la seule chose qu'un outil doit dire sur sa présentation,
 * et la source unique dont dépendent les trois consommateurs d'une liste.
 *
 * POURQUOI CE PETIT OBJET EXISTE. Un outil sait mieux que quiconque ce que ses clés
 * veulent dire : « montant » est de l'argent, « date » une date, « soldePrime » un
 * nombre, « trancheId » un numéro qu'on n'additionne jamais. Cette connaissance était
 * jusqu'ici REDEVINÉE trois fois, et de trois façons différentes : par le repli PHP
 * (heuristique sur le type et le suffixe « Id »), par le modèle au moment de rédiger
 * (au jugé), et par personne du tout côté navigateur — où l'alignement écrit en
 * markdown était purement et simplement jeté. Résultat mesuré sur la capture du
 * 2026-08-10 : des montants alignés à gauche, des dates au format ISO
 * (« 2026-08-05 ») et une ligne de totaux qui ne se distinguait d'aucune autre.
 *
 * CE QU'ON FAIT À LA PLACE. L'outil déclare ses rôles UNE fois, dans son résultat, et
 * cette déclaration sert : au modèle (le contrat de présentation du prompt lui dit de
 * s'y conformer), au repli PHP (TableauMarkdown rend le même tableau, à coût nul), et
 * à l'export documentaire. Coût : une centaine d'octets par liste, contre plusieurs
 * centaines de tokens qu'aurait coûté un tableau pré-rendu renvoyé au modèle.
 *
 * CE N'EST PAS UNE MISE EN FORME, C'EST UNE DÉCLARATION DE SENS. Aucune couleur,
 * aucune largeur, aucun libellé d'affichage ici : le rendu appartient à
 * TableauMarkdown côté serveur et au CSS côté navigateur.
 */
final class Colonnes
{
    /** Texte libre : un nom, une référence, une description. */
    public const TEXTE = 'texte';

    /** De l'argent. Aligné à droite, symbole monétaire, deux décimales, s'additionne. */
    public const MONTANT = 'montant';

    /** Une quantité (un compte, un nombre de jours). Aligné à droite, s'additionne. */
    public const NOMBRE = 'nombre';

    /** Un taux en POINTS (16 = 16 %). Aligné à droite, ne s'additionne JAMAIS. */
    public const POURCENTAGE = 'pourcentage';

    /** Une date. Rendue en jj/mm/aaaa, jamais en ISO. */
    public const DATE = 'date';

    /** Un statut, déjà porteur de sa pastille le cas échéant. */
    public const STATUT = 'statut';

    /** Un numéro d'enregistrement : brut, sans séparateur de milliers, jamais sommé. */
    public const IDENTIFIANT = 'identifiant';

    public const ROLES = [
        self::TEXTE,
        self::MONTANT,
        self::NOMBRE,
        self::POURCENTAGE,
        self::DATE,
        self::STATUT,
        self::IDENTIFIANT,
    ];

    /**
     * Rôles dont la colonne s'aligne à DROITE (`---:` en GFM). L'œil compare des
     * chiffres par leur unité : à gauche, il doit relire chaque ligne.
     */
    public const ROLES_ALIGNES_A_DROITE = [self::MONTANT, self::NOMBRE, self::POURCENTAGE];

    /**
     * Rôles qu'on peut additionner d'office. Le pourcentage en est exclu : la somme de
     * deux taux n'est pas un taux, et l'afficher ferait douter du reste du tableau.
     */
    public const ROLES_SOMMABLES = [self::MONTANT, self::NOMBRE];

    /**
     * Déclaration à poser dans le résultat d'un outil, sous la clé « presentation ».
     *
     * @param array<string, string> $roles     clé de ligne => rôle, DANS L'ORDRE d'affichage
     * @param list<string>|null     $totaliser clés à sommer en dernière ligne ; par défaut
     *                                         les seules colonnes de MONTANT — un total de
     *                                         quantités est rarement voulu, et un total faux
     *                                         est pire qu'un total absent
     *
     * @return array{colonnes: array<string, string>, totaliser: list<string>}
     */
    public static function de(array $roles, ?array $totaliser = null): array
    {
        $colonnes = [];
        foreach ($roles as $cle => $role) {
            $colonnes[(string) $cle] = \in_array($role, self::ROLES, true) ? (string) $role : self::TEXTE;
        }

        if ($totaliser === null) {
            $totaliser = array_keys(array_filter(
                $colonnes,
                static fn (string $role) => $role === self::MONTANT,
            ));
        }

        // Ne jamais promettre un total sur une colonne absente ou non sommable : le
        // contrat de présentation en ferait une ligne vide, et le modèle une invention.
        $totaliser = array_values(array_filter(
            array_map('strval', $totaliser),
            static fn (string $cle) => isset($colonnes[$cle])
                && \in_array($colonnes[$cle], self::ROLES_SOMMABLES, true),
        ));

        return ['colonnes' => $colonnes, 'totaliser' => $totaliser];
    }

    /**
     * Rôle DÉDUIT d'une clé et d'une valeur, pour les listes dont l'outil ne déclare
     * encore rien.
     *
     * Volontairement TIMIDE : on ne déduit jamais MONTANT. Deviner qu'un « soldePrime »
     * est de l'argent conduirait à lui coller un symbole monétaire — donc à afficher
     * « 4 000,50 $ » là où l'outil n'a jamais dit dans quelle monnaie il comptait. Un
     * nombre bien aligné sans unité est honnête ; un nombre avec la mauvaise unité ne
     * l'est pas. Déclarer le rôle (self::de) est le seul moyen d'obtenir MONTANT.
     */
    public static function roleDeduit(string $cle, mixed $valeur): string
    {
        if ($cle === 'id' || str_ends_with($cle, 'Id') || str_ends_with($cle, 'ID')) {
            return self::IDENTIFIANT;
        }
        if (\is_int($valeur) || \is_float($valeur)) {
            return self::NOMBRE;
        }
        if (\is_string($valeur) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur) === 1) {
            return self::DATE;
        }

        return self::TEXTE;
    }

    public static function estAlignéeADroite(string $role): bool
    {
        return \in_array($role, self::ROLES_ALIGNES_A_DROITE, true);
    }
}
