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
            "primeTranche" => GrandeurTotalisable::montant("Prime Tranche", $object->primeTranche ?? null),
            "montantCalculeHT" => GrandeurTotalisable::montant("Montant HT", $object->montantCalculeHT ?? null),
            "montantCalculeTTC" => GrandeurTotalisable::montant("Montant TTC", $object->montantCalculeTTC ?? null),
            "montant_du" => GrandeurTotalisable::montant("Montant Dû", $object->montant_du ?? null),
            "commissionExigible" => GrandeurTotalisable::montant("Commission Exigible", $object->commissionExigible ?? null),
            "retroCommissionExigible" => GrandeurTotalisable::montant("Rétro Exigible", $object->retroCommissionExigible ?? null),
            "primeDeclareePayee" => GrandeurTotalisable::montant("Prime Signalée Payée", $object->primeDeclareePayee ?? null),
            "resteAPayer" => GrandeurTotalisable::montant("Reste à Payer", $object->resteAPayer ?? null),
        ], $this->getCalculatedIndicatorsNumericAttributes($object));
    }
}
