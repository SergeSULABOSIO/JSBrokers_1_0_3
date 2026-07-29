<?php

namespace App\Services\Search;

/**
 * Périmètre « Statut de souscription » des cotations (propositions) : critère synthétique
 * porté par la barre de recherche (badge + dialogue avancé + chips de la rubrique Propositions).
 *
 * Une cotation est « souscrite » dès qu'elle porte au moins un avenant (= transformée en
 * police) — même définition que l'indicateur calculé Cotation.statutSouscription
 * (IndicatorCalculationHelper::isCotationBound). À la différence du statut de paiement d'une
 * tranche (dérivé, filtré en mémoire), la présence d'un avenant est EXPRIMABLE EN SQL
 * (Avenant.cotation est une vraie relation) : le filtrage se fait donc directement en base
 * (EXISTS / NOT EXISTS), via un CAS de JSBDynamicSearchService::applyCriteriaToQueryBuilder —
 * sans service en mémoire ni tri spécial (le tri standard e.id DESC convient). Cette classe
 * centralise le vocabulaire et la traduction valeur → filtre : source unique partagée par les
 * chips de la rubrique et les outils de l'assistant IA (compter_entites / rechercher_entites).
 */
final class CotationSouscriptionScope
{
    /**
     * Clé de critère synthétique. La valeur transmise est l'un des statuts de VALEURS.
     */
    public const CRITERION_KEY = '__souscription_cotation__';

    public const STATUT_SOUSCRITES = 'souscrites';
    public const STATUT_EN_ATTENTE = 'en_attente';
    public const STATUT_CADUQUES = 'caduques';

    /**
     * Libellés SINGULIERS du statut de souscription d'UNE cotation, repris EXACTEMENT de
     * l'indicateur calculé Cotation.statutSouscription. Source unique partagée par la stratégie
     * d'indicateur (écrans + fiche Ket) et par les items de rechercher_entites : une cotation
     * bound doit s'afficher « Souscrite » PARTOUT, jamais confondue avec une proposition.
     */
    public const STATUT_LABEL_SOUSCRITE = 'Souscrite';
    public const STATUT_LABEL_EN_ATTENTE = 'En attente';

    /**
     * @var array<string, string> Valeur du critère => libellé affiché (badge, select du
     *      dialogue avancé, chips). Le vocabulaire reprend EXACTEMENT l'indicateur calculé
     *      Cotation.statutSouscription (« Souscrite » / « En attente »). « Caduques » désigne
     *      les propositions concurrentes qui ont perdu le marché : NON souscrites, mais une
     *      autre cotation de LA MÊME piste l'est déjà (piste « bound »). Elles ne demandent
     *      plus d'effort commercial ; « En attente » ne les inclut donc plus (partition :
     *      Souscrites ⊎ En attente ⊎ Caduques = toutes les cotations).
     */
    public const VALEURS = [
        self::STATUT_SOUSCRITES => 'Souscrites',
        self::STATUT_EN_ATTENTE => 'En attente',
        self::STATUT_CADUQUES => 'Caduques',
    ];

    public static function estValide(?string $statut): bool
    {
        return $statut !== null && isset(self::VALEURS[$statut]);
    }

    /**
     * Libellé singulier du statut d'UNE cotation d'après sa qualité de bound (au moins un
     * avenant). Même vocabulaire que l'indicateur calculé Cotation.statutSouscription. Source
     * unique : la stratégie d'indicateur et rechercher_entites l'utilisent tous les deux, pour
     * qu'une cotation souscrite ne soit JAMAIS prise pour une proposition en attente.
     */
    public static function statutLibelle(bool $bound): string
    {
        return $bound ? self::STATUT_LABEL_SOUSCRITE : self::STATUT_LABEL_EN_ATTENTE;
    }

    public static function libelle(string $statut): string
    {
        return self::VALEURS[$statut] ?? $statut;
    }

    /**
     * Fragment de critère à passer au moteur de recherche pour restreindre à un statut de
     * souscription. SOURCE UNIQUE partagée par les chips de la rubrique et les outils
     * génériques de l'assistant IA (compter_entites / rechercher_entites) : le même critère
     * traverse la même interception SQL (EXISTS / NOT EXISTS sur les avenants), donc Ket et la
     * barre de chips donnent EXACTEMENT le même résultat. Retourne un tableau vide si l'entité
     * n'est pas Cotation ou si le statut est absent/inconnu (le filtre est alors ignoré).
     *
     * @return array<string, array{operator: string, value: string, label: string}>
     */
    public static function critereRecherche(string $entityShortName, ?string $statut): array
    {
        if ($entityShortName !== 'Cotation' || !self::estValide($statut)) {
            return [];
        }

        return [self::CRITERION_KEY => [
            'operator' => '=',
            'value' => $statut,
            'label' => self::libelle($statut),
        ]];
    }

    /**
     * Détecte un statut de souscription dans une question en langage naturel déjà normalisée
     * (AiText::normalize : minuscules, sans accents — la ponctuation est CONSERVÉE). Sert au
     * moteur simulé de l'assistant pour que « combien de propositions non souscrites ? »
     * applique le MÊME filtre que le chip correspondant. Retourne null si aucun statut n'est
     * exprimé.
     */
    public static function detecterDepuisTexte(string $texteNormalise): ?string
    {
        // Ordre volontaire : la caducité EN PREMIER. « caduque », « sans suite », « perdu(e) »
        // ou « marche attribue » désignent une proposition concurrente hors course — à ne PAS
        // confondre avec « en attente » (qui contient « sans » et piégerait la règle négative
        // ci-dessous).
        if (preg_match('/\b(?:caduque?s?|sans suite|perdue?s?|marche attribue|deja attribue)\b/', $texteNormalise)) {
            return self::STATUT_CADUQUES;
        }
        // Ensuite les formulations NÉGATIVES (« non souscrite », « non transformee » contiennent
        // le mot « souscrite »/« transformee » qui piégerait la règle positive).
        if (preg_match('/\b(?:non|pas|jamais|sans)\b.{0,15}\b(?:souscri\w*|transform\w*|police|validee?s?)\b/', $texteNormalise)
            || preg_match('/\b(?:en attente|a relancer|non validee?s?|non souscrites?|non transformees?)\b/', $texteNormalise)) {
            return self::STATUT_EN_ATTENTE;
        }
        if (preg_match('/\b(?:souscri\w*|transformees?|validee?s?|police[es]?)\b/', $texteNormalise)) {
            return self::STATUT_SOUSCRITES;
        }

        return null;
    }
}
