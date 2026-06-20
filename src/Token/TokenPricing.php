<?php

namespace App\Token;

use App\Entity\Avenant;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Feedback;
use App\Entity\Piste;
use App\Entity\Tache;

/**
 * @file Référence du modèle de facturation à base de TOKENS de JS Brokers.
 * @description Source de vérité unique pour le métrage et la tarification :
 *  - poids des entités en écriture (entrée) et en lecture (sortie) ;
 *  - allocation gratuite renouvelable ;
 *  - paquets prépayés cumulables ;
 *  - taux de conversion token → USD (informatif).
 *
 * Classe dédiée et volontairement minimale (même esprit que App\Legal\Cgu) :
 * on n'alourdit pas la god-class App\Constantes\Constante.
 */
final class TokenPricing
{
    /**
     * Poids en tokens d'une entité lors d'une ÉCRITURE (création ou édition).
     * Toute entité non listée ici vaut DEFAULT_WRITE_WEIGHT.
     */
    public const WRITE_WEIGHTS = [
        Entreprise::class => 200,
        Avenant::class    => 100,
        Cotation::class   => 50,
        Piste::class      => 20,
        Tache::class      => 10,
        Feedback::class   => 8,
    ];

    /** Poids par défaut en écriture pour toute entité non explicitement listée. */
    public const DEFAULT_WRITE_WEIGHT = 5;

    /** Poids en tokens d'une entité envoyée vers le frontend (LECTURE / sortie). */
    public const READ_WEIGHT = 2;

    /** Allocation gratuite offerte à chaque utilisateur, par fenêtre. */
    public const FREE_ALLOWANCE = 1000;

    /** Durée de validité (en heures) de l'allocation gratuite avant renouvellement. */
    public const FREE_WINDOW_HOURS = 8;

    /**
     * Paquets prépayés cumulables. Clé = identifiant technique du paquet.
     *  - tokens : nombre de tokens crédités ;
     *  - price  : prix en USD.
     */
    public const PACKS = [
        'intermediaire' => ['tokens' => 10000, 'price' => 10],
        'professionnel' => ['tokens' => 50000, 'price' => 40],
    ];

    /**
     * Taux de référence (configurable) pour estimer le coût en USD d'une
     * consommation de tokens. Défaut = taux du paquet Intermédiaire
     * (10 $ / 10 000 tokens = 0,001 $/token). Modifier ici suffit : le coût
     * n'est jamais stocké, il est toujours recalculé à l'affichage.
     */
    public const USD_PER_TOKEN = 0.001;

    /**
     * Retourne le poids en écriture d'une entité (par son FQCN), avec repli
     * sur le poids par défaut.
     */
    public static function weightFor(string $fqcn): int
    {
        return self::WRITE_WEIGHTS[$fqcn] ?? self::DEFAULT_WRITE_WEIGHT;
    }

    /** Convertit un nombre de tokens consommés en coût USD selon le taux de référence. */
    public static function costUsd(int $tokens): float
    {
        return $tokens * self::USD_PER_TOKEN;
    }

    /** Retourne la définition d'un paquet ou null s'il n'existe pas. */
    public static function pack(string $key): ?array
    {
        return self::PACKS[$key] ?? null;
    }
}
