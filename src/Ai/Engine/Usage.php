<?php

namespace App\Ai\Engine;

/**
 * Ce qu'un tour a coûté chez le fournisseur, lu dans sa réponse.
 *
 * POURQUOI UN OBJET PLUTÔT QUE TROIS ENTIERS. Les deux fournisseurs ne comptent
 * pas la même chose sous le même nom, et la confusion est silencieuse : elle ne
 * casse rien, elle fausse les mesures sur lesquelles on tranche des décisions
 * d'architecture. Deux pièges, tous deux vécus ailleurs :
 *
 * 1. Chez Anthropic, « input_tokens » n'est PAS la taille du prompt : c'est le
 *    seul reliquat situé APRÈS le dernier point de rupture du cache. Avec le
 *    cache posé, il vaut ~2 400 là où le prompt en fait 36 000. Le lire seul
 *    afficherait une consommation quinze fois trop basse, et rendrait la
 *    campagne de mesure incomparable avec celle de Gemini, dont le
 *    promptTokenCount, lui, INCLUT les tokens cachés.
 *
 * 2. Ce qui part au JOURNAL et ce qui part au COMPTEUR DE DÉBIT diffèrent —
 *    chez Anthropic seulement. Le journal veut le prompt entier ($entree) ;
 *    le plafond du fournisseur, lui, ne compte pas les tokens LUS en cache
 *    ($debit). Chez Gemini les deux sont égaux : le cache implicite allège la
 *    facture, jamais la limite par minute. D'où deux champs distincts, nommés
 *    pour ce qu'ils servent, plutôt qu'un seul qu'on interpréterait mal.
 */
final readonly class Usage
{
    private function __construct(
        /** Taille TOTALE du prompt envoyé, tokens cachés compris. */
        public int $entree,
        public int $sortie,
        /** Sous-ensemble de $entree servi depuis le cache — l'économie réalisée. */
        public int $cacheLu,
        /** Sous-ensemble de $entree écrit dans le cache — facturé plus cher que le plein tarif. */
        public int $cacheEcrit,
        /** Ce que le FOURNISSEUR décompte de son plafond par minute. */
        public int $debit,
    ) {
    }

    /**
     * Lit le bloc « usage » d'une réponse Messages API.
     *
     * Total du prompt = input_tokens + cache_creation + cache_read : c'est la
     * formule que donne la documentation, et le seul moyen d'obtenir un nombre
     * comparable au promptTokenCount de Gemini.
     *
     * @param array<string, mixed> $reponse
     */
    public static function depuisAnthropic(array $reponse): self
    {
        $u = $reponse['usage'] ?? [];

        $reliquat = (int) ($u['input_tokens'] ?? 0);
        $ecrit    = (int) ($u['cache_creation_input_tokens'] ?? 0);
        $lu       = (int) ($u['cache_read_input_tokens'] ?? 0);

        return new self(
            entree: $reliquat + $ecrit + $lu,
            sortie: (int) ($u['output_tokens'] ?? 0),
            cacheLu: $lu,
            cacheEcrit: $ecrit,
            // Les tokens LUS en cache ne comptent pas dans l'ITPM ; ceux qu'on
            // vient d'y ÉCRIRE, si. C'est ce qui rend le plafond Anthropic si
            // haut en pratique, et c'est le chiffre à déclarer au compteur.
            debit: $reliquat + $ecrit,
        );
    }

    /**
     * Lit le bloc « usageMetadata » d'une réponse generateContent.
     *
     * Ici, contrairement à Anthropic, `promptTokenCount` EST le prompt entier, et
     * les tokens cachés comptent dans le quota par minute autant que les autres :
     * le cache implicite de Google allège la facture, jamais le débit. C'est
     * pourquoi $debit vaut $entree — le 429 du 2026-08-08 est survenu alors que
     * 77 % de la minute était en cache.
     *
     * @param array<string, mixed> $reponse
     */
    public static function depuisGemini(array $reponse): self
    {
        $u = $reponse['usageMetadata'] ?? [];
        $entree = (int) ($u['promptTokenCount'] ?? 0);

        return new self(
            entree: $entree,
            sortie: (int) ($u['candidatesTokenCount'] ?? 0),
            cacheLu: (int) ($u['cachedContentTokenCount'] ?? 0),
            cacheEcrit: 0, // le cache de Google est implicite : rien n'est « écrit » à notre demande
            debit: $entree,
        );
    }

    /** Un tour qui n'a rien rapporté : le fournisseur n'a pas répondu, rien n'est à compter. */
    public static function neant(): self
    {
        return new self(entree: 0, sortie: 0, cacheLu: 0, cacheEcrit: 0, debit: 0);
    }

    /**
     * La forme attendue par JournalTokens::tour().
     *
     * @return array{entree: int, sortie: int, cache: int}
     */
    public function pourLeJournal(): array
    {
        return ['entree' => $this->entree, 'sortie' => $this->sortie, 'cache' => $this->cacheLu];
    }
}
