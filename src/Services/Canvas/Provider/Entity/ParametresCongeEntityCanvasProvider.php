<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Entreprise;
use App\Entity\ParametresConge;
use App\Entity\PeriodeBlocage;

class ParametresCongeEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ParametresConge::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Paramètres des congés",
                "icone" => "action:settings",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Réglages des congés : [[*resumeReglages]].",
                ],
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "delaiPreavisJours", "intitule" => "Délai de préavis", "type" => "Entier", "unite" => "j"],
                ["code" => "maxAbsentsSimultanes", "intitule" => "Absents simultanés max", "type" => "Entier"],
                ["code" => "relanceApresJours", "intitule" => "Relance après", "type" => "Entier", "unite" => "j"],
                ["code" => "dotationAnnuelle", "intitule" => "Dotation annuelle", "type" => "Nombre", "unite" => "j"],
                ["code" => "seuilAlerteReport", "intitule" => "Seuil d'alerte du report", "type" => "Nombre"],
                ["code" => "periodesBlocage", "intitule" => "Périodes de blocage", "type" => "Collection", "targetEntity" => PeriodeBlocage::class, "displayField" => "libelle"],
                ["code" => "entreprise", "intitule" => "Entreprise", "type" => "Relation", "targetEntity" => Entreprise::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators()),
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["group" => "Réglages", "code" => "resumeReglages", "intitule" => "Résumé", "type" => "Calcul", "format" => "Texte", "description" => "Les réglages en une ligne, contrôles désactivés compris."],
            ["group" => "Réglages", "code" => "nombrePeriodesBlocage", "intitule" => "Périodes de blocage", "type" => "Calcul", "format" => "Entier", "description" => "Nombre de périodes déclarées, actives ou non."],
        ];
    }
}
