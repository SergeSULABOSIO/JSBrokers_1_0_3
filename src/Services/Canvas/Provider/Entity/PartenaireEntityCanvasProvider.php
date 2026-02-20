<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Document;
use App\Entity\Note;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class PartenaireEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Partenaire::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Partenaire",
                "icone" => "partenaire",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Partenaire [[*nom]], avec une part de [[partPourcentage]]%.",
                    " Contact: [[email]] / [[telephone]]. Adresse: [[adressePhysique]].",
                    " Infos légales: Impôt [[numimpot]], RCCM [[rccm]], ID.NAT [[idnat]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "adressePhysique", "intitule" => "Adresse", "type" => "Texte"],
                ["code" => "telephone", "intitule" => "Téléphone", "type" => "Texte"],
                ["code" => "email", "intitule" => "Email", "type" => "Texte"],
                ["code" => "idnat", "intitule" => "ID.NAT", "type" => "Texte"],
                ["code" => "numimpot", "intitule" => "N° Impôt", "type" => "Texte"],
                ["code" => "rccm", "intitule" => "RCCM", "type" => "Texte"],
                ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
                ["code" => "conditionPartages", "intitule" => "Conditions de partage", "type" => "Collection", "targetEntity" => ConditionPartage::class, "displayField" => "nom"],
                ["code" => "pistes", "intitule" => "Pistes", "type" => "Collection", "targetEntity" => Piste::class, "displayField" => "nom"],
                ["code" => "notes", "intitule" => "Notes", "type" => "Collection", "targetEntity" => Note::class, "displayField" => "reference"],
                ["code" => "clients", "intitule" => "Clients", "type" => "Collection", "targetEntity" => Client::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        $monnaie = $this->serviceMonnaies->getCodeMonnaieAffichage();
        return [
            ["group" => "Activité", "code" => "nombrePistesApportees", "intitule" => "Nb. Pistes", "type" => "Entier", "format" => "Nombre", "description" => "Nombre de pistes apportées par ce partenaire."],
            ["group" => "Activité", "code" => "nombreClientsAssocies", "intitule" => "Nb. Clients", "type" => "Entier", "format" => "Nombre", "description" => "Nombre de clients associés à ce partenaire."],
            ["group" => "Activité", "code" => "nombrePolicesGenerees", "intitule" => "Nb. Polices", "type" => "Entier", "format" => "Nombre", "description" => "Nombre de polices générées via ce partenaire."],
            ["group" => "Activité", "code" => "nombreConditionsPartage", "intitule" => "Nb. Conditions", "type" => "Entier", "format" => "Nombre", "description" => "Nombre de conditions de partage définies pour ce partenaire."],
            ["group" => "Statut & Suivi", "code" => "partPourcentage", "intitule" => "Part (%)", "type" => "Calcul", "format" => "Nombre", "unite" => "%", "description" => "Part du partenaire en pourcentage."],

            // Groupe SINISTRALITE (Portefeuille Partenaire)
            ["group" => "SINISTRALITE", "code" => "indemnisationDue", "intitule" => "Indemnisation dûe", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant total des sinistres sur le portefeuille du partenaire."],
            ["group" => "SINISTRALITE", "code" => "indemnisationVersee", "intitule" => "Indemnisation versée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Somme des paiements effectués pour les sinistres du portefeuille."],
            ["group" => "SINISTRALITE", "code" => "indemnisationSolde", "intitule" => "Indemnisation solde", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Solde dû aux clients du partenaire."],
            ["group" => "SINISTRALITE", "code" => "tauxSP", "intitule" => "Le taux S/P", "type" => "Calcul", "format" => "Pourcentage", "description" => "Rapport Sinistre sur Prime (S/P) global du partenaire."],
            ["group" => "SINISTRALITE", "code" => "tauxSPInterpretation", "intitule" => "Interprétation S/P", "type" => "Calcul", "format" => "Texte", "description" => "Analyse de la rentabilité du portefeuille partenaire."],

            // Indicateurs financiers globaux (Miroir Cotation)
            ["group" => "Prime brutte", "code" => "primeTotale", "intitule" => "Prime Totale", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Volume total des primes générées par le partenaire."],
            ["group" => "Prime brutte", "code" => "primePayee", "intitule" => "Prime Payée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant des primes encaissées."],
            ["group" => "Prime brutte", "code" => "primeSoldeDue", "intitule" => "Prime Solde Dû", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant des primes restant à recouvrer."],
            
            ["group" => "Revenu Brut", "code" => "tauxCommission", "intitule" => "Taux de Com. Moyen", "type" => "Calcul", "format" => "Pourcentage", "description" => "Taux de commission moyen sur le portefeuille."],
            ["group" => "Revenu Brut", "code" => "montantHT", "intitule" => "Commission HT", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Commission totale HT générée."],
            ["group" => "Revenu Brut", "code" => "montantTTC", "intitule" => "Commission TTC", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Commission totale TTC générée."],

            ["group" => "Taxes sur Commission", "code" => "taxeCourtierMontant", "intitule" => "Taxe Courtier (ARCA)", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Total taxe courtier sur le portefeuille."],
            ["group" => "Taxes sur Commission", "code" => "taxeAssureurMontant", "intitule" => "Taxe Assureur (TVA)", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Total taxe assureur sur le portefeuille."],

            ["group" => "Facturation & Paiements", "code" => "montant_du", "intitule" => "Commission TTC", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant total TTC facturé."],
            ["group" => "Facturation & Paiements", "code" => "montant_paye", "intitule" => "Commission Encaissée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant effectivement encaissé."],
            ["group" => "Facturation & Paiements", "code" => "solde_restant_du", "intitule" => "Solde Commission", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Reste à encaisser."],
            ["group" => "Facturation & Paiements", "code" => "taxeCourtierPayee", "intitule" => "Taxe Courtier Payée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Taxe courtier reversée."],
            ["group" => "Facturation & Paiements", "code" => "taxeCourtierSolde", "intitule" => "Solde Taxe Courtier", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Taxe courtier restant à reverser."],
            ["group" => "Facturation & Paiements", "code" => "taxeAssureurPayee", "intitule" => "Taxe Assureur Payée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Taxe assureur reversée."],
            ["group" => "Facturation & Paiements", "code" => "taxeAssureurSolde", "intitule" => "Solde Taxe Assureur", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Taxe assureur restant à reverser."],

            ["group" => "Partage Partenaire", "code" => "montantPur", "intitule" => "Commission Pure", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Assiette de partage (Commission Pure)."],
            ["group" => "Partage Partenaire", "code" => "retroCommission", "intitule" => "Rétro-commission Due", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Rétro-commission due au partenaire."],
            ["group" => "Partage Partenaire", "code" => "retroCommissionReversee", "intitule" => "Rétro-commission Reversée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Rétro-commission déjà payée au partenaire."],
            ["group" => "Partage Partenaire", "code" => "retroCommissionSolde", "intitule" => "Rétro-commission Solde", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Solde de rétro-commission à payer."],

            ["group" => "Résultat Final", "code" => "reserve", "intitule" => "Réserve Courtier", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Revenu net final pour le courtier sur ce partenaire."],
        ];
    }
}