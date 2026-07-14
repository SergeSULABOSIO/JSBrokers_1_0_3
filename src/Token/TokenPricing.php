<?php

namespace App\Token;

use App\Entity\AssistantMessage;
use App\Entity\Avenant;
use App\Entity\ChargeCourtier;
use App\Entity\Cotation;
use App\Entity\DepenseCourtier;
use App\Entity\Entreprise;
use App\Entity\Feedback;
use App\Entity\Fournisseur;
use App\Entity\Piste;
use App\Entity\Portefeuille;
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
        Entreprise::class      => 200,
        Avenant::class         => 100,
        Cotation::class        => 50,
        Piste::class           => 20,
        Tache::class           => 10,
        Feedback::class        => 8,
        // Portefeuille client (regroupement de clients par gestionnaire de compte) :
        // entrée explicite pour figurer dans le plan tarifaire éditable, au poids standard.
        Portefeuille::class    => 5,
        // Comptabilité du courtier (workspace) : entrées explicites pour figurer
        // dans le plan tarifaire éditable (console), au poids standard.
        ChargeCourtier::class  => 5,
        DepenseCourtier::class => 5,
        Fournisseur::class     => 5,
        // Assistant IA : chaque message envoyé à l'assistant est métré comme une
        // écriture (≈ 2 écritures standard — le traitement IA coûte plus qu'un
        // simple enregistrement). Paramétrable en console comme les autres poids.
        AssistantMessage::class => 10,
    ];

    /** Poids par défaut en écriture pour toute entité non explicitement listée. */
    public const DEFAULT_WRITE_WEIGHT = 5;

    /**
     * Assistant IA : coût d'un objet attaché au contexte d'une conversation,
     * exprimé en RATIO du poids d'un message (décision produit : 80 %). Facturé
     * une seule fois, à l'attache ; suit dynamiquement le poids message
     * paramétré en console.
     */
    public const CONTEXTE_IA_RATIO = 0.8;

    /** Poids en tokens d'une entité envoyée vers le frontend (LECTURE / sortie). */
    public const READ_WEIGHT = 2;

    /** Allocation gratuite offerte à chaque utilisateur, par fenêtre. */
    public const FREE_ALLOWANCE = 1000;

    /** Durée de validité (en heures) de l'allocation gratuite avant renouvellement. */
    public const FREE_WINDOW_HOURS = 8;

    /**
     * Paquets prépayés cumulables. Clé = identifiant technique (stable) du paquet.
     *  - label  : nom d'affichage du paquet (repli ucfirst(clé) si absent) ;
     *  - tokens : nombre de tokens crédités ;
     *  - price  : prix de vente TTC en USD.
     */
    public const PACKS = [
        'intermediaire' => ['label' => 'Intermédiaire', 'tokens' => 10000, 'price' => 10],
        'professionnel' => ['label' => 'Professionnel', 'tokens' => 50000, 'price' => 40],
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
