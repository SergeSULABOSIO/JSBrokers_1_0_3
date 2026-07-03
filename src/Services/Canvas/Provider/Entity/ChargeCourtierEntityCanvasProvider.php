<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\ChargeCourtier;
use App\Entity\Entreprise;

class ChargeCourtierEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ChargeCourtier::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Charge",
                "icone" => "charge",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Charge [[*code]] - [[libelle]].",
                    " Compte OHADA : [[compteOhadaFull]].",
                    " Profil : [[profilCharge]].",
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "code", "intitule" => "Code", "type" => "Texte"],
                ["code" => "libelle", "intitule" => "Libellé", "type" => "Texte"],
                ["code" => "compteOhada", "intitule" => "Compte OHADA", "type" => "Texte"],
                ["code" => "comportement", "intitule" => "Comportement", "type" => "Texte"],
                ["code" => "periodicite", "intitule" => "Périodicité", "type" => "Texte"],
                ["code" => "montantBudgeteMensuel", "intitule" => "Budget mensuel", "type" => "Nombre", "format" => "Monetaire"],
                ["code" => "actif", "intitule" => "Active", "type" => "Booleen"],
                ["code" => "entreprise", "intitule" => "Entreprise", "type" => "Relation", "targetEntity" => Entreprise::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["group" => "Détails", "code" => "compteOhadaFull", "intitule" => "Compte OHADA (libellé)", "type" => "Calcul", "format" => "Texte", "description" => "Compte de rattachement au plan comptable SYSCOHADA, avec son libellé."],
            ["group" => "Détails", "code" => "comportementLabel", "intitule" => "Comportement", "type" => "Calcul", "format" => "Texte", "description" => "Charge fixe ou variable."],
            ["group" => "Détails", "code" => "periodiciteLabel", "intitule" => "Périodicité", "type" => "Calcul", "format" => "Texte", "description" => "Périodicité prévisionnelle de la charge."],
            ["group" => "Détails", "code" => "actifLabel", "intitule" => "Statut", "type" => "Calcul", "format" => "Texte", "description" => "Charge active ou inactive à la saisie des dépenses."],
            ["group" => "Budget", "code" => "montantBudgeteMensuelFloat", "intitule" => "Budget mensuel", "type" => "Calcul", "format" => "Monetaire", "description" => "Montant prévisionnel mensuel budgété pour cette charge."],
        ];
    }
}
