<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\RegimeTravail;

class RegimeTravailEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RegimeTravail::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Régime de travail",
                "icone" => "regime-travail",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Régime de [[agent]] : [[joursLibelle]].",
                    " Taux : [[tauxLibelle]].",
                    " [[periodeLibelle]].",
                ],
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "agent", "intitule" => "Collaborateur", "type" => "Relation", "targetEntity" => Invite::class, "displayField" => "nom"],
                ["code" => "tauxOccupation", "intitule" => "Taux d'occupation", "type" => "Nombre"],
                ["code" => "dateDebut", "intitule" => "En vigueur depuis", "type" => "Date"],
                ["code" => "dateFin", "intitule" => "Jusqu'au", "type" => "Date"],
                ["code" => "entreprise", "intitule" => "Entreprise", "type" => "Relation", "targetEntity" => Entreprise::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators()),
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["group" => "Temps de travail", "code" => "joursLibelle", "intitule" => "Jours travaillés", "type" => "Calcul", "format" => "Texte", "description" => "Jours de la semaine effectivement travaillés."],
            ["group" => "Temps de travail", "code" => "tauxLibelle", "intitule" => "Taux", "type" => "Calcul", "format" => "Texte", "description" => "Taux d'occupation exprimé en pourcentage."],
            ["group" => "Temps de travail", "code" => "periodeLibelle", "intitule" => "Période", "type" => "Calcul", "format" => "Texte", "description" => "Période de validité du régime."],
        ];
    }
}
