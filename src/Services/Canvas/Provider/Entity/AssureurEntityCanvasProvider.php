<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Assureur;
use App\Entity\Bordereau;
use App\Entity\Cotation;
use App\Entity\NotificationSinistre;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class AssureurEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Assureur::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Assureur",
                "icone" => "assureur",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "L'assureur [[*nom]] est une entité clé de notre portefeuille.",
                    " Contactable par email à l'adresse [[email]], par téléphone au [[telephone]] et physiquement à [[adressePhysique]].",
                    " Les informations légales sont : N° Impôt [[numimpot]], ID.NAT [[idnat]], et RCCM [[rccm]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "email", "intitule" => "Email", "type" => "Texte"],
                ["code" => "telephone", "intitule" => "Téléphone", "type" => "Texte"],
                ["code" => "url", "intitule" => "Site Web", "type" => "Texte"],
                ["code" => "adressePhysique", "intitule" => "Adresse", "type" => "Texte"],
                ["code" => "numimpot", "intitule" => "N° Impôt", "type" => "Texte"],
                ["code" => "idnat", "intitule" => "ID.NAT", "type" => "Texte"],
                ["code" => "rccm", "intitule" => "RCCM", "type" => "Texte"],
                ["code" => "cotations", "intitule" => "Cotations", "type" => "Collection", "targetEntity" => Cotation::class, "displayField" => "nom"],
                ["code" => "bordereaus", "intitule" => "Bordereaux", "type" => "Collection", "targetEntity" => Bordereau::class, "displayField" => "nom"],
                ["code" => "notificationSinistres", "intitule" => "Sinistres", "type" => "Collection", "targetEntity" => NotificationSinistre::class, "displayField" => "referenceSinistre"],
            ], $this->getSpecificIndicators(), $this->canvasHelper->getGlobalIndicatorsCanvas("Assureur"))
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["code" => "nombrePolicesSouscrites", "intitule" => "Nb. Polices", "type" => "Entier", "format" => "Nombre", "description" => "Nombre total de polices souscrites auprès de cet assureur."],
            ["code" => "nombreSinistresGeres", "intitule" => "Nb. Sinistres", "type" => "Entier", "format" => "Nombre", "description" => "Nombre total de sinistres gérés par cet assureur."],
            ["code" => "tauxTransformationCotations", "intitule" => "Taux Transfo.", "type" => "Texte", "format" => "Texte", "description" => "Pourcentage de cotations transformées en polices d'assurance."],
        ];
    }
}