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
    public function __construct(
        private EntityCanvasProvider $entityCanvasProvider,
        private SearchCanvasProvider $searchCanvasProvider,
        private ListCanvasProvider $listCanvasProvider,
        private FormCanvasProvider $formCanvasProvider,
        private NumericCanvasProvider $numericCanvasProvider,
        private CalculationProvider $calculationProvider
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
     * Calcule le délai en jours entre la survenance et la notification d'un sinistre.
     */
    public function Notification_Sinistre_getDelaiDeclaration(NotificationSinistre $sinistre): string
    {
        return $this->calculationProvider->Notification_Sinistre_getDelaiDeclaration($sinistre);
    }

    /**
     * Calcule l'âge du dossier sinistre depuis sa création.
     */
    public function Notification_Sinistre_getAgeDossier(NotificationSinistre $sinistre): string
    {
        return $this->calculationProvider->Notification_Sinistre_getAgeDossier($sinistre);
    }

    /**
     * Calcule le pourcentage de pièces fournies par rapport aux pièces attendues.
     */
    public function Notification_Sinistre_getIndiceCompletude(NotificationSinistre $sinistre): string
    {
        return $this->calculationProvider->Notification_Sinistre_getIndiceCompletude($sinistre);
    }

    /**
     * Calcule le pourcentage payé d'une offre d'indemnisation.
     */
    public function Offre_Indemnisation_getPourcentagePaye(OffreIndemnisationSinistre $offre): string
    {
        return $this->calculationProvider->Offre_Indemnisation_getPourcentagePaye($offre);
    }
}