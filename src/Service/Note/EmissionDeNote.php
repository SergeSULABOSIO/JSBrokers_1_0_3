<?php

namespace App\Service\Note;

use App\Entity\Note;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ÉMETTRE UNE NOTE : la valider, ce qui lui donne son numéro définitif et sa date
 * d'émission — dans UNE transaction (règle OHADA, décision du 2026-10-15).
 *
 * ── LE SEUL CHEMIN DE LA VALIDATION ─────────────────────────────────────────────────
 * Le geste « Valider la note », le picker de facturation et Ket passent tous ici. La
 * validation fait naître la créance au journal, et FIGE les montants de la note
 * ({@see FigeageDeNote}) ; elle est irréversible — une note validée se corrige par un
 * avoir.
 *
 * ── POURQUOI LA TRANSACTION, ET POURQUOI ICI ────────────────────────────────────────
 * Le numéro est pris au flush ({@see \App\EventListener\NumerotationDesNotesListener}),
 * mais Doctrine déclenche `onFlush` AVANT d'ouvrir sa propre transaction. Hors
 * transaction englobante, une écriture qui échoue après la prise du numéro le perdrait :
 * un trou. Enveloppée, l'échec annule aussi l'incrément de la série. Et le verrou de
 * ligne de la série, tenu jusqu'au commit, fait attendre la validation concurrente :
 * pas de doublon, et la chronologie suit l'ordre des numéros.
 *
 * Les contrôles précèdent la transaction : un refus ne touche jamais la série.
 */
final class EmissionDeNote
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IndicatorCalculationHelper $calcul,
        private readonly FigeageDeNote $figeage,
        private readonly NumerotationDesNotes $numerotation,
    ) {
    }

    /**
     * UNE NOTE DE REPRISE : une commission déjà encaissée dans l'ancien système. Ce n'est
     * pas une facture émise par Joseara — elle reste HORS SÉRIE (référence « REPRISE-… »)
     * et non validée : aucune créance ne naît au journal. Mais ses montants sont FIGÉS à
     * l'import, comme ceux d'une note validée (décision de Serge du 2026-10-15). À appeler
     * une fois ses lignes posées.
     */
    public function figerUneReprise(Note $note): Note
    {
        if ($note->estFigee()) {
            return $note;
        }
        $this->em->wrapInTransaction(function () use ($note): void {
            $note->setOrigine(Note::ORIGINE_REPRISE);
            if (($entreprise = $note->getEntreprise()?->getId()) !== null) {
                $note->setReference($this->numerotation->provisoire($entreprise, 'REPRISE-'));
            }
            $this->figeage->figer($note);
            $this->em->persist($note);
            $this->em->flush();
        });

        return $note;
    }

    /**
     * Valide la note (neuve ou brouillon déjà écrit) et l'écrit.
     *
     * @throws NoteNonValidable quand la note ne peut pas être émise — le message s'adresse
     *                          au courtier
     */
    public function valider(Note $note): Note
    {
        if ($motif = $this->motifDeRefus($note)) {
            throw new NoteNonValidable($motif);
        }

        $this->em->wrapInTransaction(function () use ($note): void {
            // LES MONTANTS SE FIGENT AVEC LE NUMÉRO : une note émise ne se recalcule plus.
            $this->figeage->figer($note);
            $note->setValidated(true);
            $this->em->persist($note);
            $this->em->flush();
        });

        return $note;
    }

    /** Pourquoi cette note ne peut pas être validée, ou null si elle le peut. */
    public function motifDeRefus(Note $note): ?string
    {
        if ($note->isValidated() === true) {
            return sprintf('La note %s est déjà validée.', $note->getReference());
        }
        // Une note de REPRISE retrace une commission déjà encaissée dans l'ancien système.
        // La valider lui donnerait un numéro de la série et ferait naître aujourd'hui une
        // créance d'hier : le chiffre d'affaires d'une période passée glisserait au présent.
        if ($note->estDeReprise()) {
            return sprintf('La note %s retrace une commission reprise de votre ancien système : elle ne se valide pas.', $note->getReference());
        }
        if ($note->getEntreprise() === null) {
            return 'Cette note n’appartient à aucun cabinet.';
        }
        if ($this->destinataire($note) === null) {
            return 'Indiquez à qui la note est adressée avant de la valider.';
        }
        if ($note->getArticles()->isEmpty() && $note->getBordereau() === null) {
            return 'Une note sans ligne ne se valide pas : ajoutez-lui au moins un article.';
        }
        if ($note->getBordereau() === null && abs($this->calcul->getNoteMontantPayable($note)) < 0.005) {
            return 'Le montant de cette note est nul : il n’y a rien à émettre.';
        }

        return null;
    }

    private function destinataire(Note $note): ?object
    {
        return match ($note->getAddressedTo()) {
            Note::TO_CLIENT => $note->getClient(),
            Note::TO_ASSUREUR => $note->getAssureur(),
            Note::TO_PARTENAIRE => $note->getPartenaire(),
            Note::TO_AUTORITE_FISCALE => $note->getAutoritefiscale(),
            default => null,
        };
    }
}
