<?php

namespace App\Echange\Service;

/**
 * Issue de la résolution d'un renvoi : ce qu'il faut écrire dans le champ, ou pourquoi
 * on ne peut pas.
 *
 * Quatre issues, et pas trois : « vide » (la cellule est blanche, ce qui est
 * légitime) se distingue d'« ignoré » (la colonne est descriptive et n'est pas relue),
 * lui-même distinct d'un refus. Les confondre reviendrait à effacer un lien existant
 * parce qu'une colonne d'information était vide.
 */
final class Renvoi
{
    private function __construct(
        public readonly string $statut,
        public readonly int|string|null $valeur = null,
        public readonly string $motif = '',
        public readonly bool $ambigu = false,
    ) {
    }

    /** La cellule est vide : le champ sera écrit à null, délibérément. */
    public static function vide(): self
    {
        return new self('vide');
    }

    /** Colonne descriptive, hors périmètre d'échange : ne pas la relire du tout. */
    public static function ignore(): self
    {
        return new self('ignore');
    }

    /** Résolu vers une ligne existante. */
    public static function identifiant(int $id): self
    {
        return new self('id', $id);
    }

    /** Résolu vers une ligne NOUVELLE du même fichier (« @etiquette »). */
    public static function repere(string $etiquette): self
    {
        return new self('repere', $etiquette);
    }

    public static function refus(string $motif, bool $ambigu = false): self
    {
        return new self('refus', null, $motif, $ambigu);
    }

    public function estRefus(): bool
    {
        return $this->statut === 'refus';
    }

    /** La valeur doit-elle être posée sur le champ ? */
    public function estEcrivable(): bool
    {
        return in_array($this->statut, ['vide', 'id', 'repere'], true);
    }
}
