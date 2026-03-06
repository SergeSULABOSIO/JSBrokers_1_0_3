<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Assureur;
use App\Entity\Bordereau;
use App\Entity\Document;
use App\Services\ServiceMonnaies;

class BordereauEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Bordereau::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Bordereau",
                "icone" => "bordereau",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Bordereau [[*nom]] de l'assureur [[assureur]]",
                    ", reçu le [[receivedAt]]",
                    " pour un montant total de [[montantTTC]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "assureur", "intitule" => "Assureur", "type" => "Relation", "targetEntity" => Assureur::class, "displayField" => "nom"],
                ["code" => "montantTTC", "intitule" => "Montant TTC", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                ["code" => "receivedAt", "intitule" => "Reçu le", "type" => "Date"],
                ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"], // Note: This collection is not directly related to global indicators.
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["group" => "Analyse du Bordereau", "code" => "typeString", "intitule" => "Type", "type" => "Calcul", "format" => "Texte", "description" => "Type de bordereau."],
            ["group" => "Analyse du Bordereau", "code" => "ageBordereau", "intitule" => "Âge (depuis réception)", "type" => "Calcul", "format" => "Texte", "description" => "Nombre de jours écoulés depuis la date de réception."],
            ["group" => "Analyse du Bordereau", "code" => "delaiSoumission", "intitule" => "Délai de soumission", "type" => "Calcul", "format" => "Texte", "description" => "Nombre de jours entre la création et la réception du bordereau."],
            ["group" => "Analyse du Bordereau", "code" => "nombreDocuments", "intitule" => "Nombre de documents", "type" => "Calcul", "format" => "Nombre", "description" => "Nombre de documents joints à ce bordereau."],
        ];
    }
}