<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\AutoriteFiscale;
use App\Entity\Entreprise;
use App\Entity\Taxe;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class TaxeEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Taxe::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Taxe",
                "icone" => "taxe",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Taxe [[*code]] - [[description]].",
                    " Taux: [[tauxIARD]]% (IARD) / [[tauxVIE]]% (VIE).",
                    " Redevable: [[redevableString]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "code", "intitule" => "Code", "type" => "Texte"],
                ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                ["code" => "tauxIARD", "intitule" => "Taux IARD", "type" => "Nombre", "format" => "Pourcentage"],
                ["code" => "tauxVIE", "intitule" => "Taux VIE", "type" => "Nombre", "format" => "Pourcentage"],
                ["code" => "redevable", "intitule" => "Redevable", "type" => "Texte"], // Valeur brute
                ["code" => "entreprise", "intitule" => "Entreprise", "type" => "Relation", "targetEntity" => Entreprise::class, "displayField" => "nom"],
                ["code" => "autoriteFiscales", "intitule" => "Autorités Fiscales", "type" => "Collection", "targetEntity" => AutoriteFiscale::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["group" => "Détails", "code" => "redevableString", "intitule" => "Redevable", "type" => "Calcul", "format" => "Texte", "description" => "Entité redevable de la taxe."],
            ["group" => "Statistiques", "code" => "nombreAutorites", "intitule" => "Nb. Autorités", "type" => "Calcul", "format" => "Nombre", "description" => "Nombre d'autorités fiscales associées."],
        ];
    }
}