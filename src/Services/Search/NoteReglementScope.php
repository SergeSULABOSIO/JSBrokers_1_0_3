<?php

namespace App\Services\Search;

/**
 * Périmètre « Règlement » des notes : UN axe, quatre états, porté par les chips de la
 * rubrique Notes et par la barre de recherche (badge retirable + dialogue avancé).
 *
 * ── POURQUOI UN SEUL AXE, LÀ OÙ LA TRANCHE EN A QUATRE ──────────────────────────────
 * Une tranche porte TROIS dettes aux débiteurs différents — la prime due par l'assuré, la
 * commission due par l'assureur, la rétrocommission due par le courtier — plus son
 * échéance. Un statut unique les mélangeait, et le même mot « impayées » a fini par
 * désigner deux choses (incident du 2026-08-05).
 *
 * Une NOTE n'a qu'une dette : la sienne, envers son seul destinataire. Lui inventer
 * plusieurs axes créerait des distinctions qui n'existent pas. Le modèle est donc suivi
 * fidèlement, pas étendu.
 *
 * ── LA CLASSIFICATION VIT ICI, ET NULLE PART AILLEURS ───────────────────────────────
 * {@see statut()} est la SOURCE UNIQUE. Elle était écrite deux fois dans
 * `NoteIndicatorStrategy` — en `if` pour les notes à articles, en `match` pour les notes de
 * bordereau —, et la première ignorait le chemin du bordereau : appelée seule sur une note
 * de bordereau, elle lisait des articles vides et répondait « N/A » ou « Payée » à tort.
 * Deux copies d'une même règle finissent toujours par diverger.
 *
 * ── CE STATUT N'EST PAS FILTRABLE EN SQL ────────────────────────────────────────────
 * `Article` ne persiste aucun montant — seulement une quantité. Le montant d'une ligne
 * passe par `getArticleMontant()`, qui branche sur le type et le destinataire de la note,
 * le taux IARD ou VIE de la taxe, la fraction de l'échéance et les rétrocommissions. Le
 * solde en hérite.
 *
 * Le critère est donc intercepté par {@see \App\Services\JSBDynamicSearchService} qui
 * bascule sur un filtrage en mémoire ({@see \App\Services\Note\NoteReglementService}),
 * exactement comme {@see TranchePaiementScope}. Le chemin SQL d'Avenant ou de Cotation
 * nous est fermé.
 */
final class NoteReglementScope
{
    /** Clé de critère synthétique. Le préfixe `__` dit : ceci n'est pas une colonne. */
    public const CRITERION_KEY = '__reglement_note__';

    public const REGLEE = 'reglee';
    public const PARTIELLE = 'partielle';
    public const IMPAYEE = 'impayee';

    /**
     * Une note qui ne réclame RIEN : ni due, ni payée. C'est presque toujours une note dont
     * les lignes n'ont pas encore été saisies. Elle n'est ni réglée ni impayée — la ranger
     * sous « réglées » gonflerait le compte de ce qui a été encaissé.
     */
    public const SANS_MONTANT = 'sans_montant';

    /**
     * En deçà d'un centime, un écart relève de l'arrondi comptable et non d'une créance.
     *
     * ⚠ TROIS SEUILS COHABITAIENT : `SourceDeFacturation::SEUIL_SOLDE` (0,01),
     * `NoteRecouvrementService::SEUIL_SOLDE` (0,005) et un `> 0.01` dans
     * `NoteIndicatorStrategy::aUnSoldeDu()`, sous un commentaire affirmant qu'il partageait
     * le premier. Une note à 0,007 pouvait donc être « impayée » pour l'un et soldée pour
     * l'autre. Celui-ci est désormais le seul que la classification consulte.
     */
    public const SEUIL = 0.01;

    /**
     * SOURCE UNIQUE des états : alimente les chips, le dialogue de recherche avancée et les
     * tests. L'ordre est celui de présentation — du plus urgent au moins actionnable.
     *
     * Trois jeux de libellés, et chacun a sa raison :
     *  - `libelle`   : le libellé COMPLET, employé par le badge de recherche, où le critère
     *                  apparaît isolé. « Partielles » seul n'y dirait pas de quoi il s'agit ;
     *  - `court`     : le libellé du CHIP, où le critère est déjà écrit en tête du groupe.
     *                  Y répéter « Note » sur chaque bouton doublerait leur largeur ;
     *  - `affichage` : le mot porté par la colonne « Statut » de chaque ligne. Il existait
     *                  AVANT ce lot et ne change pas : le renommer serait une régression
     *                  visuelle que personne n'a demandée.
     *
     * Les icônes sont celles des dettes de Tranche — « soldé », « entamé », « dû » doivent
     * se reconnaître d'une rubrique à l'autre. Aucun alias n'est créé : toutes existent déjà
     * dans {@see \App\Services\Canvas\Provider\Icon\IconCanvasProvider}.
     *
     * @var array<string, array{libelle: string, court: string, affichage: string, icone: string}>
     */
    public const ETATS = [
        self::IMPAYEE => [
            'libelle' => 'Note impayée',
            'court' => 'Impayées',
            'affichage' => 'Impayée',
            'icone' => 'action:alert',
        ],
        self::PARTIELLE => [
            'libelle' => 'Note partiellement réglée',
            'court' => 'Partielles',
            'affichage' => 'Partiel',
            'icone' => 'action:ongoing',
        ],
        self::REGLEE => [
            'libelle' => 'Note réglée à 100 %',
            'court' => 'Réglées',
            'affichage' => 'Payée',
            'icone' => 'action:completed',
        ],
        self::SANS_MONTANT => [
            'libelle' => 'Note sans montant',
            'court' => 'Sans montant',
            'affichage' => 'N/A',
            // Une croix plutôt qu'un dessin de somme : il n'y a rien à encaisser, et c'est
            // précisément ce qui range cette note hors des trois autres états.
            'icone' => 'action:cancel',
        ],
    ];

    /** Libellé et icône du groupe de chips, et du critère isolé dans la barre de recherche. */
    public const LIBELLE_AXE = 'Règlement de la note';
    public const TITRE_CHIPS = 'Règlement';
    public const ICONE_AXE = 'paiement';

    /**
     * LA RÈGLE DE CLASSEMENT, en un seul endroit.
     *
     * Fonction PURE : elle ne connaît ni l'entité, ni Doctrine, ni la façon dont les deux
     * montants ont été obtenus. C'est ce qui permet à `NoteIndicatorStrategy` de l'appeler
     * depuis ses DEUX chemins de calcul — notes à articles et notes de bordereau — et au
     * filtre de la rubrique de s'y fier sans recalculer quoi que ce soit.
     *
     * L'ordre des tests compte : « rien à réclamer » se juge AVANT « tout est payé », sinon
     * une note à zéro, dont le payé est trivialement supérieur ou égal au dû, passerait pour
     * réglée et gonflerait le compte de l'encaissement.
     */
    public static function statut(float $montantTotal, float $montantPaye): string
    {
        $du = round($montantTotal, 2);
        $paye = round($montantPaye, 2);

        if (abs($du) < self::SEUIL && abs($paye) < self::SEUIL) {
            return self::SANS_MONTANT;
        }
        if ($paye >= $du - self::SEUIL) {
            return self::REGLEE;
        }
        if ($paye >= self::SEUIL) {
            return self::PARTIELLE;
        }

        return self::IMPAYEE;
    }

    /** Reste-t-il quelque chose à encaisser ? Dérivé du classement, jamais d'un second seuil. */
    public static function resteADue(float $montantTotal, float $montantPaye): bool
    {
        return in_array(self::statut($montantTotal, $montantPaye), [self::IMPAYEE, self::PARTIELLE], true);
    }

    public static function estValide(?string $valeur): bool
    {
        return $valeur !== null && isset(self::ETATS[$valeur]);
    }

    /** Libellé complet — badge de recherche, dialogue avancé, réponses de l'assistant. */
    public static function libelle(string $valeur): string
    {
        return self::ETATS[$valeur]['libelle'] ?? $valeur;
    }

    /**
     * Libellé destiné aux CHIPS, où le critère est déjà écrit en tête du groupe. À n'employer
     * nulle part ailleurs : isolé, « Partielles » ne dit pas de quoi il s'agit.
     */
    public static function libelleCourt(string $valeur): string
    {
        return self::ETATS[$valeur]['court'] ?? self::libelle($valeur);
    }

    /** Le mot de la colonne « Statut » d'une ligne. Inchangé depuis toujours. */
    public static function libelleAffichage(string $valeur): string
    {
        return self::ETATS[$valeur]['affichage'] ?? $valeur;
    }

    /** @return array<string, string> valeur => libellé complet, pour le dialogue de recherche */
    public static function valeurs(): array
    {
        return array_map(static fn (array $etat): string => $etat['libelle'], self::ETATS);
    }

    /**
     * Fragment de critères pour le moteur de recherche. SOURCE UNIQUE partagée par le chip
     * initial de la rubrique et tout appelant qui voudrait le même filtre : les mêmes
     * critères traversent la même interception, donc deux surfaces ne peuvent pas diverger.
     *
     * Rend un tableau vide si l'entité n'est pas Note ou si la valeur est inconnue — un
     * filtre qu'on ne sait pas appliquer ne doit pas être annoncé.
     *
     * @return array<string, array{operator: string, value: string, label: string}>
     */
    public static function critereRecherche(string $entityShortName, ?string $valeur): array
    {
        if ($entityShortName !== 'Note' || !self::estValide($valeur)) {
            return [];
        }

        return [self::CRITERION_KEY => [
            'operator' => '=',
            'value' => $valeur,
            'label' => self::libelle($valeur),
        ]];
    }

    /**
     * La valeur portée par un jeu de critères, ou null si elle est absente ou inconnue.
     *
     * @param array<string, mixed> $criteria
     */
    public static function extraireValeur(array $criteria): ?string
    {
        if (!array_key_exists(self::CRITERION_KEY, $criteria)) {
            return null;
        }

        $brut = $criteria[self::CRITERION_KEY];
        $valeur = is_array($brut) ? (string) ($brut['value'] ?? '') : (string) $brut;

        return self::estValide($valeur) ? $valeur : null;
    }

    /**
     * Le jeu de critères porte-t-il la clé — même vide, même invalide ?
     *
     * ⚠ C'est cette question, et non « la valeur est-elle valide ? », qui décide que le
     * moteur doit RETIRER la clé. `__reglement_note__` n'est pas une colonne : la laisser
     * passer au chemin SQL ferait lever Doctrine sur un champ inconnu.
     *
     * @param array<string, mixed> $criteria
     */
    public static function porteLeCritere(array $criteria): bool
    {
        return array_key_exists(self::CRITERION_KEY, $criteria);
    }

    /**
     * Retire la clé synthétique d'un jeu de critères.
     *
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    public static function retirerCritere(array $criteria): array
    {
        unset($criteria[self::CRITERION_KEY]);

        return $criteria;
    }
}
