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

    /**
     * @param string $note CE QU'IL FAUT FAIRE, à l'intention du modèle.
     *
     * POURQUOI CE SECOND PARAMÈTRE (incident du 2026-08-12). Ce refus ne portait
     * qu'une « precision » — un libellé et un identifiant, « Avenants #1 ». Le
     * modèle n'avait donc RIEN pour se corriger : il a inventé un « ajustement
     * technique requis » et proposé à l'utilisateur de « valider le lancement
     * direct de la création par étape », c'est-à-dire rien du tout. Un refus qui
     * ne dit pas quoi faire produit une impasse polie.
     */
    public static function introuvable(string $precision = '', string $note = ''): self
    {
        $data = ['precision' => $precision];
        if (trim($note) !== '') {
            $data['note'] = $note;
        }

        return new self(self::STATUS_INTROUVABLE, $data);
    }
}
