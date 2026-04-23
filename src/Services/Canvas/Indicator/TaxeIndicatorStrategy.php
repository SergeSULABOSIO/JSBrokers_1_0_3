<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Taxe;
use App\Services\ServiceDates;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class TaxeIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator,
        private IndicatorCalculationHelper $calculationHelper,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Taxe::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Taxe $entity */
        $stats = $this->calculateTaxeStats($entity);

        return [
            'redevableString' => $this->getTaxeRedevableString($entity),
            'nombreAutorites' => $entity->getAutoriteFiscales()->count(),
            // CORRECTION: Le taux est déjà en pourcentage dans la BDD (ex: 16.00), pas besoin de multiplier par 100.
            'tauxIARDPercent' => (float)($entity->getTauxIARD() ?? 0),
            'tauxVIEPercent' => (float)($entity->getTauxVIE() ?? 0),
            'montantTaxeTotal' => round($stats['due'], 2),
            'montantTaxePaye' => round($stats['paid'], 2),
            'soldeRestantDu' => round($stats['balance'], 2),
        ];
    }

    // --- Méthodes privées déplacées depuis CalculationProvider ---

    private function getTaxeRedevableString(Taxe $taxe): string
    {
        return match ($taxe->getRedevable()) {
            Taxe::REDEVABLE_ASSUREUR => "L'assureur",
            Taxe::REDEVABLE_COURTIER => "Le courtier",
            default => "Non défini",
        };
    }

    private function calculateTaxeStats(Taxe $taxe): array
    {
        $due = 0.0;
        $paid = 0.0;

        $entreprise = $taxe->getEntreprise();
        if (!$entreprise) {
            return ['due' => 0.0, 'paid' => 0.0, 'balance' => 0.0];
        }

        // 1. Calcul du montant total dû
        // On parcourt tous les revenus de l'entreprise pour appliquer le taux de la taxe en cours.
        $revenus = $this->em->getRepository(\App\Entity\RevenuPourCourtier::class)->findBy(['entreprise' => $entreprise]);

        foreach ($revenus as $revenu) {
            $isIARD = $this->calculationHelper->isIARD($revenu->getCotation());
            $tauxBrut = $isIARD ? $taxe->getTauxIARD() : $taxe->getTauxVIE();

            if ($tauxBrut > 0) {
                $montantHT = $this->calculationHelper->getRevenuMontantHt($revenu);
                // Le taux est stocké en pourcentage (ex: 16), on le divise par 100 pour le calcul.
                $taxeCalculee = $montantHT * ($tauxBrut / 100);
                $due += $taxeCalculee;
            }
        }

        // 2. Calcul du montant total payé
        // On parcourt toutes les autorités fiscales liées à cette taxe, puis leurs notes et paiements.
        foreach ($taxe->getAutoriteFiscales() as $autorite) {
            foreach ($autorite->getNotes() as $note) {
                $paid += $this->calculationHelper->getNoteMontantPaye($note);
            }
        }

        // 3. Calcul du solde
        $balance = $due - $paid;

        return ['due' => $due, 'paid' => $paid, 'balance' => $balance];
    }
}