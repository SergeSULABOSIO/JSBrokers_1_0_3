<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\DepenseCourtier;
use App\Services\ServiceDates;

class DepenseCourtierIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === DepenseCourtier::class;
    }

    public function calculate(object $entity): array
    {
        /** @var DepenseCourtier $entity */
        $ligneSecondaire = sprintf(
            '%s · %s',
            $entity->getDateDepense()?->format('d/m/Y') ?? 'Sans date',
            $entity->getMoyenPaiementLabel(),
        );
        if ($entity->getReference()) {
            $ligneSecondaire .= ' · Réf. ' . $entity->getReference();
        }

        return [
            'ligne_principale'   => trim(sprintf('%s — %s', $entity->getCharge()?->getLibelle() ?? 'Dépense', $entity->getTiersLibelle() ?? ''), ' —'),
            'ligne_secondaire'   => $ligneSecondaire,
            'tiersLibelle'       => $entity->getTiersLibelle() ?? 'Aucun tiers renseigné',
            'fournisseurNom'     => $entity->getFournisseur()?->getNom() ?? '',
            'chargeLibelle'      => $entity->getCharge()?->getLibelle() ?? '',
            'compteOhadaFull'    => $entity->getCharge() !== null
                ? sprintf('%s — %s', $entity->getCharge()->getCompteOhada(), $entity->getCharge()->getCompteOhadaLabel())
                : '',
            'statutLabel'        => $entity->getStatutLabel(),
            'moyenPaiementLabel' => $entity->getMoyenPaiementLabel(),
            'montantTtc'         => round($entity->getMontantFloat(), 2),
            'montantHt'          => round($entity->getMontantHtFloat(), 2),
            'tvaDeductible'      => round($entity->getTvaDeductibleFloat(), 2),
        ];
    }
}
