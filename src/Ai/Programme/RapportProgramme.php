<?php

namespace App\Ai\Programme;

/**
 * Mise en forme du RAPPORT FINAL de mission, en markdown de chat.
 *
 * Le texte est écrit par le SERVEUR à partir du rapport vérifié en base — jamais
 * par le modèle. C'est le point du parcours où il serait le plus tentant, et le
 * plus dommageable, de « résumer » : l'incident fondateur est précisément une
 * affirmation de complaisance (« les trois paiements ont été enregistrés »)
 * posée sur une seule exécution réelle. Ici, ce qui est écrit est ce qui a été
 * relu en base, ni plus ni moins.
 *
 * Contraintes de rendu du chat : pas de titres `#` (hors allowlist DOMPurify) —
 * on met en gras ; pastilles d'état via la syntaxe `[Libellé](#success)`.
 */
final class RapportProgramme
{
    private const PASTILLE = [
        'executee'   => '[Exécutée](#success)',
        'annulee'    => '[Refusée](#warning)',
        'impossible' => '[Impossible](#danger)',
        'echec'      => '[Échec](#danger)',
        'en_attente' => '[Non traitée](#danger)',
        'proposee'   => '[En attente](#warning)',
    ];

    /** @param array<string, mixed> $rapport */
    public static function enMarkdown(array $rapport): string
    {
        $lignes = [];
        $lignes[] = sprintf('**Rapport de mission %s**', (string) ($rapport['reference'] ?? ''));
        $lignes[] = '';
        $lignes[] = sprintf('Objectif : %s', (string) ($rapport['objectif'] ?? ''));
        $lignes[] = '';

        $etapes = is_array($rapport['etapes'] ?? null) ? $rapport['etapes'] : [];
        $lignes[] = '| Référence | Étape | État | Vérification en base |';
        $lignes[] = '| --- | --- | --- | --- |';
        foreach ($etapes as $etape) {
            $statut = (string) ($etape['statut'] ?? '');
            $lignes[] = sprintf(
                '| %s | %s | %s | %s |',
                self::cellule((string) ($etape['reference'] ?? '')),
                self::cellule((string) ($etape['libelle'] ?? '')),
                self::PASTILLE[$statut] ?? $statut,
                self::cellule(self::verdict($etape)),
            );
        }
        $lignes[] = '';

        $ecarts = is_array($rapport['ecarts'] ?? null) ? $rapport['ecarts'] : [];
        if ($ecarts === []) {
            $lignes[] = '[Conforme](#success) Chaque écriture du programme a été relue en base : tout est '
                . 'enregistré comme annoncé, et les effets attendus sont là.';

            return implode("\n", $lignes);
        }

        $lignes[] = sprintf('**Écarts constatés (%d)**', count($ecarts));
        $lignes[] = '';
        foreach ($ecarts as $ecart) {
            $lignes[] = sprintf('- [Écart](#danger) %s', self::cellule((string) $ecart));
        }

        $corrections = is_array($rapport['corrections'] ?? null) ? $rapport['corrections'] : [];
        if ($corrections !== []) {
            $lignes[] = '';
            $lignes[] = sprintf(
                '**Correction proposée** — %d étape%s, à valider une par une comme le programme initial :',
                count($corrections),
                count($corrections) > 1 ? 's' : '',
            );
            $lignes[] = '';
            foreach ($corrections as $correction) {
                $lignes[] = sprintf('- %s', self::cellule((string) ($correction['libelle'] ?? '')));
            }
        }

        return implode("\n", $lignes);
    }

    /** Résumé de vérification d'une ligne : ce qui cloche d'abord, sinon ce qui a été relu. */
    private static function verdict(array $etape): string
    {
        $ecarts = is_array($etape['ecarts'] ?? null) ? $etape['ecarts'] : [];
        if ($ecarts !== []) {
            return implode(' ', array_map('strval', $ecarts));
        }
        $motif = trim((string) ($etape['motif'] ?? ''));
        if ($motif !== '') {
            return $motif;
        }
        $constats = is_array($etape['constats'] ?? null) ? $etape['constats'] : [];

        return $constats !== [] ? implode(' ', array_map('strval', $constats)) : '—';
    }

    /** Neutralise ce qui casserait une cellule de tableau markdown ou une pastille. */
    private static function cellule(string $texte): string
    {
        $plat = trim((string) preg_replace('/\s+/u', ' ', $texte));

        return str_replace(['|', '[', ']'], ['/', '(', ')'], $plat);
    }
}
