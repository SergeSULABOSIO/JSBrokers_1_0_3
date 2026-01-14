<?php

namespace App\Services;

use DateTimeImmutable;
use App\Constantes\Constante;
use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
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
        // 1. Charge les valeurs basées sur la définition du canevas (champs de type 'Calcul')
        $entityCanvas = $this->entityCanvasProvider->getCanvas(get_class($entity));
        if (isset($entityCanvas['liste'])) {
            foreach ($entityCanvas['liste'] as $field) {
                if (($field['type'] ?? null) === 'Calcul') {
                    $functionName = $field['fonction'];
                    $args = [];

                    if (!empty($field['params'])) {
                        $paramNames = $field['params'];
                        $args = array_map(function ($paramName) use ($entity) {
                            $getter = 'get' . ucfirst($paramName);
                            return method_exists($entity, $getter) ? $entity->$getter() : null;
                        }, $paramNames);
                    } else {
                        $args[] = $entity;
                    }

                    if (method_exists($this->calculationProvider, $functionName)) {
                        $entity->{$field['code']} = $this->calculationProvider->$functionName(...$args);
                    }
                }
            }
        }

        // 2. Charge les indicateurs spécifiques (ex: Âge du dossier, Taux de transfo., etc.)
        $specificIndicators = $this->calculationProvider->getIndicateursSpecifics($entity);
        foreach ($specificIndicators as $key => $value) {
            $entity->{$key} = $value;
        }
    }
}