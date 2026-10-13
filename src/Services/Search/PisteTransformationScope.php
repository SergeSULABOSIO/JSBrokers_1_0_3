<?php

namespace App\Services\Search;

/**
 * Périmètre « Statut de transformation » des pistes (opportunités) : critère synthétique
 * porté par la barre de recherche (badge + dialogue avancé + chips de la rubrique Pistes).
 *
 * Une piste est « transformée » dès qu'une de ses cotations est souscrite (porte au moins un
 * avenant = transformée en police), « en cours » sinon — même définition que l'indicateur
 * calculé Piste.statutTransformation (PisteIndicatorStrategy). C'est l'exact pendant, un cran
 * plus haut, du statut de souscription d'une cotation (cf. [[CotationSouscriptionScope]]) : la
 * présence d'un avenant rattaché à l'une des cotations de la piste est EXPRIMABLE EN SQL, donc
 * le filtrage se fait directement en base (EXISTS / NOT EXISTS), via un CAS de
 * JSBDynamicSearchService::applyCriteriaToQueryBuilder — sans service en mémoire ni tri spécial
 * (le tri standard e.id DESC convient). Cette classe centralise le vocabulaire et la traduction
 * valeur → filtre : source unique partagée par les chips de la rubrique et les outils de
 * l'assistant IA (compter_entites / rechercher_entites).
 *
 * ── LES QUATRE ÉTAPES DU PIPE ───────────────────────────────────────────────────
 * Le même critère porte désormais les étapes : À coter (aucune cotation) → En cotation (des
 * cotations, aucune prime chiffrée) → Offre chiffrée (une prime, rien de souscrit) →
 * Transformée. « En cours » réunit les trois premières. Les prédicats SQL vivent dans
 * JSBDynamicSearchService::predicatsDEtapePiste() ; la règle PHP dans {@see etapeDe()} ; les
 * deux lisent le même seuil ({@see SEUIL_PRIME_CHIFFREE}), et un test exige qu'ils
 * rangent chaque piste dans la même étape.
 */
final class PisteTransformationScope
{
    /**
     * Clé de critère synthétique. La valeur transmise est l'un des statuts de VALEURS.
     */
    public const CRITERION_KEY = '__transformation_piste__';

    public const STATUT_TRANSFORMEES = 'transformees';
    public const STATUT_EN_COURS = 'en_cours';

    // ── LES ÉTAPES DU PIPE ─────────────────────────────────────────────────────────
    // Aucune n'est STOCKÉE : chacune se lit sur les cotations de la piste. « En cours »
    // regroupe les trois premières ; « Transformées » est la quatrième.
    public const ETAPE_A_COTER = 'a_coter';
    public const ETAPE_EN_COTATION = 'en_cotation';
    public const ETAPE_OFFRES_CHIFFREES = 'offres_chiffrees';

    /**
     * QUAND UNE PRIME EST-ELLE « CHIFFRÉE » ? Quand la somme des chargements d'une cotation
     * (`montantFlatExceptionel`, la colonne que somme getCotationMontantPrimePayableParClient)
     * atteint ce seuil — c'est-à-dire qu'arrondie au centime elle n'est pas nulle.
     *
     * ⚠ UNE SEULE RÈGLE POUR LE SQL ET LE PHP. DQL n'a pas de ROUND : comparer au demi-
     * centime donne exactement `ROUND(Σ, 2) > 0`, et c'est CETTE constante que lisent le
     * filtre du chip, l'agrégat par étape et l'indicateur calculé de chaque ligne. Une remise
     * qui ramène la somme à zéro laisse donc la piste « en cotation », des deux côtés.
     */
    public const SEUIL_PRIME_CHIFFREE = 0.005;

    /**
     * LA VUE CARTES MONTRE LE PIPE ACTIF : sans les pistes clôturées (depuis le tableau de
     * bord). Critère posé par le serveur seul, jamais par un chip — le pied du Kanban dit
     * combien il en écarte, et « Voir dans la liste » les montre.
     */
    public const CLE_PISTES_OUVERTES = '__pistes_ouvertes__';

    /**
     * @var array<string, string> Valeur du critère => libellé affiché (badge, select du
     *      dialogue avancé, chips), DANS L'ORDRE DU PIPE. « En cours » vient après, parce
     *      qu'il regroupe les trois premières étapes ; « Toutes » est l'absence de valeur.
     */
    public const VALEURS = [
        self::ETAPE_A_COTER => 'À coter',
        self::ETAPE_EN_COTATION => 'En cotation',
        self::ETAPE_OFFRES_CHIFFREES => 'Offres chiffrées',
        self::STATUT_TRANSFORMEES => 'Transformées',
        self::STATUT_EN_COURS => 'En cours',
    ];

    /**
     * LE PIPE, déclaré une fois : ce que chaque étape montre et ce qu'il faut pour la
     * franchir. La liste (marqueur de la sous-ligne), le chip et la vue Cartes en lisent
     * le libellé, l'icône (alias d'IconCanvasProvider) et la couleur (jeton `--etape-*`).
     *
     * @var array<string, array{singulier: string, pluriel: string, icone: string, raison: string}>
     */
    public const ETAPES = [
        self::ETAPE_A_COTER => [
            'singulier' => 'À coter',
            'pluriel' => 'À coter',
            'icone' => 'action:information',
            'raison' => 'aucune cotation n’a encore été demandée',
        ],
        self::ETAPE_EN_COTATION => [
            'singulier' => 'En cotation',
            'pluriel' => 'En cotation',
            'icone' => 'cotation',
            'raison' => 'aucune cotation n’a de prime',
        ],
        self::ETAPE_OFFRES_CHIFFREES => [
            'singulier' => 'Offre chiffrée',
            'pluriel' => 'Offres chiffrées',
            'icone' => 'revenu',
            'raison' => 'aucune cotation n’a encore été souscrite',
        ],
        self::STATUT_TRANSFORMEES => [
            'singulier' => 'Transformée',
            'pluriel' => 'Transformées',
            'icone' => 'action:completed',
            'raison' => '',
        ],
    ];

    /**
     * L'étape d'une piste, à partir de trois faits — la RÈGLE, sans base de données.
     *
     * Évaluée dans l'ordre : une piste souscrite est transformée, même si une autre de ses
     * cotations n'a pas de prime.
     */
    public static function etapeDe(bool $transformee, bool $chiffree, bool $aCotation): string
    {
        return match (true) {
            $transformee => self::STATUT_TRANSFORMEES,
            $chiffree => self::ETAPE_OFFRES_CHIFFREES,
            $aCotation => self::ETAPE_EN_COTATION,
            default => self::ETAPE_A_COTER,
        };
    }

    /** Une somme de chargements est-elle une prime chiffrée ? */
    public static function primeChiffree(float $sommeDesChargements): bool
    {
        return $sommeDesChargements >= self::SEUIL_PRIME_CHIFFREE;
    }

    /** Le rang d'une étape dans le pipe (0 pour « À coter »), ou -1 si ce n'en est pas une. */
    public static function rang(string $etape): int
    {
        $rang = array_search($etape, array_keys(self::ETAPES), true);

        return $rang === false ? -1 : $rang;
    }

    public static function estValide(?string $statut): bool
    {
        return $statut !== null && isset(self::VALEURS[$statut]);
    }

    public static function libelle(string $statut): string
    {
        return self::VALEURS[$statut] ?? $statut;
    }

    /**
     * Fragment de critère à passer au moteur de recherche pour restreindre à un statut de
     * transformation. SOURCE UNIQUE partagée par les chips de la rubrique et les outils
     * génériques de l'assistant IA (compter_entites / rechercher_entites) : le même critère
     * traverse la même interception SQL (EXISTS / NOT EXISTS sur les avenants des cotations de
     * la piste), donc Ket et la barre de chips donnent EXACTEMENT le même résultat. Retourne un
     * tableau vide si l'entité n'est pas Piste ou si le statut est absent/inconnu (le filtre est
     * alors ignoré).
     *
     * @return array<string, array{operator: string, value: string, label: string}>
     */
    public static function critereRecherche(string $entityShortName, ?string $statut): array
    {
        if ($entityShortName !== 'Piste' || !self::estValide($statut)) {
            return [];
        }

        return [self::CRITERION_KEY => [
            'operator' => '=',
            'value' => $statut,
            'label' => self::libelle($statut),
        ]];
    }

    /**
     * Détecte un statut de transformation dans une question en langage naturel déjà normalisée
     * (AiText::normalize : minuscules, sans accents — la ponctuation est CONSERVÉE). Sert au
     * moteur simulé de l'assistant pour que « combien de pistes en cours ? » applique le MÊME
     * filtre que le chip correspondant. Retourne null si aucun statut n'est exprimé.
     */
    public static function detecterDepuisTexte(string $texteNormalise): ?string
    {
        // LES ÉTAPES, D'ABORD : elles sont plus précises que « en cours », qu'elles détaillent.
        if (preg_match('/\b(?:a coter|sans cotation|pas encore cotees?|non cotees?)\b/', $texteNormalise)) {
            return self::ETAPE_A_COTER;
        }
        if (preg_match('/\b(?:offres? chiffrees?|chiffrees?|primes? chiffrees?)\b/', $texteNormalise)) {
            return self::ETAPE_OFFRES_CHIFFREES;
        }
        if (preg_match('/\ben cotation\b/', $texteNormalise)) {
            return self::ETAPE_EN_COTATION;
        }

        // Ordre volontaire : les formulations « en cours » / NÉGATIVES d'abord (« non
        // transformee » contient le mot « transformee » qui piégerait la règle positive).
        if (preg_match('/\b(?:en cours|ouvertes?|actives?|a relancer)\b/', $texteNormalise)
            || preg_match('/\b(?:non|pas|jamais|sans)\b.{0,15}\b(?:transform\w*|souscri\w*|police|gagnees?)\b/', $texteNormalise)
            || preg_match('/\b(?:non transformees?|non souscrites?|non gagnees?|non concluess?)\b/', $texteNormalise)) {
            return self::STATUT_EN_COURS;
        }
        if (preg_match('/\b(?:transformees?|souscri\w*|police[es]?|gagnees?|conclues?)\b/', $texteNormalise)) {
            return self::STATUT_TRANSFORMEES;
        }

        return null;
    }

    /**
     * Fragment de schéma JSON décrivant l'argument `transformation` des outils de l'assistant.
     *
     * SOURCE UNIQUE, même raison que ses deux jumeaux : trois outils, une seule règle.
     *
     * @return array{type: string, enum: string[], description: string}
     */
    public static function proprieteSchema(): array
    {
        return [
            'type' => 'string',
            'enum' => array_keys(self::VALEURS),
            'description' => 'PISTE uniquement. Étape du pipe commercial : '
                . self::ETAPE_A_COTER . ' (aucune cotation), '
                . self::ETAPE_EN_COTATION . ' (des cotations, aucune prime chiffrée), '
                . self::ETAPE_OFFRES_CHIFFREES . ' (une prime chiffrée, rien de souscrit), '
                . self::STATUT_TRANSFORMEES . ' (au moins une cotation souscrite), '
                . self::STATUT_EN_COURS . ' (les trois premières étapes réunies).',
        ];
    }
}
