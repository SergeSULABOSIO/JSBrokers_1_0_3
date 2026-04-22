<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\AutoriteFiscale;
use App\Entity\Entreprise;
use App\Entity\Note;
use App\Entity\Taxe;
use Doctrine\ORM\EntityManagerInterface;

class AutoriteFiscaleIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private IndicatorCalculationHelper $calculationHelper,
        private EntityManagerInterface $em
    ) {}

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === AutoriteFiscale::class;
    }

    public function calculate(object $entity): array
    {
        /** @var AutoriteFiscale $entity */
        $stats = $this->calculateAutoriteFiscaleStats($entity);

        return [
            'taxeDue' => round($stats['due'], 2),
            'taxePayee' => round($stats['paid'], 2),
            'taxeSolde' => round($stats['balance'], 2),
        ];
    }

    private function calculateAutoriteFiscaleStats(AutoriteFiscale $autorite): array
    {
        $due = 0.0;
        $paid = 0.0;
        $taxeCible = $autorite->getTaxe();

        if (!$taxeCible) {
            return ['due' => 0.0, 'paid' => 0.0, 'balance' => 0.0];
        }

        $redevableCible = $taxeCible->getRedevable();
        $entreprise = $autorite->getTaxe()->getEntreprise();
        // CORRECTION : On utilise maintenant la relation directe.
        // On recherche les revenus liés à l'entreprise de la taxe de l'autorité.
        $revenus = $this->em->getRepository(\App\Entity\RevenuPourCourtier::class)->findBy([
            'entreprise' => $entreprise
        ]);

        foreach ($revenus as $revenu) {
            $isIARD = $this->calculationHelper->isIARD($revenu->getCotation());
            // Le taux est stocké en % (ex: 16), on le convertit en facteur (ex: 0.16) pour le calcul.
            $tauxBrut = $isIARD ? $taxeCible->getTauxIARD() : $taxeCible->getTauxVIE();

            if ($tauxBrut > 0) {
                $montantHT = $this->calculationHelper->getRevenuMontantHt($revenu);
                $taxeCalculee = $montantHT * ($tauxBrut / 100);
                $due += $taxeCalculee;
            }
        }

        // Calcul du montant payé en parcourant les notes de l'autorité
        foreach ($autorite->getNotes() as $note) {
            if ($note->getType() === Note::TYPE_NOTE_DE_DEBIT) {
                $paid += $this->calculationHelper->getNoteMontantPaye($note);
            }
        }

        return [
            'due' => $due,
            'paid' => $paid,
            'balance' => $due - $paid,
        ];
    }
}