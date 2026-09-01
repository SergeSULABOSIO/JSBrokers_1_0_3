<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Entreprise;
use App\Entity\JourFerie;

class JourFerieEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === JourFerie::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Jour férié",
                "icone" => "jour-ferie",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "[[*libelle]], le [[dateLibelle]].",
                    " Exercice [[exercice]].",
                ],
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "libelle", "intitule" => "Libellé", "type" => "Texte"],
                ["code" => "date", "intitule" => "Date", "type" => "Date"],
                ["code" => "exercice", "intitule" => "Exercice", "type" => "Entier"],
                ["code" => "entreprise", "intitule" => "Entreprise", "type" => "Relation", "targetEntity" => Entreprise::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators()),
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["group" => "Calendrier", "code" => "dateLibelle", "intitule" => "Date", "type" => "Calcul", "format" => "Texte", "description" => "Jour de la semaine et date du férié."],
        ];
    }
}
