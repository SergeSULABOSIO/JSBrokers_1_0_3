<?php

namespace App\Ai\Document;

/**
 * La NOTE DE BAS DE PAGE du document : entreprise, utilisateur, titre, date de
 * production.
 *
 * Construite exclusivement par le SERVEUR. Ce sont des faits — qui a produit ce
 * document, pour quelle maison, quel jour — et un fait ne se rédige pas : laissé au
 * modèle, il deviendrait tôt ou tard une date plausible mais fausse sur un document
 * qui, lui, sort du logiciel et circule.
 */
final readonly class PiedDePage
{
    public function __construct(
        public string $entreprise,
        public string $utilisateur,
        public string $titre,
        public \DateTimeImmutable $produitLe,
        public string $assistantNom,
    ) {
    }

    /** Ligne unique, telle qu'elle s'imprime en pied de page. */
    public function ligne(): string
    {
        return sprintf(
            '%s — %s · %s · Produit le %s par %s (JS Brokers)',
            $this->entreprise,
            $this->utilisateur,
            $this->titre,
            $this->produitLe->format('d/m/Y à H:i'),
            $this->assistantNom,
        );
    }

    /**
     * Les quatre mentions, séparées — pour les rendus qui disposent d'un vrai pied
     * de page paginé (Word) ou d'un tableau (Excel).
     *
     * @return list<array{0: string, 1: string}>
     */
    public function mentions(): array
    {
        return [
            ['Entreprise', $this->entreprise],
            ['Utilisateur', $this->utilisateur],
            ['Document', $this->titre],
            ['Produit le', $this->produitLe->format('d/m/Y à H:i')],
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'entreprise'   => $this->entreprise,
            'utilisateur'  => $this->utilisateur,
            'titre'        => $this->titre,
            'produitLe'    => $this->produitLe->format(\DateTimeImmutable::ATOM),
            'assistantNom' => $this->assistantNom,
        ];
    }
}
