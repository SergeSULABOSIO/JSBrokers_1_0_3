<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\NotificationSinistre;

class NotificationSinistreNumericCanvasProvider implements NumericCanvasProviderInterface
{
    use CalculatedIndicatorsNumericProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === NotificationSinistre::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var NotificationSinistre $object */
        return array_merge([
            "dommageAvantEvaluation" => GrandeurTotalisable::montant("Dommages (av. éval.)", $object->getDommage()),
            'dommageApresEvaluation' => GrandeurTotalisable::montant("Dommages (ap. éval.)", $object->getEvaluationChiffree()),
            'franchise' => GrandeurTotalisable::montant("Franchise", $this->getFranchiseForNotificationSinistre($object)),
        ], $this->getCalculatedIndicatorsNumericAttributes($object));
    }

    private function getFranchiseForNotificationSinistre(NotificationSinistre $sinistre): float
    {
        return array_reduce($sinistre->getOffreIndemnisationSinistres()->toArray(), function ($carry, $offre) {
            return $carry + ($offre->getFranchiseAppliquee() ?? 0);
        }, 0.0);
    }
}
