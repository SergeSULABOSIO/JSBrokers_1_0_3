<?php

namespace App\Ai\Tool;

/**
 * Résultat d'exécution d'un outil de données de l'assistant IA.
 * HORS_PERIMETRE signale que l'invité n'a pas le droit de lecture sur les
 * données visées (le moteur formule alors le refus poli standardisé).
 */
final class AiToolResult
{
    public const STATUS_OK = 'OK';
    public const STATUS_HORS_PERIMETRE = 'HORS_PERIMETRE';
    public const STATUS_INTROUVABLE = 'INTROUVABLE';

    private function __construct(
        public readonly string $status,
        public readonly array $data,
    ) {
    }

    public static function ok(array $data): self
    {
        return new self(self::STATUS_OK, $data);
    }

    /** @param string $libelle Libellé lisible des données refusées (pour le message de refus). */
    public static function horsPerimetre(string $libelle): self
    {
        return new self(self::STATUS_HORS_PERIMETRE, ['libelle' => $libelle]);
    }

    public static function introuvable(string $precision = ''): self
    {
        return new self(self::STATUS_INTROUVABLE, ['precision' => $precision]);
    }
}
