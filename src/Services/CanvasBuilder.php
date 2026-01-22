<?php

namespace App\Services;
use App\Services\Canvas\CalculationProvider;
use App\Services\Canvas\EntityCanvasProvider;
use App\Services\Canvas\FormCanvasProvider;
use App\Services\Canvas\ListCanvasProvider;
use App\Services\Canvas\NumericCanvasProvider;
use App\Services\Canvas\SearchCanvasProvider;

class CanvasBuilder
{
    // Le constructeur injecte maintenant les "résolveurs" principaux,
    // et non plus les fournisseurs monolithiques.
    public function __construct(
        private EntityCanvasProvider $entityCanvasProvider,
        private SearchCanvasProvider $searchCanvasProvider,
        private ListCanvasProvider $listCanvasProvider,
        private FormCanvasProvider $formCanvasProvider,
        private NumericCanvasProvider $numericCanvasProvider,
        private CalculationProvider $calculationProvider // Ajout du CalculationProvider
    ) {
    }

    public function getEntityCanvas(string $entityClassName): array
    {
        return $this->entityCanvasProvider->getCanvas($entityClassName);
    }

    public function getSearchCanvas(string $entityClassName): array
    {
        return $this->searchCanvasProvider->getCanvas($entityClassName);
    }

    public function getListeCanvas(string $entityClassName): array
    {
        return $this->listCanvasProvider->getCanvas($entityClassName);
    }

    public function getEntityFormCanvas($object, ?int $idEntreprise = null): array
    {
        return $this->formCanvasProvider->getCanvas($object, $idEntreprise);
    }

    public function getNumericAttributesAndValues($object): array
    {
        return $this->numericCanvasProvider->getAttributesAndValues($object);
    }

    public function getNumericAttributesAndValuesForCollection($data): array
    {
        return $this->numericCanvasProvider->getAttributesAndValuesForCollection($data);
    }

    /**
     * NOUVEAU : Charge toutes les valeurs calculées pour une entité donnée.
     * Cette méthode centralise la logique qui était auparavant dans ControllerUtilsTrait.
     *
     * @param object $entity L'entité à enrichir avec les valeurs calculées.
     */
    public function loadAllCalculatedValues(object $entity): void
    {
        // La seule responsabilité de cette méthode est de demander au CalculationProvider
        // de faire son travail. Le CalculationProvider est maintenant le seul à savoir
        // COMMENT les calculs sont effectués, ce qui respecte le principe de responsabilité unique.
        // L'ancienne logique d'appel dynamique basée sur la configuration du canvas a été supprimée
        // au profit de cette approche plus robuste et mieux encapsulée.
        $specificIndicators = $this->calculationProvider->getIndicateursSpecifics($entity);
        foreach ($specificIndicators as $key => $value) {
            $entity->{$key} = $value;
        }
    }
}