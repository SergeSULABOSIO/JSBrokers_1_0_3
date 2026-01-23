<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Document;
use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\Paiement;
use App\Entity\Tache;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class OffreIndemnisationSinistreEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === OffreIndemnisationSinistre::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Offre d'indemnisation",
                "icone" => "mdi:cash-check",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Offre [[*nom]] pour le sinistre [[notificationSinistre]].",
                    " Montant payable: [[montantPayable]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "franchiseAppliquee", "intitule" => "Franchise", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                ["code" => "montantPayable", "intitule" => "Montant Payable", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                ["code" => "beneficiaire", "intitule" => "Bénéficiaire", "type" => "Texte"],
                ["code" => "referenceBancaire", "intitule" => "Réf. Bancaire", "type" => "Texte"],
                ["code" => "notificationSinistre", "intitule" => "Sinistre", "type" => "Relation", "targetEntity" => NotificationSinistre::class, "displayField" => "referenceSinistre"],
                ["code" => "paiements", "intitule" => "Paiements", "type" => "Collection", "targetEntity" => Paiement::class, "displayField" => "reference"],
                ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
                ["code" => "taches", "intitule" => "Tâches", "type" => "Collection", "targetEntity" => Tache::class, "displayField" => "description"], // Note: This collection is not directly related to global indicators.
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["code" => "compensationVersee", "intitule" => "Montant Versé", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "Montant total déjà versé pour cette offre."],
            ["code" => "soldeAVerser", "intitule" => "Solde à Verser", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "Montant restant à payer pour cette offre."],
            ["code" => "pourcentagePaye", "intitule" => "% Payé", "type" => "Nombre", "unite" => "%", "description" => "Pourcentage du montant payable qui a été versé."],
            ["code" => "nombrePaiements", "intitule" => "Nb. Paiements", "type" => "Entier", "format" => "Nombre", "description" => "Nombre de paiements effectués pour cette offre."],
            ["code" => "montantMoyenParPaiement", "intitule" => "Moy. par Paiement", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "Montant moyen de chaque paiement."],
        ];
    }
}