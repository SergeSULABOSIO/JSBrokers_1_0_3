<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\ConditionPartage;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class ConditionPartageEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ConditionPartage::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Condition de Partage",
                "icone" => "mdi:share-variant",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Condition de partage: [[*nom]] pour le partenaire [[partenaire]].",
                    " Taux de [[taux]]% appliqué sur [[unite_mesure_string]]",
                    " avec la formule: [[formule_string]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "partenaire", "intitule" => "Partenaire", "type" => "Relation", "targetEntity" => Partenaire::class, "displayField" => "nom"],
                ["code" => "piste", "intitule" => "Piste (Exception)", "type" => "Relation", "targetEntity" => Piste::class, "displayField" => "nom"],
                ["code" => "taux", "intitule" => "Taux", "type" => "Nombre", "unite" => "%"],
                ["code" => "seuil", "intitule" => "Seuil", "type" => "Nombre", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                [
                    "code" => "formule_string",
                    "intitule" => "Formule",
                    "type" => "Calcul",
                    "format" => "Texte",
                    "fonction" => "ConditionPartage_getFormuleString",
                ],
                [
                    "code" => "unite_mesure_string",
                    "intitule" => "Unité de Mesure",
                    "type" => "Calcul",
                    "format" => "Texte",
                    "fonction" => "ConditionPartage_getUniteMesureString",
                ],
                [
                    "code" => "critere_risque_string",
                    "intitule" => "Critère Risque",
                    "type" => "Calcul",
                    "format" => "Texte",
                    "fonction" => "ConditionPartage_getCritereRisqueString",
                ],
                ["code" => "produits", "intitule" => "Risques Ciblés", "type" => "Collection", "targetEntity" => Risque::class, "displayField" => "nomComplet"], // Note: This collection is not directly related to global indicators.
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["code" => "descriptionRegle", "intitule" => "Description de la Règle", "type" => "Texte", "format" => "Texte", "description" => "Un résumé lisible de la condition de partage."],
            ["code" => "nombreRisquesCibles", "intitule" => "Nb. Risques Ciblés", "type" => "Entier", "format" => "Nombre", "description" => "Nombre de produits/risques spécifiques visés par cette condition."],
            ["code" => "porteeCondition", "intitule" => "Portée", "type" => "Texte", "format" => "Texte", "description" => "Indique si la condition est générale (liée au partenaire) ou exceptionnelle (liée à une piste)."],
        ];
    }
}