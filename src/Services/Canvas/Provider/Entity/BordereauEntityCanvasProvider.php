<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Assureur;
use App\Entity\Bordereau;
use App\Entity\Document;
use App\Services\ServiceMonnaies;

class BordereauEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Bordereau::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Bordereau",
                "icone" => "bordereau",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Bordereau [[*nom]] (Réf: [[reference]]) de l'assureur [[assureur]]",
                    ", reçu le [[receivedAt]]",
                    " pour un montant HT de [[montantCommissionHT]] et un statut [[statutString]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "reference", "intitule" => "Référence", "type" => "Texte"],
                ["code" => "assureur", "intitule" => "Assureur", "type" => "Relation", "targetEntity" => Assureur::class, "displayField" => "nom"],
                ["code" => "periodeDebut", "intitule" => "Période Début", "type" => "Date"],
                ["code" => "periodeFin", "intitule" => "Période Fin", "type" => "Date"],
                ["code" => "receivedAt", "intitule" => "Reçu le", "type" => "Date"],
                ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"], // Note: This collection is not directly related to global indicators.
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["group" => "Analyse du Bordereau", "code" => "typeString", "intitule" => "Type", "type" => "Calcul", "format" => "Texte", "description" => "Type de bordereau."],
            ["group" => "Analyse du Bordereau", "code" => "statutString", "intitule" => "Statut", "type" => "Calcul", "format" => "Texte", "description" => "Statut actuel du bordereau."],
            ["group" => "Analyse du Bordereau", "code" => "ageBordereau", "intitule" => "Âge (depuis réception)", "type" => "Calcul", "format" => "Texte", "description" => "Nombre de jours écoulés depuis la date de réception."],
            ["group" => "Analyse du Bordereau", "code" => "nombreDocuments", "intitule" => "Nombre de documents", "type" => "Calcul", "format" => "Nombre", "description" => "Nombre de documents joints à ce bordereau."],
            ["group" => "Analyse du Bordereau", "code" => "montantCommissionHT", "intitule" => "Montant HT", "type" => "Calcul", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "Montant total des commissions HT."],
            ["group" => "Analyse du Bordereau", "code" => "montantTaxe", "intitule" => "Montant Taxe", "type" => "Calcul", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "Montant total des taxes."],
            ["group" => "Analyse du Bordereau", "code" => "montantCommissionTTC", "intitule" => "Commission TTC", "type" => "Calcul", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "Montant total des commissions TTC."],
            ["group" => "Analyse du Bordereau", "code" => "montantEncaisse", "intitule" => "Montant Encaissé", "type" => "Calcul", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "Montant total des paiements reçus."],
            ["group" => "Analyse du Bordereau", "code" => "solde", "intitule" => "Solde", "type" => "Calcul", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "Solde restant à encaisser."],
        ];
    }
}