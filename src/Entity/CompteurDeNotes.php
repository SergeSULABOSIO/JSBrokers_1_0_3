<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * LE DERNIER NUMÉRO ATTRIBUÉ À UNE SÉRIE DE NOTES : un cabinet, une année, un type.
 *
 * Une note de débit et une note de crédit forment deux séries (ND-2026-00001,
 * NC-2026-00001) : chacune se lit sans trou dû à l'autre. Chaque série repart à 1 le
 * 1er janvier (décision du 2026-10-14).
 *
 * ⚠ CETTE LIGNE NE SE LIT PAS PAR L'ORM. L'incrément passe par une seule instruction SQL
 * atomique ({@see \App\Service\Note\NumerotationDesNotes}) : lire la séquence, l'augmenter
 * puis l'écrire en trois temps laisserait deux enregistrements simultanés repartir avec le
 * même numéro. L'entité n'existe que pour que le schéma la déclare.
 */
#[ORM\Entity]
#[ORM\Table(name: 'compteur_de_notes')]
#[ORM\UniqueConstraint(name: 'uniq_compteur_de_notes_serie', columns: ['entreprise_id', 'annee', 'type'])]
class CompteurDeNotes
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // ⚠ SUPPRIMÉE AVEC SON CABINET, par la base elle-même : les purges de cabinet (et les
    // nettoyages des tests) effacent l'entreprise en SQL, sans passer par l'ORM.
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entreprise $entreprise = null;

    /** Année civile de la série (ex. 2026). */
    #[ORM\Column]
    private int $annee = 0;

    /** Note::TYPE_NOTE_DE_DEBIT ou Note::TYPE_NOTE_DE_CREDIT. */
    #[ORM\Column]
    private int $type = Note::TYPE_NOTE_DE_DEBIT;

    /** Dernier numéro attribué dans la série (0 = aucun encore). */
    #[ORM\Column]
    private int $sequence = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntreprise(): ?Entreprise
    {
        return $this->entreprise;
    }

    public function getAnnee(): int
    {
        return $this->annee;
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function getSequence(): int
    {
        return $this->sequence;
    }
}
