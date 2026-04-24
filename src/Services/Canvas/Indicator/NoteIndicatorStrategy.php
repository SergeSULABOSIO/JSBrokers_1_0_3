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
        $montantTotal = round($this->getNoteMontantPayable($entity), 2);
        $montantTaxe = round($this->getNoteMontantTaxe($entity), 2);
        $montantHT = $montantTotal - $montantTaxe;

        return [
            'typeString' => $this->getNoteTypeString($entity),
            'addressedToString' => $this->getNoteAddressedToString($entity),
            'montantTotal' => $montantTotal,
            'montantPaye' => round($this->getNoteMontantPaye($entity), 2),
            'solde' => round($this->getNoteSolde($entity), 2),
            'statutPaiement' => $this->getNoteStatutPaiementString($entity),
            'montantTaxe' => $montantTaxe,
            'nomTaxe' => $this->getNoteNomTaxe($entity),
            'tauxTaxe' => $this->getNoteTauxTaxe($entity, $montantHT),
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

    private function getNoteAddressedToString(?Note $note): ?string
    {
        if ($note === null) return null;

        switch ($note->getAddressedTo()) {
            case Note::TO_CLIENT:
                return $note->getClient()?->getNom() ?? 'Client';

            case Note::TO_ASSUREUR:
                return $note->getAssureur()?->getNom() ?? 'Assureur';

            case Note::TO_PARTENAIRE:
                return $note->getPartenaire()?->getNom() ?? 'Intermédiaire';

            case Note::TO_AUTORITE_FISCALE:
                if ($autorite = $note->getAutoritefiscale()) {
                    $nom = $autorite->getNom();
                    $abbreviation = $autorite->getAbreviation();
                    // Si une abréviation existe et n'est pas vide, on la préfixe au nom complet.
                    if ($abbreviation && trim($abbreviation) !== '') {
                        return trim($abbreviation) . ' - ' . $nom;
                    }
                    return $nom;
                }
                return 'Autorité Fiscale';

            default:
                return 'Inconnu';
        }
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
        $taxe = $this->calculationHelper->serviceTaxes->getTaxeApplicable($isIARD, true);

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