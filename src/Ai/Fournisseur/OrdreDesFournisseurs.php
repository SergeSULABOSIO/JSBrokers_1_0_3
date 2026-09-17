<?php

namespace App\Ai\Fournisseur;

/**
 * L'ORDRE DE PRÉFÉRENCE, écrit une seule fois pour la bouche et pour les oreilles.
 *
 * Une variable d'environnement (« elevenlabs,gemini ») décide qui parle et qui écoute
 * en premier. Un nom absent de la liste n'est JAMAIS appelé : c'est ainsi qu'on coupe
 * un fournisseur sans toucher au code.
 */
final class OrdreDesFournisseurs
{
    /**
     * Les fournisseurs, dans l'ordre de la liste. Ceux qu'elle ne nomme pas sont écartés.
     *
     * @template T of Fournisseur
     *
     * @param iterable<T> $fournisseurs
     *
     * @return list<T>
     */
    public static function ordonner(iterable $fournisseurs, string $ordre): array
    {
        $parNom = [];
        foreach ($fournisseurs as $fournisseur) {
            $parNom[$fournisseur->nom()] = $fournisseur;
        }

        $ordonnes = [];
        foreach (array_filter(array_map('trim', explode(',', $ordre))) as $nom) {
            if (isset($parNom[$nom])) {
                $ordonnes[] = $parNom[$nom];
            }
        }

        return $ordonnes;
    }

    /**
     * Ceux qui sont réellement appelables, dans le même ordre.
     *
     * @template T of Fournisseur
     *
     * @param list<T> $fournisseurs
     *
     * @return list<T>
     */
    public static function disponibles(array $fournisseurs): array
    {
        return array_values(array_filter($fournisseurs, static fn (Fournisseur $f): bool => $f->estDisponible()));
    }
}
