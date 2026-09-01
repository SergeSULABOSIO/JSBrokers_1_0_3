<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Entreprise;
use App\Entity\ParametresConge;
use App\Entity\PeriodeBlocage;

class PeriodeBlocageEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === PeriodeBlocage::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Période de blocage",
                "icone" => "action:resiliation",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "[[*libelle]].",
                    " [[periodeLibelle]].",
                ],
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "libelle", "intitule" => "Motif", "type" => "Texte"],
                ["code" => "dateDebut", "intitule" => "Du", "type" => "Date"],
                ["code" => "dateFin", "intitule" => "Au", "type" => "Date"],
                ["code" => "actif", "intitule" => "Active", "type" => "Booleen"],
                ["code" => "parametres", "intitule" => "Paramètres", "type" => "Relation", "targetEntity" => ParametresConge::class, "displayField" => "id"],
                ["code" => "entreprise", "intitule" => "Entreprise", "type" => "Relation", "targetEntity" => Entreprise::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators()),
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["group" => "Période", "code" => "periodeLibelle", "intitule" => "Période", "type" => "Calcul", "format" => "Texte", "description" => "Dates du blocage, et mention explicite s'il est désactivé."],
        ];
    }
}
