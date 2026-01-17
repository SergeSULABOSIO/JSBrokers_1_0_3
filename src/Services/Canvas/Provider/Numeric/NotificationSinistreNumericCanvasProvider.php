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
            "dommageAvantEvaluation" => [
                "description" => "Dommages (av. éval.)",
                "value" => ($object->getDommage() ?? 0) * 100,
            ],
            'dommageApresEvaluation' => [
                "description" => "Dommages (ap. éval.)",
                "value" => ($object->getEvaluationChiffree() ?? 0) * 100,
            ],
            'franchise' => [
                "description" => "Franchise",
                "value" => ($this->getFranchiseForNotificationSinistre($object) ?? 0) * 100,
            ],
        ], $this->getCalculatedIndicatorsNumericAttributes($object));
    }

    private function getFranchiseForNotificationSinistre(NotificationSinistre $sinistre): float
    {
        return array_reduce($sinistre->getOffreIndemnisationSinistres()->toArray(), function ($carry, $offre) {
            return $carry + ($offre->getFranchiseAppliquee() ?? 0);
        }, 0.0);
    }
}
