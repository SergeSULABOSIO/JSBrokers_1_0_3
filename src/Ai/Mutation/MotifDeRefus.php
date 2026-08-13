<?php

namespace App\Ai\Mutation;

use App\Ai\Tool\AiToolResult;

/**
 * POURQUOI UN OUTIL DE PLAN N'A PAS PRODUIT DE PLAN, dit en une phrase lisible —
 * et tirée de ce que l'outil a RÉELLEMENT répondu, jamais d'une formule générique.
 *
 * Cette traduction existait déjà, enfouie en privé dans ProgrammeRunner pour le
 * rapport de fin de mission. Elle sert maintenant à deux endroits — le rapport, et
 * l'avertissement affiché à l'utilisateur quand la prose du modèle décrit un plan
 * que l'outil a refusé de préparer. Deux implémentations diraient tôt ou tard deux
 * choses différentes du même refus : celui qu'on lit dans le rapport et celui qu'on
 * lit dans le fil.
 *
 * RÈGLE DE LECTURE (déjà payée par un incident) : un refus d'outil de plan est un
 * `AiToolResult::ok()` portant `pret: false`. Tester le seul statut ne l'intercepte
 * PAS — c'est `pret !== true` qui fait autorité.
 */
final class MotifDeRefus
{
    /** Un outil de plan a-t-il refusé de produire un plan ? */
    public static function estUnRefus(AiToolResult $resultat): bool
    {
        if ($resultat->status !== AiToolResult::STATUS_OK) {
            return true;
        }

        return ($resultat->data['pret'] ?? null) !== true;
    }

    public static function depuis(AiToolResult $resultat): string
    {
        if ($resultat->status === AiToolResult::STATUS_HORS_PERIMETRE) {
            return sprintf('Hors de votre périmètre d’accès (%s).', (string) ($resultat->data['libelle'] ?? 'données'));
        }
        if ($resultat->status === AiToolResult::STATUS_INTROUVABLE) {
            $precision = trim((string) ($resultat->data['precision'] ?? ''));

            return $precision !== '' ? sprintf('Cible introuvable : %s.', $precision) : 'Cible introuvable.';
        }

        $manquants = $resultat->data['manquants'] ?? [];
        if (is_array($manquants) && $manquants !== []) {
            return 'Informations manquantes : ' . implode(' ; ', array_map('strval', $manquants));
        }
        // Un nom dicté qui n'a pas pu être identifié : on nomme le terme cherché, sinon
        // l'utilisateur ne peut pas savoir lequel de ses mots n'a pas été compris.
        $aDemander = $resultat->data['aDemander'] ?? [];
        if (is_array($aDemander) && $aDemander !== []) {
            $termes = [];
            foreach ($aDemander as $question) {
                if (!is_array($question)) {
                    continue;
                }
                $libelle = trim((string) ($question['libelle'] ?? $question['champ'] ?? ''));
                $terme = trim((string) ($question['terme'] ?? ''));
                if ($libelle === '') {
                    continue;
                }
                $termes[] = $terme !== '' ? sprintf('%s « %s »', $libelle, $terme) : $libelle;
            }
            if ($termes !== []) {
                return 'Références à préciser : ' . implode(' ; ', $termes) . '.';
            }
        }
        $blocages = $resultat->data['blocages'] ?? [];
        if (is_array($blocages) && $blocages !== []) {
            return 'Blocage : ' . implode(' ; ', array_map('strval', $blocages));
        }
        if (($resultat->data['planEnAttente'] ?? false) === true) {
            return 'Un autre plan attendait déjà une décision.';
        }
        if (($resultat->data['dejaAJour'] ?? false) === true) {
            return 'Rien à écrire : les données étaient déjà à jour.';
        }
        // « bloquant » est, par contrat, la phrase écrite POUR L'UTILISATEUR (c'est
        // aussi celle que RepliPrecis restitue). Elle passe donc avant « note », qui
        // s'adresse au modèle : le 2026-08-13, faute de cette branche, le courtier a
        // lu « reprends le nom exact et rappelle preparer_operations ».
        $bloquant = trim((string) ($resultat->data['bloquant'] ?? ''));
        if ($bloquant !== '') {
            return $bloquant;
        }

        return trim((string) ($resultat->data['note'] ?? '')) ?: 'L’outil n’a pas pu préparer ce plan.';
    }
}
