<?php

namespace App\Echange\Etat;

use App\Ai\Presentation\Colonnes;

/**
 * UNE COLONNE DE L'ÉTAT DU PORTEFEUILLE : un libellé, un rôle, une explication.
 *
 * Le rôle vient du vocabulaire de présentation déjà en place (`Colonnes`) — montant,
 * date, pourcentage, texte. Il décide seul du format de la cellule et de son alignement :
 * aucun écrivain n'a plus à deviner qu'une colonne nommée « solde » est un montant.
 *
 * L'explication n'est pas décorative. Elle alimente `_DICTIONNAIRE`, et c'est elle qui
 * empêche les trois erreurs de lecture déjà constatées sur ces chiffres : croire que la
 * taxe porte sur la prime, que le TTC comprend la taxe du courtier, ou qu'une commission
 * se proratise sur un règlement partiel.
 */
final class ColonneEtat
{
    public function __construct(
        /** Libellé humain, tel qu'il apparaît en ligne 1. */
        public readonly string $libelle,
        /** Rôle de présentation — une constante de `Colonnes`. */
        public readonly string $role,
        /** Ce que la colonne veut dire, pour `_DICTIONNAIRE`. */
        public readonly string $explication,
    ) {
    }

    public static function montant(string $libelle, string $explication): self
    {
        return new self($libelle, Colonnes::MONTANT, $explication);
    }

    public static function date(string $libelle, string $explication): self
    {
        return new self($libelle, Colonnes::DATE, $explication);
    }

    public static function texte(string $libelle, string $explication): self
    {
        return new self($libelle, Colonnes::TEXTE, $explication);
    }

    public static function pourcentage(string $libelle, string $explication): self
    {
        return new self($libelle, Colonnes::POURCENTAGE, $explication);
    }

    public static function identifiant(string $libelle, string $explication): self
    {
        return new self($libelle, Colonnes::IDENTIFIANT, $explication);
    }

    /** Les montants et les nombres s'alignent à droite, et eux seuls. */
    public function aligneeADroite(): bool
    {
        return \in_array($this->role, Colonnes::ROLES_ALIGNES_A_DROITE, true);
    }
}
