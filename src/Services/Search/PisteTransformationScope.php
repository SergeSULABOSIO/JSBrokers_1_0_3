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
 */
final class PisteTransformationScope
{
    /**
     * Clé de critère synthétique. La valeur transmise est l'un des statuts de VALEURS.
     */
    public const CRITERION_KEY = '__transformation_piste__';

    public const STATUT_TRANSFORMEES = 'transformees';
    public const STATUT_EN_COURS = 'en_cours';

    /**
     * @var array<string, string> Valeur du critère => libellé affiché (badge, select du
     *      dialogue avancé, chips). Le vocabulaire reprend l'indicateur calculé
     *      Piste.statutTransformation (« Transformée (Souscrite) » / « En cours »).
     */
    public const VALEURS = [
        self::STATUT_TRANSFORMEES => 'Transformées',
        self::STATUT_EN_COURS => 'En cours',
    ];

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
}
