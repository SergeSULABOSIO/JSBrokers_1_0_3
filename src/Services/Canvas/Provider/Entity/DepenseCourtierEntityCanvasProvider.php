<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\ChargeCourtier;
use App\Entity\DepenseCourtier;
use App\Entity\Entreprise;

class DepenseCourtierEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === DepenseCourtier::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Dépense",
                "icone" => "depense",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Dépense [[*ligne_principale]].",
                    " [[ligne_secondaire]].",
                    " Statut : [[statutLabel]].",
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "charge", "intitule" => "Charge", "type" => "Relation", "targetEntity" => ChargeCourtier::class, "displayField" => "libelle"],
                ["code" => "dateDepense", "intitule" => "Date", "type" => "Date"],
                ["code" => "montant", "intitule" => "Montant TTC", "type" => "Nombre", "format" => "Monetaire"],
                ["code" => "tauxTva", "intitule" => "Taux TVA (%)", "type" => "Nombre"],
                ["code" => "beneficiaire", "intitule" => "Bénéficiaire", "type" => "Texte"],
                ["code" => "reference", "intitule" => "Référence", "type" => "Texte"],
                ["code" => "moyenPaiement", "intitule" => "Moyen de paiement", "type" => "Texte"],
                ["code" => "statut", "intitule" => "Statut", "type" => "Texte"],
                ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                ["code" => "entreprise", "intitule" => "Entreprise", "type" => "Relation", "targetEntity" => Entreprise::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["group" => "Détails", "code" => "chargeLibelle", "intitule" => "Type de charge", "type" => "Calcul", "format" => "Texte", "description" => "Libellé de la charge de rattachement."],
            ["group" => "Détails", "code" => "compteOhadaFull", "intitule" => "Compte OHADA", "type" => "Calcul", "format" => "Texte", "description" => "Compte SYSCOHADA de la charge de rattachement."],
            ["group" => "Détails", "code" => "statutLabel", "intitule" => "Statut", "type" => "Calcul", "format" => "Texte", "description" => "Engagée (pèse sur le résultat), payée (décaisse la trésorerie) ou annulée (exclue)."],
            ["group" => "Détails", "code" => "moyenPaiementLabel", "intitule" => "Moyen de paiement", "type" => "Calcul", "format" => "Texte", "description" => "Canal de décaissement (banque, caisse, mobile money…)."],
            ["group" => "Montants", "code" => "montantTtc", "intitule" => "Montant TTC", "type" => "Calcul", "format" => "Monetaire", "description" => "Montant toutes taxes comprises de la dépense."],
            ["group" => "Montants", "code" => "montantHt", "intitule" => "Montant HT", "type" => "Calcul", "format" => "Monetaire", "description" => "Base hors taxe : la charge portée au compte de résultat."],
            ["group" => "Montants", "code" => "tvaDeductible", "intitule" => "TVA déductible", "type" => "Calcul", "format" => "Monetaire", "description" => "Part de TVA récupérable auprès de l'État, incluse dans le TTC."],
        ];
    }
}
