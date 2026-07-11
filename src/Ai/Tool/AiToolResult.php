<?php

namespace App\Ai\Tool;

/**
 * Résultat d'exécution d'un outil de données de l'assistant IA.
 * HORS_PERIMETRE signale que l'invité n'a pas le droit de lecture sur les
 * données visées (le moteur formule alors le refus poli standardisé).
 *
 * `uiAction` = directive d'INTENTION destinée au frontend (jamais renvoyée au
 * modèle) : le moteur la remonte dans AiReply::actions, le chat la traduit sur
 * le bus d'événements du workspace (ex. ouvrir un dialogue de création).
 * L'assistant n'écrit jamais lui-même : l'action reste exécutée — et
 * re-validée — par les circuits existants de l'application.
 */
final class AiToolResult
{
    public const STATUS_OK = 'OK';
    public const STATUS_HORS_PERIMETRE = 'HORS_PERIMETRE';
    public const STATUS_INTROUVABLE = 'INTROUVABLE';

    private function __construct(
        public readonly string $status,
        public readonly array $data,
        public readonly ?array $uiAction = null,
    ) {
    }

    public static function ok(array $data, ?array $uiAction = null): self
    {
        return new self(self::STATUS_OK, $data, $uiAction);
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
