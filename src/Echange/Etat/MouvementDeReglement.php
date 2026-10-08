<?php

namespace App\Echange\Etat;

/**
 * UN MOUVEMENT QUI A RÉGLÉ (UNE PART D') UNE TRANCHE — une ligne de son relevé.
 *
 * `montant` est la part IMPUTÉE À LA TRANCHE, calculée par le même prorata que
 * l'indicateur qu'elle justifie : la somme des lignes d'une famille redonne donc, au
 * centime, le « payé » de la fiche. `montantBrut` est le règlement tel qu'il a été fait —
 * celui d'une note entière, qui couvre souvent plusieurs tranches. Les deux sont dans la
 * MÊME unité : aucune pièce du projet ne porte sa devise, et les indicateurs n'en
 * convertissent aucune.
 *
 * Une ligne INFÉRÉE n'a pas de pièce derrière elle : c'est le complément qu'une règle du
 * calcul ajoute (bordereau réconcilié, commission de l'assureur soldée). Elle se montre
 * comme telle — la confondre avec un règlement ferait chercher une pièce qui n'existe pas.
 */
final class MouvementDeReglement
{
    public const NATURE_SIGNALEMENT = 'Signalement';
    public const NATURE_FACTURE     = 'Facture';
    public const NATURE_TAXE        = 'Note fiscale';
    public const NATURE_VIREMENT    = 'Virement';
    public const NATURE_BORDEREAU   = 'Bordereau';
    public const NATURE_INFEREE     = 'Inféré';

    public function __construct(
        public readonly string $nature,
        public readonly string $libelle,
        public readonly ?\DateTimeImmutable $date,
        public readonly ?string $reference,
        public readonly float $montant,
        public readonly ?float $montantBrut = null,
        public readonly ?string $compte = null,
        public readonly ?string $lot = null,
        // La pièce derrière la ligne : son type gouverne ce qu'on a le droit d'en voir
        // (le compte bancaire d'une note n'est montré qu'à qui lit les notes), et son id
        // ouvre ses gestes (un signalement de prime se corrige depuis sa ligne).
        public readonly ?string $pieceEntite = null,
        public readonly ?int $pieceId = null,
        // Faux quand la note n'a aucun montant facturable : rien ne peut lui être imputé.
        public readonly bool $imputable = true,
    ) {
    }

    public function estInferee(): bool
    {
        return $this->nature === self::NATURE_INFEREE;
    }

    /** Même ligne, autre montant — c'est ainsi que l'arrondi reporte son écart. */
    public function avecMontant(float $montant): self
    {
        return new self(
            $this->nature, $this->libelle, $this->date, $this->reference, $montant,
            $this->montantBrut, $this->compte, $this->lot, $this->pieceEntite, $this->pieceId, $this->imputable,
        );
    }
}
