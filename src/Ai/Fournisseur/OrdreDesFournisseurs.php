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
     * Ceux qui sont CONFIGURÉS — une clé posée, un moteur réel.
     *
     * ⚠ NE PAS Y AJOUTER L'ÉPUISEMENT. « Configuré » et « a encore du solde » sont
     * deux questions différentes, et la voix de Ket s'appuie précisément sur cette
     * distinction : `estDisponible()` répond « la fonctionnalité existe » (sinon la
     * page masque le bouton), là où `uneVoixPeutParler()` répond « elle rendra
     * quelque chose maintenant » (sinon la page branche la synthèse du navigateur).
     * Les confondre fait disparaître le bouton un jour de quota épuisé, au lieu de
     * basculer proprement sur le repli — c'est le défaut qu'un test a attrapé le
     * 2026-09-22.
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

    /**
     * Ceux qui rendront réellement quelque chose MAINTENANT : configurés ET pas à sec.
     *
     * C'est ce que veulent les chaînes qui CHOISISSENT un fournisseur — le moteur de
     * texte, la compréhension, la finition. Écarter ici un fournisseur qui s'est
     * déclaré à sec, c'est ne plus jamais lui parler tant qu'il ne peut rien rendre :
     * on va droit à celui qui a du solde, au lieu de payer une attente pour un refus
     * connu d'avance.
     *
     * La voix et l'oreille, elles, gardent leur propre enchaînement : elles essaient
     * les fournisseurs l'un après l'autre et ont besoin de distinguer « aucun n'est
     * configuré » de « aucun n'a de solde ».
     *
     * @template T of Fournisseur
     *
     * @param list<T> $fournisseurs
     *
     * @return list<T>
     */
    public static function utilisables(array $fournisseurs): array
    {
        return array_values(array_filter(
            self::disponibles($fournisseurs),
            static fn (Fournisseur $f): bool => !$f->estEpuise(),
        ));
    }
}
