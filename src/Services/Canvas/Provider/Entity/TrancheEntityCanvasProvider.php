<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Article;
use App\Entity\Cotation;
use App\Entity\Tranche;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class TrancheEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Tranche::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Tranche de Paiement",
                "icone" => "tranche",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Tranche [[*nom]] pour la cotation [[cotation]].",
                    " Montant: [[montantFlat]] ou [[pourcentage]]%.",
                    " Payable le [[payableAt]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "cotation", "intitule" => "Cotation", "type" => "Relation", "targetEntity" => Cotation::class, "displayField" => "nom"],
                ["code" => "montantFlat", "intitule" => "Montant Fixe", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                ["code" => "pourcentage", "intitule" => "Pourcentage", "type" => "Nombre", "unite" => "%"],
                ["code" => "payableAt", "intitule" => "Payable le", "type" => "Date"],
                ["code" => "echeanceAt", "intitule" => "Échéance le", "type" => "Date"],
                ["code" => "articles", "intitule" => "Articles de note", "type" => "Collection", "targetEntity" => Article::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        $monnaie = $this->serviceMonnaies->getCodeMonnaieAffichage();
        return [
            ["code" => "ageTranche", "intitule" => "Âge", "type" => "Calcul", "format" => "Texte", "description" => "Nombre de jours depuis la création de la tranche."],
            ["code" => "joursRestantsAvantEcheance", "intitule" => "Jours Restants", "type" => "Calcul", "format" => "Texte", "description" => "Nombre de jours restants avant la date d'échéance."],
            ["code" => "contexteParent", "intitule" => "Contexte Parent", "type" => "Calcul", "format" => "Texte", "description" => "Contexte de la cotation parente."],
            
            // NOUVEAU : Indicateurs financiers basés sur le taux de la tranche
            ["group" => "Prime brutte", "code" => "primeTranche", "intitule" => "Prime Tranche", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Part de la prime totale payable pour cette tranche."],
            ["group" => "Prime brutte", "code" => "primePayee", "intitule" => "Prime Payée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant de la prime déjà réglé pour cette tranche."],
            ["group" => "Prime brutte", "code" => "primeSoldeDue", "intitule" => "Prime Solde Dû", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant de la prime restant à payer pour cette tranche."],
            ["group" => "Revenu Brut", "code" => "tauxTranche", "intitule" => "Taux Appliqué", "type" => "Calcul", "format" => "Pourcentage", "description" => "Le pourcentage de la prime totale représenté par cette tranche."],
            ["group" => "Revenu Brut", "code" => "montantCalculeHT", "intitule" => "Montant HT", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Part de la commission HT correspondant à cette tranche."],
            ["group" => "Revenu Brut", "code" => "montantCalculeTTC", "intitule" => "Montant TTC", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Part de la commission TTC correspondant à cette tranche."],
            ["group" => "Revenu Brut", "code" => "descriptionCalcul", "intitule" => "Détail du Calcul", "type" => "Calcul", "format" => "Texte", "description" => "Explication du calcul du taux de la tranche."],

            ["group" => "Taxes sur Commission", "code" => "taxeCourtierMontant", "intitule" => "Taxe Courtier (ARCA)", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Part de la taxe courtier correspondant à cette tranche."],
            ["group" => "Taxes sur Commission", "code" => "taxeAssureurMontant", "intitule" => "Taxe Assureur (TVA)", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Part de la taxe assureur correspondant à cette tranche."],

            ["group" => "Facturation & Paiements", "code" => "montant_du", "intitule" => "Montant Dû", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant total TTC à facturer pour cette tranche."],
            ["group" => "Facturation & Paiements", "code" => "montant_paye", "intitule" => "Montant Payé", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant effectivement encaissé pour cette tranche."],
            ["group" => "Facturation & Paiements", "code" => "solde_restant_du", "intitule" => "Solde Restant Dû", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Reste à encaisser sur cette tranche."],
            ["group" => "Facturation & Paiements", "code" => "taxeCourtierPayee", "intitule" => "Taxe Courtier Payée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Taxe courtier reversée pour cette tranche."],
            ["group" => "Facturation & Paiements", "code" => "taxeCourtierSolde", "intitule" => "Solde Taxe Courtier", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Taxe courtier restant à reverser."],
            ["group" => "Facturation & Paiements", "code" => "taxeAssureurPayee", "intitule" => "Taxe Assureur Payée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Taxe assureur reversée pour cette tranche."],
            ["group" => "Facturation & Paiements", "code" => "taxeAssureurSolde", "intitule" => "Solde Taxe Assureur", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Taxe assureur restant à reverser."],

            ["group" => "Partage Partenaire", "code" => "montantPur", "intitule" => "Montant Pur", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Assiette de partage (Commission Pure) pour cette tranche."],
            ["group" => "Partage Partenaire", "code" => "retroCommission", "intitule" => "Rétro-commission Due", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Rétro-commission due sur cette tranche."],
            ["group" => "Partage Partenaire", "code" => "retroCommissionReversee", "intitule" => "Rétro-commission Reversée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Rétro-commission déjà payée sur cette tranche."],
            ["group" => "Partage Partenaire", "code" => "retroCommissionSolde", "intitule" => "Rétro-commission Solde", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Solde de rétro-commission à payer."],

            ["group" => "Résultat Final", "code" => "reserve", "intitule" => "Réserve Courtier", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Revenu net final pour le courtier sur cette tranche."],
        ];
    }
}