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
        /**
         * OÙ CETTE COLONNE S'ÉCRIT — `null` = elle ne s'écrit pas.
         *
         * ⚠ C'EST CE CHAMP QUI REND LE CLASSEUR RÉIMPORTABLE, et c'est pour cela qu'il
         * vit ICI. La reconstitution a besoin de savoir quelle propriété de quelle
         * entité une colonne alimente. Le déclarer dans une seconde table, à côté du
         * catalogue, ce serait deux vérités à tenir en accord — et le jour où elles
         * divergent, une colonne s'écrit dans le mauvais champ sans que rien ne le dise.
         *
         * Forme : `Entite.propriete` (« Avenant.referencePolice »), ou `Entite.collection`
         * pour une cellule multi-valeurs (« Cotation.chargements »).
         */
        public readonly ?string $cible = null,
    ) {
    }

    /**
     * LA MÊME COLONNE, MAIS RELUE À L'IMPORT — et sachant où elle s'écrit.
     *
     * Décorer plutôt que passer la cible à chaque fabrique : sur soixante et une
     * colonnes dont une quinzaine se réimporte, le défaut utile est « résultat ». Une
     * colonne calculée qu'on aurait oublié de marquer resterait ainsi ignorée, quand
     * l'inverse — une colonne de résultat relue par mégarde — écrirait en base un
     * chiffre que l'application recalcule.
     */
    public function enSaisie(string $cible): self
    {
        return new self($this->libelle, $this->role, $this->explication, $cible);
    }

    /** Exportée pour information, jamais relue : c'est le défaut. */
    public function lectureSeule(): bool
    {
        return $this->cible === null;
    }

    /** Le nom court de l'entité que cette colonne alimente. */
    public function entiteCible(): ?string
    {
        return $this->cible === null ? null : explode('.', $this->cible, 2)[0];
    }

    /** La propriété — ou la collection — que cette colonne alimente. */
    public function proprieteCible(): ?string
    {
        return $this->cible === null ? null : (explode('.', $this->cible, 2)[1] ?? null);
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

    /**
     * LE GROUPE DE LA COLONNE, DÉDUIT DE SON LIBELLÉ.
     *
     * Les libellés sont écrits en deux temps — « Police · Référence », « Rétro agent ·
     * Solde ». Le préfixe EST le groupe ; ce qui n'en porte pas rejoint « Général ».
     *
     * ⚠ RIEN N'EST DÉCLARÉ, et c'est délibéré. Un champ `groupe` ajouté aux cinquante-deux
     * entrées du catalogue aurait fait une seconde vérité à tenir en accord avec le
     * libellé — et le jour où les deux divergent, le chip annonce un nom et la colonne en
     * porte un autre, sans que rien ne le signale.
     */
    public function groupe(): string
    {
        $position = mb_strpos($this->libelle, ' · ');

        return $position === false ? 'Général' : mb_substr($this->libelle, 0, $position);
    }

    /**
     * LA NATURE DE LA COLONNE, ÉCRITE POUR ÊTRE LUE.
     *
     * Le rôle est un code interne — « montant », « identifiant ». Le dictionnaire est lu
     * par un courtier, pas par le programme : il y verra « Montant », « Identifiant ».
     * C'est le critère de SIGNIFIANCE, et il ne coûte rien à tenir puisque la source reste
     * le rôle.
     */
    public function natureLisible(): string
    {
        return match ($this->role) {
            Colonnes::MONTANT => 'Montant',
            Colonnes::NOMBRE => 'Nombre',
            Colonnes::POURCENTAGE => 'Taux (en points)',
            Colonnes::DATE => 'Date',
            Colonnes::IDENTIFIANT => 'Identifiant',
            Colonnes::STATUT => 'Statut',
            default => 'Texte',
        };
    }

    /** Les montants et les nombres s'alignent à droite, et eux seuls. */
    public function aligneeADroite(): bool
    {
        return \in_array($this->role, Colonnes::ROLES_ALIGNES_A_DROITE, true);
    }
}
