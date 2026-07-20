<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\Tranche;

class TrancheNumericCanvasProvider implements NumericCanvasProviderInterface
{
    use CalculatedIndicatorsNumericProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Tranche::class;
    }

    /**
     * Tranche calcule ses indicateurs financiers sous des noms propres
     * (TrancheIndicatorStrategy : primeTranche, montantCalculeHT/TTC…) qui ne
     * correspondent PAS à la nomenclature générique du trait partagé (primeTotale,
     * montantHT/TTC…) utilisée par Cotation/Avenant/Client/Piste. Le trait seul
     * laissait donc la barre des totaux SANS « Prime Tranche » (l'équivalent de la
     * prime totale), ni « Commission Exigible »/« Rétro Exigible » (indicateurs sans
     * équivalent générique) — complétés ici, comme Bordereau/Note l'ont fait pour
     * leur propre nomenclature.
     */
    public function getCanvas(object $object): array
    {
        /** @var Tranche $object */
        return array_merge([
            "primeTranche" => [
                "description" => "Prime Tranche",
                "value" => ($object->primeTranche ?? 0) * 100,
            ],
            "montantCalculeHT" => [
                "description" => "Montant HT",
                "value" => ($object->montantCalculeHT ?? 0) * 100,
            ],
            "montantCalculeTTC" => [
                "description" => "Montant TTC",
                "value" => ($object->montantCalculeTTC ?? 0) * 100,
            ],
            "montant_du" => [
                "description" => "Montant Dû",
                "value" => ($object->montant_du ?? 0) * 100,
            ],
            "commissionExigible" => [
                "description" => "Commission Exigible",
                "value" => ($object->commissionExigible ?? 0) * 100,
            ],
            "retroCommissionExigible" => [
                "description" => "Rétro Exigible",
                "value" => ($object->retroCommissionExigible ?? 0) * 100,
            ],
            "primeDeclareePayee" => [
                "description" => "Prime Signalée Payée",
                "value" => ($object->primeDeclareePayee ?? 0) * 100,
            ],
            "resteAPayer" => [
                "description" => "Reste à Payer",
                "value" => ($object->resteAPayer ?? 0) * 100,
            ],
        ], $this->getCalculatedIndicatorsNumericAttributes($object));
    }
}
