<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Groupe;
use App\Services\ServiceMonnaies;

class GroupeEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Groupe::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Groupe de clients",
                "icone" => "groupe",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Groupe [[nom]].",
                    " [[description]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                ["code" => "entreprise", "intitule" => "Entreprise", "type" => "Relation", "targetEntity" => Entreprise::class, "displayField" => "nom"],
                ["code" => "clients", "intitule" => "Clients", "type" => "Collection", "targetEntity" => Client::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        $monnaie = $this->serviceMonnaies->getCodeMonnaieAffichage();
        return [
            ["group" => "Activité", "code" => "nombreClients", "intitule" => "Nb. Clients", "type" => "Calcul", "format" => "Nombre", "description" => "Nombre total de clients dans ce groupe."],
            ["group" => "Activité", "code" => "nombrePolices", "intitule" => "Nb. Polices", "type" => "Calcul", "format" => "Nombre", "description" => "Nombre total de polices pour les clients de ce groupe."],
            ["group" => "Activité", "code" => "nombreSinistres", "intitule" => "Nb. Sinistres", "type" => "Calcul", "format" => "Nombre", "description" => "Nombre total de sinistres pour les clients de ce groupe."],

            ["group" => "Prime brutte", "code" => "primeTotale", "intitule" => "Prime Totale", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant total de la prime payable par le groupe."],
            ["group" => "Prime brutte", "code" => "primePayee", "intitule" => "Prime Payée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant de la prime déjà réglé par le groupe."],
            ["group" => "Prime brutte", "code" => "primeSoldeDue", "intitule" => "Prime Solde Dû", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant de la prime restant à payer par le groupe."],

            ["group" => "Revenu Brut", "code" => "tauxCommission", "intitule" => "Taux de Com. Global", "type" => "Calcul", "format" => "Pourcentage", "description" => "Taux de commission moyen sur le portefeuille du groupe."],
            ["group" => "Revenu Brut", "code" => "montantHT", "intitule" => "Commission HT", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Commission totale HT générée par le groupe."],
            ["group" => "Revenu Brut", "code" => "montantTTC", "intitule" => "Commission TTC", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Commission totale TTC générée par le groupe."],
            ["group" => "Revenu Brut", "code" => "detailCalcul", "intitule" => "Détail du Calcul", "type" => "Calcul", "format" => "Texte", "description" => "Explication du calcul."],

            ["group" => "Taxes sur Commission", "code" => "taxeCourtierMontant", "intitule" => "Taxe Courtier (ARCA)", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Total taxe courtier sur les commissions du groupe."],
            ["group" => "Taxes sur Commission", "code" => "taxeAssureurMontant", "intitule" => "Taxe Assureur (TVA)", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Total taxe assureur sur les commissions du groupe."],

            ["group" => "Facturation & Paiements", "code" => "montant_du", "intitule" => "Commission TTC", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant total TTC à facturer."],
            ["group" => "Facturation & Paiements", "code" => "montant_paye", "intitule" => "Commission Encaissée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant effectivement encaissé."],
            ["group" => "Facturation & Paiements", "code" => "solde_restant_du", "intitule" => "Solde Commission", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Reste à encaisser."],
            ["group" => "Facturation & Paiements", "code" => "taxeCourtierPayee", "intitule" => "Taxe Courtier Payée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Taxe courtier reversée."],
            ["group" => "Facturation & Paiements", "code" => "taxeCourtierSolde", "intitule" => "Solde Taxe Courtier", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Taxe courtier restant à reverser."],
            ["group" => "Facturation & Paiements", "code" => "taxeAssureurPayee", "intitule" => "Taxe Assureur Payée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Taxe assureur reversée."],
            ["group" => "Facturation & Paiements", "code" => "taxeAssureurSolde", "intitule" => "Solde Taxe Assureur", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Taxe assureur restant à reverser."],

            ["group" => "Partage Partenaire", "code" => "montantPur", "intitule" => "Commission Pure", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Assiette de partage (Commission Pure)."],
            ["group" => "Partage Partenaire", "code" => "retroCommission", "intitule" => "Rétro-commission Due", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Rétro-commission due aux partenaires liés au groupe."],
            ["group" => "Partage Partenaire", "code" => "retroCommissionReversee", "intitule" => "Rétro-commission Reversée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Rétro-commission déjà payée."],
            ["group" => "Partage Partenaire", "code" => "retroCommissionSolde", "intitule" => "Rétro-commission Solde", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Solde de rétro-commission à payer."],

            ["group" => "Résultat Final", "code" => "reserve", "intitule" => "Réserve Courtier", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Revenu net final pour le courtier sur ce groupe."],

            ["group" => "SINISTRALITE", "code" => "indemnisationDue", "intitule" => "Indemnisation dûe", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant dû par l'assureur pour les sinistres du groupe."],
            ["group" => "SINISTRALITE", "code" => "indemnisationVersee", "intitule" => "Indemnisation versée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Somme des paiements effectués pour les sinistres du groupe."],
            ["group" => "SINISTRALITE", "code" => "indemnisationSolde", "intitule" => "Indemnisation solde", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Solde dû aux clients ou aux victimes."],
            ["group" => "SINISTRALITE", "code" => "tauxSP", "intitule" => "Le taux S/P", "type" => "Calcul", "format" => "Pourcentage", "description" => "Rapport Sinistre sur Prime (S/P) pour le groupe."],
            ["group" => "SINISTRALITE", "code" => "tauxSPInterpretation", "intitule" => "Interprétation S/P", "type" => "Calcul", "format" => "Texte", "description" => "Analyse de la rentabilité technique du groupe."],
        ];
    }
}