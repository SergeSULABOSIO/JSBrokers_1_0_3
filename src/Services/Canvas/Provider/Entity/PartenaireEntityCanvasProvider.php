<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Document;
use App\Entity\Note;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class PartenaireEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Partenaire::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Partenaire",
                "icone" => "partenaire",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Partenaire [[*nom]].",
                    " Contact: [[email]] / [[telephone]].",
                    " Part: [[part]]%."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "adressePhysique", "intitule" => "Adresse", "type" => "Texte"],
                ["code" => "telephone", "intitule" => "Téléphone", "type" => "Texte"],
                ["code" => "email", "intitule" => "Email", "type" => "Texte"],
                ["code" => "part", "intitule" => "Part (%)", "type" => "Nombre", "unite" => "%"],
                ["code" => "idnat", "intitule" => "ID.NAT", "type" => "Texte"],
                ["code" => "numimpot", "intitule" => "N° Impôt", "type" => "Texte"],
                ["code" => "rccm", "intitule" => "RCCM", "type" => "Texte"],
                ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
                ["code" => "conditionPartages", "intitule" => "Conditions de partage", "type" => "Collection", "targetEntity" => ConditionPartage::class, "displayField" => "nom"],
                ["code" => "pistes", "intitule" => "Pistes", "type" => "Collection", "targetEntity" => Piste::class, "displayField" => "nom"],
                ["code" => "notes", "intitule" => "Notes", "type" => "Collection", "targetEntity" => Note::class, "displayField" => "reference"],
                ["code" => "clients", "intitule" => "Clients", "type" => "Collection", "targetEntity" => Client::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators(), $this->canvasHelper->getGlobalIndicatorsCanvas("Partenaire"))
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["code" => "nombrePistesApportees", "intitule" => "Nb. Pistes", "type" => "Entier", "format" => "Nombre", "description" => "Nombre de pistes apportées par ce partenaire."],
            ["code" => "nombreClientsAssocies", "intitule" => "Nb. Clients", "type" => "Entier", "format" => "Nombre", "description" => "Nombre de clients associés à ce partenaire."],
            ["code" => "nombrePolicesGenerees", "intitule" => "Nb. Polices", "type" => "Entier", "format" => "Nombre", "description" => "Nombre de polices générées via ce partenaire."],
            ["code" => "nombreConditionsPartage", "intitule" => "Nb. Conditions", "type" => "Entier", "format" => "Nombre", "description" => "Nombre de conditions de partage définies pour ce partenaire."],
        ];
    }
}