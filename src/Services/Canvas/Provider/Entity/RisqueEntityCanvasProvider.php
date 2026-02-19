<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\ConditionPartage;
use App\Entity\NotificationSinistre;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class RisqueEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Risque::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Risque",
                "icone" => "risque",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Risque [[*nomComplet]] (Code: [[code]]).",
                    " Branche: [[brancheString]].",
                    " Description: [[description]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "code", "intitule" => "Code", "type" => "Texte"],
                ["code" => "nomComplet", "intitule" => "Nom Complet", "type" => "Texte"],
                ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                ["code" => "pourcentageCommissionSpecifiqueHT", "intitule" => "Com. Spécifique (%)", "type" => "Nombre", "unite" => "%"],
                ["code" => "imposable", "intitule" => "Imposable", "type" => "Booleen"],
                ["code" => "conditionPartage", "intitule" => "Condition de Partage", "type" => "Relation", "targetEntity" => ConditionPartage::class, "displayField" => "nom"],
                ["code" => "pistes", "intitule" => "Pistes", "type" => "Collection", "targetEntity" => Piste::class, "displayField" => "nom"],
                ["code" => "notificationSinistres", "intitule" => "Sinistres", "type" => "Collection", "targetEntity" => NotificationSinistre::class, "displayField" => "referenceSinistre"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        $monnaie = $this->serviceMonnaies->getCodeMonnaieAffichage();
        return [
            ["group" => "Statut & Suivi", "code" => "brancheString", "intitule" => "Branche", "type" => "Texte", "format" => "Texte", "description" => "Branche d'assurance (IARD ou Vie)."],
            ["group" => "Statut & Suivi", "code" => "nombrePistes", "intitule" => "Nb. Pistes", "type" => "Entier", "format" => "Nombre", "description" => "Nombre de pistes associées à ce risque."],
            ["group" => "Statut & Suivi", "code" => "nombreSinistres", "intitule" => "Nb. Sinistres", "type" => "Entier", "format" => "Nombre", "description" => "Nombre de sinistres déclarés pour ce risque."],
            ["group" => "Statut & Suivi", "code" => "nombrePolices", "intitule" => "Nb. Polices", "type" => "Entier", "format" => "Nombre", "description" => "Nombre de polices actives pour ce risque."],

            // Groupe SINISTRALITE (Portefeuille Risque)
            ["group" => "SINISTRALITE", "code" => "indemnisationDue", "intitule" => "Indemnisation dûe", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant total des sinistres sur ce risque."],
            ["group" => "SINISTRALITE", "code" => "indemnisationVersee", "intitule" => "Indemnisation versée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Somme des paiements effectués pour les sinistres."],
            ["group" => "SINISTRALITE", "code" => "indemnisationSolde", "intitule" => "Indemnisation solde", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Solde dû aux clients."],
            ["group" => "SINISTRALITE", "code" => "tauxSP", "intitule" => "Le taux S/P", "type" => "Calcul", "format" => "Pourcentage", "description" => "Rapport Sinistre sur Prime (S/P) global pour ce risque."],
            ["group" => "SINISTRALITE", "code" => "tauxSPInterpretation", "intitule" => "Interprétation S/P", "type" => "Calcul", "format" => "Texte", "description" => "Analyse de la rentabilité technique."],

            // Indicateurs financiers globaux
            ["group" => "Prime brutte", "code" => "primeTotale", "intitule" => "Prime Totale", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Volume total des primes générées."],
            ["group" => "Prime brutte", "code" => "primePayee", "intitule" => "Prime Payée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant des primes encaissées."],
            ["group" => "Prime brutte", "code" => "primeSoldeDue", "intitule" => "Prime Solde Dû", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant des primes restant à recouvrer."],

            ["group" => "Revenu Brut", "code" => "tauxCommission", "intitule" => "Taux de Com. Moyen", "type" => "Calcul", "format" => "Pourcentage", "description" => "Taux de commission moyen."],
            ["group" => "Revenu Brut", "code" => "montantHT", "intitule" => "Commission HT", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Commission totale HT générée."],
            ["group" => "Revenu Brut", "code" => "montantTTC", "intitule" => "Commission TTC", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Commission totale TTC générée."],

            ["group" => "Taxes sur Commission", "code" => "taxeCourtierMontant", "intitule" => "Taxe Courtier (ARCA)", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Total taxe courtier."],
            ["group" => "Taxes sur Commission", "code" => "taxeAssureurMontant", "intitule" => "Taxe Assureur (TVA)", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Total taxe assureur."],

            ["group" => "Facturation & Paiements", "code" => "montant_du", "intitule" => "Commission TTC", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant total TTC facturé."],
            ["group" => "Facturation & Paiements", "code" => "montant_paye", "intitule" => "Commission Encaissée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant effectivement encaissé."],
            ["group" => "Facturation & Paiements", "code" => "solde_restant_du", "intitule" => "Solde Commission", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Reste à encaisser."],
            ["group" => "Facturation & Paiements", "code" => "taxeCourtierPayee", "intitule" => "Taxe Courtier Payée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Taxe courtier reversée."],
            ["group" => "Facturation & Paiements", "code" => "taxeCourtierSolde", "intitule" => "Solde Taxe Courtier", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Taxe courtier restant à reverser."],
            ["group" => "Facturation & Paiements", "code" => "taxeAssureurPayee", "intitule" => "Taxe Assureur Payée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Taxe assureur reversée."],
            ["group" => "Facturation & Paiements", "code" => "taxeAssureurSolde", "intitule" => "Solde Taxe Assureur", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Taxe assureur restant à reverser."],

            ["group" => "Résultat Final", "code" => "reserve", "intitule" => "Réserve Courtier", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Revenu net final pour le courtier sur ce risque."],
        ];
    }
}