<?php

namespace App\Services\Search;

/**
 * Périmètre « Échéance » des avenants : critère synthétique porté par la barre de recherche
 * (badge + dialogue avancé + chips de la liste Avenants).
 *
 * Contrairement au statut de paiement d'une tranche (dérivé à la volée, filtré en mémoire),
 * l'échéance d'un avenant est une VRAIE colonne persistée (Avenant.endingAt) : le filtrage et
 * le tri se font donc directement en SQL (cf. JSBDynamicSearchService), sans service en
 * mémoire. Cette classe centralise les seuils de fenêtres temporelles — source unique partagée
 * par le filtre SQL (bornes()) et le badge d'urgence par ligne (classifier()).
 */
final class AvenantEcheanceScope
{
    /**
     * Clé de critère synthétique. La valeur transmise est l'un des statuts de VALEURS.
     */
    public const CRITERION_KEY = '__echeance_avenant__';

    public const STATUT_ECHUS = 'echus';
    public const STATUT_30J = 'sous_30j';
    public const STATUT_31_60J = 'de_31_a_60j';
    public const STATUT_60_PLUS = 'au_dela_60j';

    /**
     * @var array<string, string> Valeur du critère => libellé affiché (badge, select du
     *      dialogue avancé, chips). L'ordre est celui de présentation, du plus urgent au moins
     *      urgent (les avenants déjà échus sont la priorité absolue de traitement).
     */
    public const VALEURS = [
        self::STATUT_ECHUS => 'Échus',
        self::STATUT_30J => 'Sous 30 jours',
        self::STATUT_31_60J => '31 à 60 jours',
        self::STATUT_60_PLUS => 'Au-delà de 60 jours',
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
     * Fragment de critère à passer au moteur de recherche pour restreindre à une fenêtre
     * d'échéance. SOURCE UNIQUE partagée par les chips de la rubrique et les outils de
     * l'assistant IA (compter_entites / rechercher_entites) : le même critère traverse la
     * même interception SQL, donc Ket et la barre de chips donnent EXACTEMENT le même
     * résultat. Retourne un tableau vide si l'entité n'est pas Avenant ou si le statut est
     * absent/inconnu (le filtre est alors simplement ignoré).
     *
     * @return array<string, array{operator: string, value: string, label: string}>
     */
    public static function critereRecherche(string $entityShortName, ?string $statut): array
    {
        if ($entityShortName !== 'Avenant' || !self::estValide($statut)) {
            return [];
        }

        return [self::CRITERION_KEY => [
            'operator' => '=',
            'value' => $statut,
            'label' => self::libelle($statut),
        ]];
    }

    /**
     * Détecte une fenêtre d'échéance dans une question en langage naturel déjà normalisée
     * (AiText::normalize : minuscules, sans accents). Sert au moteur simulé de l'assistant
     * pour que « combien d'avenants échoient dans les 30 jours ? » applique le MÊME filtre
     * que le chip correspondant. Retourne null si aucune fenêtre n'est exprimée.
     */
    public static function detecterDepuisTexte(string $texteNormalise): ?string
    {
        // Ordre volontaire : les formulations les plus spécifiques d'abord (« au-delà de
        // 60 » et « 31 à 60 » contiennent des nombres qui piégeraient la règle des 30 jours).
        if (preg_match('/\b(echus?|echue?s?|expires?|expirees?|perimes?|depasses?)\b/', $texteNormalise)) {
            return self::STATUT_ECHUS;
        }
        // « au-delà » / « au delà » : AiText::normalize retire les accents mais CONSERVE la
        // ponctuation — le trait d'union doit donc être accepté au même titre que l'espace.
        if (preg_match('/\b(?:au[- ]dela|plus) (?:de |des )?(?:60|61|soixante)\b/', $texteNormalise)) {
            return self::STATUT_60_PLUS;
        }
        if (preg_match('/\b31 (?:a|et) 60\b|\bentre 31 et 60\b/', $texteNormalise)) {
            return self::STATUT_31_60J;
        }
        if (preg_match('/\b(?:30|trente) (?:prochains? )?jours?\b|\b(?:sous|dans) (?:les )?(?:30|trente)\b/', $texteNormalise)) {
            return self::STATUT_30J;
        }

        return null;
    }

    /**
     * Bornes de la fenêtre `endingAt` pour un statut donné, calculées à minuit à partir de la
     * date de référence (évite l'off-by-one : l'échéance est ramenée au jour). Convention
     * [min, max[ (max exclusif). `null` = borne ouverte.
     *
     * @return array{min: ?\DateTimeImmutable, max: ?\DateTimeImmutable}
     */
    public static function bornes(string $statut, \DateTimeImmutable $ref): array
    {
        $jour = $ref->setTime(0, 0, 0);

        return match ($statut) {
            self::STATUT_ECHUS => ['min' => null, 'max' => $jour],
            self::STATUT_30J => ['min' => $jour, 'max' => $jour->modify('+31 days')],
            self::STATUT_31_60J => ['min' => $jour->modify('+31 days'), 'max' => $jour->modify('+61 days')],
            self::STATUT_60_PLUS => ['min' => $jour->modify('+61 days'), 'max' => null],
            default => ['min' => null, 'max' => null],
        };
    }

    /**
     * Classe une date d'échéance dans sa fenêtre d'urgence (statut + niveau CSS + libellé du
     * badge). Source unique du badge par ligne, alignée sur les mêmes bornes que bornes().
     * Retourne `null` si l'avenant n'a pas d'échéance (aucun badge rendu).
     *
     * @return array{statut: string, niveau: string, libelle: string}|null
     */
    public static function classifier(?\DateTimeImmutable $endingAt, \DateTimeImmutable $ref): ?array
    {
        if ($endingAt === null) {
            return null;
        }

        $jour = $ref->setTime(0, 0, 0);
        $echeance = $endingAt->setTime(0, 0, 0);

        if ($echeance < $jour) {
            $jours = (int) $jour->diff($echeance)->format('%a');
            return [
                'statut' => self::STATUT_ECHUS,
                'niveau' => 'critique',
                'libelle' => sprintf('Expiré depuis %d j', $jours),
            ];
        }

        $jours = (int) $jour->diff($echeance)->format('%a');
        if ($echeance < $jour->modify('+31 days')) {
            $niveau = 'elevee';
            $statut = self::STATUT_30J;
        } elseif ($echeance < $jour->modify('+61 days')) {
            $niveau = 'moderee';
            $statut = self::STATUT_31_60J;
        } else {
            $niveau = 'faible';
            $statut = self::STATUT_60_PLUS;
        }

        return [
            'statut' => $statut,
            'niveau' => $niveau,
            'libelle' => $jours === 0 ? "Échéance aujourd'hui" : sprintf('Échéance dans %d j', $jours),
        ];
    }
}
