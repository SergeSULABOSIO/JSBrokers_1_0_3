<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Client;
use App\Entity\Contact;
use App\Entity\Document;
use App\Entity\Groupe;
use App\Entity\NotificationSinistre;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class ClientEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Client::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Client",
                "icone" => "client",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Client [[*nom]].",
                    " Contact: [[email]] / [[telephone]].",
                    " Adresse: [[adresse]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "email", "intitule" => "Email", "type" => "Texte"],
                ["code" => "telephone", "intitule" => "Téléphone", "type" => "Texte"],
                ["code" => "adresse", "intitule" => "Adresse", "type" => "Texte"],
                ["code" => "groupe", "intitule" => "Groupe", "type" => "Relation", "targetEntity" => Groupe::class, "displayField" => "nom"],
                ["code" => "contacts", "intitule" => "Contacts", "type" => "Collection", "targetEntity" => Contact::class, "displayField" => "nom"],
                ["code" => "pistes", "intitule" => "Pistes", "type" => "Collection", "targetEntity" => Piste::class, "displayField" => "nom"],
                ["code" => "notificationSinistres", "intitule" => "Sinistres", "type" => "Collection", "targetEntity" => NotificationSinistre::class, "displayField" => "referenceSinistre"],
                ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
                ["code" => "partenaires", "intitule" => "Partenaires", "type" => "Collection", "targetEntity" => Partenaire::class, "displayField" => "nom"], // Note: This collection is not directly related to global indicators.
            ], $this->getSpecificIndicators(), $this->canvasHelper->getGlobalIndicatorsCanvas("Client"))
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["code" => "civiliteString", "intitule" => "Civilité", "type" => "Calcul", "format" => "Texte", "description" => "Forme juridique ou civilité du client."],
            ["code" => "nombrePistes", "intitule" => "Nb. Pistes", "type" => "Calcul", "format" => "Nombre", "description" => "Nombre total de pistes commerciales ouvertes pour ce client."],
            ["code" => "nombreSinistres", "intitule" => "Nb. Sinistres", "type" => "Calcul", "format" => "Nombre", "description" => "Nombre total de sinistres déclarés par ce client."],
            ["code" => "nombrePolices", "intitule" => "Nb. Polices", "type" => "Calcul", "format" => "Nombre", "description" => "Nombre de polices d'assurance actives (cotations transformées) pour ce client."],
        ];
    }
}