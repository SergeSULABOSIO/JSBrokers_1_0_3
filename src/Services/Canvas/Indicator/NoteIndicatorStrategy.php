<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Note;
use App\Entity\Paiement;
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
        $montantTaxe = round($this->getNoteMontantTaxe($entity), 2);
        $montantHT = $montantTotal - $montantTaxe;

        return [
            'typeString' => $this->getNoteTypeString($entity),
            'addressedToString' => $this->calculationHelper->getNoteAddressedToString($entity),
            'montantTotal' => $montantTotal,
            'montantPaye' => round($this->getNoteMontantPaye($entity), 2),
            'solde' => round($this->getNoteSolde($entity), 2),
            // IL RESTE QUELQUE CHOSE À ENCAISSER — condition d'affichage de l'action
            // « Signaler le règlement ». La proposer sur une note soldée inviterait à un
            // double encaissement, et ouvrirait un formulaire sans montant à proposer.
            'aUnSoldeDu' => $this->aUnSoldeDu($this->getNoteSolde($entity)),
            'statutPaiement' => $this->getNoteStatutPaiementString($entity),
            'montantTaxe' => $montantTaxe,
            'nomTaxe' => $this->getNoteNomTaxe($entity),
            'tauxTaxe' => $this->getNoteTauxTaxe($entity, $montantHT),
        ];
    }

    private function calculateFromBordereau(Note $note): array
    {
        $bordereau   = $note->getBordereau();
        $montantHT   = round($bordereau->getMontantComHtPayableNow() ?? 0.0, 2);
        $montantTaxe = round($bordereau->getMontantTaxePayableNow() ?? 0.0, 2);
        $montantTotal = round($montantHT + $montantTaxe, 2);
        $montantPaye  = round($this->getNoteMontantPaye($note), 2);
        $solde        = round($montantTotal - $montantPaye, 2);

        $tauxTaxe = ($montantHT > 0 && $montantTaxe > 0)
            ? round(($montantTaxe / $montantHT) * 100, 2)
            : 0.0;

        $statutPaiement = match (true) {
            $montantTotal == 0 && $montantPaye == 0 => 'N/A',
            $montantPaye >= $montantTotal            => 'Payée',
            $montantPaye > 0                         => 'Partiel',
            default                                  => 'Impayée',
        };

        return [
            'typeString'       => $this->getNoteTypeString($note),
            'addressedToString'=> $this->calculationHelper->getNoteAddressedToString($note),
            'montantTotal'     => $montantTotal,
            'montantPaye'      => $montantPaye,
            'solde'            => $solde,
            // ⚠ LES DEUX CHEMINS DE CALCUL, OU AUCUN. Une note de bordereau tire ses
            // montants du bordereau et non de ses articles : oublier cette ligne
            // priverait du bouton de règlement toutes les notes issues d'un bordereau,
            // c'est-à-dire la plupart.
            'aUnSoldeDu'       => $this->aUnSoldeDu($solde),
            'statutPaiement'   => $statutPaiement,
            'montantTaxe'      => $montantTaxe,
            'nomTaxe'          => 'Taxe',
            'tauxTaxe'         => $tauxTaxe,
        ];
    }

    private function getNoteMontantTaxe(Note $note): float
    {
        // Les notes de crédit (rétro-commission, paiement de taxe) sont considérées comme HT.
        if ($note->getType() === Note::TYPE_NOTE_DE_CREDIT) {
            return 0.0;
        }
        // Pour les notes de débit, la taxe est la différence entre le montant TTC et le montant HT.
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

    /**
     * Reste-t-il quelque chose à encaisser sur cette note ?
     *
     * Le seuil est celui du projet — `SourceDeFacturation::SEUIL_SOLDE` et
     * `NoteRecouvrementService` le partagent déjà : en deçà d'un centime, un solde relève
     * de l'arrondi comptable et non d'une créance. On n'en crée pas un troisième.
     */
    private function aUnSoldeDu(float $solde): bool
    {
        return round($solde, 2) > 0.01;
    }

    private function getNoteSolde(Note $note): float
    {
        return $this->getNoteMontantPayable($note) - $this->getNoteMontantPaye($note);
    }

    private function getNoteStatutPaiementString(?Note $note): ?string
    {
        if ($note === null) return null;

        $montantDu = $this->getNoteMontantPayable($note);
        $montantPaye = $this->getNoteMontantPaye($note);

        if ($montantDu == 0 && $montantPaye == 0) {
            return 'N/A';
        }
        if ($montantPaye >= $montantDu) {
            return 'Payée';
        }
        if ($montantPaye > 0 && $montantPaye < $montantDu) {
            return 'Partiel';
        }
        return 'Impayée';
    }

    private function getNoteNomTaxe(Note $note): ?string
    {
        // On prend le premier article pour déterminer le contexte de la taxe.
        $firstArticle = $note->getArticles()->first();
        if (!$firstArticle || !$firstArticle->getRevenuFacture()) {
            return 'Taxe';
        }

        $revenu = $firstArticle->getRevenuFacture();
        $isIARD = $this->calculationHelper->isIARD($revenu->getCotation());
        
        // On utilise le service de taxes pour trouver la taxe applicable.
        // Pour une note de débit, la taxe est toujours celle de l'assureur.
        // CORRECTION : On utilise la nouvelle méthode publique du helper.
        $taxe = $this->calculationHelper->getTaxeApplicable($isIARD, true);

        return $taxe?->getCode() ?? 'Taxe';
    }

    private function getNoteTauxTaxe(Note $note, float $montantHT): ?float
    {
        $montantTaxe = $this->getNoteMontantTaxe($note);
        if ($montantHT > 0 && $montantTaxe > 0) {
            return ($montantTaxe / $montantHT) * 100;
        }
        return 0.0;
    }
}