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
        $currency = $this->serviceMonnaies->getCodeMonnaieAffichage();

        return [
            // ── Informations générales ──────────────────────────────────────
            ["group" => "Informations générales", "code" => "typeString",      "intitule" => "Type",                  "type" => "Calcul", "format" => "Texte",     "description" => "Type de bordereau."],
            ["group" => "Informations générales", "code" => "statutString",    "intitule" => "Statut",                "type" => "Calcul", "format" => "Texte",     "description" => "Statut actuel du bordereau."],
            ["group" => "Informations générales", "code" => "ageBordereau",    "intitule" => "Âge (depuis réception)","type" => "Calcul", "format" => "Texte",     "description" => "Nombre de jours écoulés depuis la date de réception."],
            ["group" => "Informations générales", "code" => "nombreDocuments", "intitule" => "Nombre de documents",   "type" => "Calcul", "format" => "Nombre",    "description" => "Nombre de documents joints à ce bordereau."],

            // ── Montants issus du bordereau (lignes analysées) ──────────────
            ["group" => "Montants du bordereau", "code" => "comHtPayableNow",  "intitule" => "Com. HT Payable Now",  "type" => "Calcul", "format" => "Monetaire", "unite" => $currency, "description" => "Somme des commissions HT payables issues des lignes du bordereau."],
            ["group" => "Montants du bordereau", "code" => "taxePayableNow",   "intitule" => "Taxe Payable Now",     "type" => "Calcul", "format" => "Monetaire", "unite" => $currency, "description" => "Somme des taxes sur commission payables issues des lignes du bordereau."],
            ["group" => "Montants du bordereau", "code" => "comTtcPayableNow", "intitule" => "Com. TTC Payable Now", "type" => "Calcul", "format" => "Monetaire", "unite" => $currency, "description" => "Total TTC payable (Com. HT + Taxe), issu des lignes du bordereau."],

            // ── Encaissement ────────────────────────────────────────────────
            ["group" => "Encaissement", "code" => "montantEncaisse", "intitule" => "Montant Encaissé", "type" => "Calcul", "format" => "Monetaire", "unite" => $currency, "description" => "Montant total des paiements reçus sur ce bordereau."],
            ["group" => "Encaissement", "code" => "solde",           "intitule" => "Solde dû",         "type" => "Calcul", "format" => "Monetaire", "unite" => $currency, "description" => "Solde restant à encaisser (Com. TTC Payable Now − Encaissé)."],

            // ── Récapitulatif des avenants liés ─────────────────────────────
            ["group" => "Avenants liés", "code" => "montantCommissionHT",  "intitule" => "Montant HT",      "type" => "Calcul", "format" => "Monetaire", "unite" => $currency, "description" => "Somme des commissions HT calculée sur les avenants liés."],
            ["group" => "Avenants liés", "code" => "montantTaxe",          "intitule" => "Montant Taxe",    "type" => "Calcul", "format" => "Monetaire", "unite" => $currency, "description" => "Somme des taxes calculée sur les avenants liés."],
            ["group" => "Avenants liés", "code" => "montantCommissionTTC", "intitule" => "Commission TTC",  "type" => "Calcul", "format" => "Monetaire", "unite" => $currency, "description" => "Total TTC calculé sur les avenants liés (HT + Taxe)."],
        ];
    }
}