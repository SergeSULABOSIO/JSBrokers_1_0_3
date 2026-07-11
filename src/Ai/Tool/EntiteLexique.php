<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Service\Workspace\WorkspaceAccessResolver;

/**
 * Lexique partagé des entités interrogeables par l'assistant : mots-clés
 * (dérivés des libellés de la carte de permissions, DRY) => nom court d'entité.
 * Les pseudo-entités sans classe Doctrine (DocumentComptable) sont exclues.
 * Utilisé par les outils de données pour leur enum de schéma (tool-calling)
 * et leur matching par mots-clés (moteur simulé).
 */
final class EntiteLexique
{
    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
    ) {
    }

    /**
     * @return array<string, string[]> nom court => mots-clés normalisés
     */
    public function lexique(): array
    {
        $lexique = [];
        foreach ($this->accessResolver->libellesEntites() as $shortName => $label) {
            if (!class_exists('App\\Entity\\' . $shortName)) {
                continue;
            }

            $keywords = [];
            foreach ([AiText::normalize($label), AiText::normalize($shortName)] as $candidate) {
                $keywords[] = $candidate;
                // Variante singulier/pluriel naïve, suffisante pour un lexique FR.
                $keywords[] = str_ends_with($candidate, 's') ? rtrim($candidate, 's') : $candidate . 's';
            }
            $lexique[$shortName] = array_values(array_unique($keywords));
        }

        return $lexique;
    }

    /** @return string[] noms courts des entités interrogeables (enum des schémas d'outils) */
    public function nomsCourts(): array
    {
        return array_keys($this->lexique());
    }

    /** Première entité dont un mot-clé apparaît dans la question normalisée, ou null. */
    public function matchEntite(string $normalizedQuestion): ?string
    {
        foreach ($this->lexique() as $shortName => $keywords) {
            foreach ($keywords as $keyword) {
                if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $normalizedQuestion)) {
                    return $shortName;
                }
            }
        }

        return null;
    }
}
