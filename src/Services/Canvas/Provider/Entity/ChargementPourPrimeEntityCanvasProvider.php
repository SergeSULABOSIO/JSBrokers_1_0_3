<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Chargement;
use App\Entity\ChargementPourPrime;
use App\Entity\Cotation;
use App\Services\Canvas\CanvasHelper;
use App\Services\Canvas\Provider\Entity\EntityCanvasProviderInterface;
use App\Services\ServiceMonnaies;

class ChargementPourPrimeEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ChargementPourPrime::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Chargement sur Prime",
                "icone" => "chargement",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Chargement [[*nom]] d'un montant de [[montantFlatExceptionel]]",
                    " sur la cotation [[cotation]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "type", "intitule" => "Type de chargement", "type" => "Relation", "targetEntity" => Chargement::class, "displayField" => "nom"],
                ["code" => "cotation", "intitule" => "Cotation", "type" => "Relation", "targetEntity" => Cotation::class, "displayField" => "nom"],
                ["code" => "montantFlatExceptionel", "intitule" => "Montant", "type" => "Nombre", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                ["code" => "createdAt", "intitule" => "Créé le", "type" => "Date"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["group" => "Détails du Montant", "code" => "montant_final", "intitule" => "Montant Final (TTC)", "type" => "Calcul", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "Montant du chargement incluant les taxes applicables."],
            ["group" => "Détails du Montant", "code" => "montantTaxeAppliquee", "intitule" => "Taxe Appliquée", "type" => "Calcul", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "Montant de la taxe calculée sur ce chargement, basé sur la branche du risque."],
            ["group" => "Analyse & Contexte", "code" => "poidsSurPrimeTotale", "intitule" => "Poids sur Prime Totale", "type" => "Calcul", "format" => "Pourcentage", "unite" => "%", "description" => "Pourcentage que ce chargement représente par rapport au montant total de la prime de la cotation."],
            ["group" => "Analyse & Contexte", "code" => "ageChargement", "intitule" => "Âge du Chargement", "type" => "Calcul", "format" => "Texte", "description" => "Nombre de jours écoulés depuis la création de ce chargement."],
            ["group" => "Analyse & Contexte", "code" => "fonctionChargement", "intitule" => "Fonction du Chargement", "type" => "Calcul", "format" => "Texte", "description" => "Rôle fonctionnel de ce type de chargement (ex: Prime nette, Taxe, Frais)."],
        ];
    }
}