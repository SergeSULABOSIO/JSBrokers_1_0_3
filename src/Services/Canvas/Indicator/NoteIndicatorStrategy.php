<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Note;
use App\Entity\Paiement;
use App\Services\Search\NoteReglementScope;
use App\Services\ServiceDates;
use Symfony\Contracts\Translation\TranslatorInterface;

class NoteIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator,
        private IndicatorCalculationHelper $calculationHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Note::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Note $entity */

        // Note de bordereau : pas d'articles, montants issus du bordereau persisté
        if ($entity->getArticles()->isEmpty() && $entity->getBordereau() !== null) {
            return $this->calculateFromBordereau($entity);
        }

        $montantTotal = round($this->getNoteMontantPayable($entity), 2);
        $montantPaye = round($this->getNoteMontantPaye($entity), 2);
        $montantTaxe = round($this->getNoteMontantTaxe($entity), 2);
        $montantHT = $montantTotal - $montantTaxe;

        return [
            'typeString' => $this->getNoteTypeString($entity),
            'addressedToString' => $this->calculationHelper->getNoteAddressedToString($entity),
            'montantTotal' => $montantTotal,
            'montantPaye' => $montantPaye,
            'solde' => round($montantTotal - $montantPaye, 2),
            // IL RESTE QUELQUE CHOSE À ENCAISSER — condition d'affichage de l'action
            // « Signaler le règlement ». La proposer sur une note soldée inviterait à un
            // double encaissement, et ouvrirait un formulaire sans montant à proposer.
            'aUnSoldeDu' => NoteReglementScope::resteADue($montantTotal, $montantPaye),
            // Seul un brouillon se valide : condition de l'action « Valider la note ».
            'aValider' => $entity->estBrouillon() && !$entity->estDeReprise(),
            'statutPaiement' => NoteReglementScope::libelleAffichage(
                NoteReglementScope::statut($montantTotal, $montantPaye),
            ),
            'montantTaxe' => $montantTaxe,
            'nomTaxe' => $this->getNoteNomTaxe($entity),
            'tauxTaxe' => $this->getNoteTauxTaxe($entity, $montantHT),
            // ⚠ CE CHEMIN-CI AUSSI. La bascule vers `calculateFromBordereau()` exige que la
            // note n'ait AUCUN article ; une note qui porterait les deux passerait par ici,
            // et resterait sans badge alors qu'elle est bien liée à un bordereau. La
            // provenance se lit sur la relation, pas sur le chemin de calcul emprunté.
            'bordereauAffiche' => $this->bordereauAffiche($entity),
            'bordereauReference' => $entity->getBordereau()?->getReference(),
        ];
    }

    /**
     * LE BADGE DE PROVENANCE. Rien pour une note ordinaire : le rendu d'une ligne n'affiche
     * un badge que si sa valeur n'est pas vide, et c'est ce qui laisse les notes courantes
     * muettes plutôt que de les marquer « ordinaire ».
     *
     * Le niveau reste NEUTRE — le gris par défaut. Les niveaux existants disent tous une
     * urgence ou une action à mener (critique, exigible, rétro à payer) ; une provenance
     * n'en est pas une, et lui emprunter le cobalt ferait croire à un geste attendu.
     */
    private function bordereauAffiche(Note $note): ?string
    {
        return $note->getBordereau() !== null ? 'Bordereau' : null;
    }

    private function calculateFromBordereau(Note $note): array
    {
        $bordereau   = $note->getBordereau();
        // Figée, la note garde les montants de sa validation, même si le bordereau est
        // réanalysé depuis.
        $montantHT   = round($note->estFigee() ? (float) $note->getMontantHtFige() : ($bordereau->getMontantComHtPayableNow() ?? 0.0), 2);
        $montantTaxe = round($note->estFigee() ? (float) $note->getMontantTtcFige() - (float) $note->getMontantHtFige() : ($bordereau->getMontantTaxePayableNow() ?? 0.0), 2);
        $montantTotal = round($montantHT + $montantTaxe, 2);
        $montantPaye  = round($this->getNoteMontantPaye($note), 2);
        $solde        = round($montantTotal - $montantPaye, 2);

        $tauxTaxe = ($montantHT > 0 && $montantTaxe > 0)
            ? round(($montantTaxe / $montantHT) * 100, 2)
            : 0.0;

        return [
            'typeString'       => $this->getNoteTypeString($note),
            'addressedToString'=> $this->calculationHelper->getNoteAddressedToString($note),
            'montantTotal'     => $montantTotal,
            'montantPaye'      => $montantPaye,
            'solde'            => $solde,
            // ⚠ LES DEUX CHEMINS PASSENT PAR LA MÊME RÈGLE. Elle était écrite deux fois —
            // en `if` pour les notes à articles, en `match` ici —, et la première ignorait
            // le bordereau : appelée seule sur une note de bordereau, elle lisait des
            // articles vides et répondait « N/A » à tort. Deux copies finissent par diverger.
            'aUnSoldeDu'       => NoteReglementScope::resteADue($montantTotal, $montantPaye),
            'aValider'         => $note->estBrouillon() && !$note->estDeReprise(),
            'statutPaiement'   => NoteReglementScope::libelleAffichage(
                NoteReglementScope::statut($montantTotal, $montantPaye),
            ),
            'montantTaxe'      => $montantTaxe,
            'nomTaxe'          => 'Taxe',
            'tauxTaxe'         => $tauxTaxe,
            'bordereauAffiche' => $this->bordereauAffiche($note),
            'bordereauReference' => $bordereau->getReference(),
        ];
    }

    private function getNoteMontantTaxe(Note $note): float
    {
        // Une note de crédit à un partenaire (rétrocommission) ou à une autorité fiscale
        // (la taxe elle-même) n'a pas de part de taxe.
        if ($note->getType() === Note::TYPE_NOTE_DE_CREDIT
            && in_array($note->getAddressedTo(), [Note::TO_PARTENAIRE, Note::TO_AUTORITE_FISCALE], true)) {
            return 0.0;
        }
        // Note de débit, ou AVOIR au client ou à l'assureur : la taxe est la différence
        // entre le TTC et le HT — l'avoir rend la taxe qu'avait portée la note annulée.
        return $this->calculationHelper->getNoteMontantPayable($note) - $this->calculationHelper->getNoteMontantHT($note);
    }

    // --- Méthodes privées déplacées depuis CalculationProvider ---

    private function getNoteTypeString(?Note $note): ?string
    {
        if ($note === null) return null;

        return match ($note->getType()) {
            Note::TYPE_NOTE_DE_DEBIT => 'Note de débit',
            Note::TYPE_NOTE_DE_CREDIT => 'Note de crédit',
            default => 'Inconnu',
        };
    }

    private function getNoteMontantPayable(?Note $note): float
    {
        // Utilisation du helper pour garantir que les montants des articles sont calculés à la volée
        return $this->calculationHelper->getNoteMontantPayable($note);
    }

    private function getNoteMontantPaye(?Note $note): float
    {
        $montant = 0;
        if ($note) {
            foreach ($note->getPaiements() as $encaisse) {
                /** @var Paiement $paiement */
                $paiement = $encaisse;
                $montant += $paiement->getMontant();
            }
        }
        return $montant;
    }


    private function getNoteSolde(Note $note): float
    {
        return $this->getNoteMontantPayable($note) - $this->getNoteMontantPaye($note);
    }


    private function getNoteNomTaxe(Note $note): ?string
    {
        // Source unique, partagée avec le figeage de la note.
        return $this->calculationHelper->getNoteNomTaxe($note);
    }

    private function getNoteTauxTaxe(Note $note, float $montantHT): ?float
    {
        if ($note->estFigee() && $note->getTauxTaxeFige() !== null) {
            return $note->getTauxTaxeFige();
        }
        $montantTaxe = $this->getNoteMontantTaxe($note);
        if ($montantHT > 0 && $montantTaxe > 0) {
            return ($montantTaxe / $montantHT) * 100;
        }
        return 0.0;
    }
}