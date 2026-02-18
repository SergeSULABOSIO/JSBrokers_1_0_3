<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\ChargementPourPrime;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Tache;
use App\Entity\Tranche;
use App\Services\ServiceMonnaies;

class CotationEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Cotation::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Cotation",
                "icone" => "cotation",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Cotation [[*nom]] pour la piste [[piste]].",
                    " Assureur: [[assureur]].",
                    " Statut: [[statutSouscription]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "duree", "intitule" => "Durée (jours)", "type" => "Entier"],
                ["code" => "createdAt", "intitule" => "Créée le", "type" => "Date"],
                ["code" => "assureur", "intitule" => "Assureur", "type" => "Relation", "targetEntity" => Assureur::class, "displayField" => "nom"],
                ["code" => "piste", "intitule" => "Piste", "type" => "Relation", "targetEntity" => Piste::class, "displayField" => "nom"],
                ["code" => "taches", "intitule" => "Tâches", "type" => "Collection", "targetEntity" => Tache::class, "displayField" => "description"],
                ["code" => "chargements", "intitule" => "Chargements", "type" => "Collection", "targetEntity" => ChargementPourPrime::class, "displayField" => "nom"],
                ["code" => "revenus", "intitule" => "Revenus", "type" => "Collection", "targetEntity" => RevenuPourCourtier::class, "displayField" => "nom"],
                ["code" => "tranches", "intitule" => "Tranches", "type" => "Collection", "targetEntity" => Tranche::class, "displayField" => "nom"],
                ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
                ["code" => "avenants", "intitule" => "Avenants", "type" => "Collection", "targetEntity" => Avenant::class, "displayField" => "numero"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        $monnaie = $this->serviceMonnaies->getCodeMonnaieAffichage();
        return [
            ["group" => "Statut & Suivi", "code" => "statutSouscription", "intitule" => "Statut", "type" => "Calcul", "format" => "Texte", "description" => "Indique si la cotation a été transformée en police (Souscrite) ou non (En attente)."],
            ["group" => "Statut & Suivi", "code" => "referencePolice", "intitule" => "Réf. Police", "type" => "Calcul", "format" => "Texte", "description" => "Référence de la police associée (si souscrite)."],
            ["group" => "Statut & Suivi", "code" => "periodeCouverture", "intitule" => "Période", "type" => "Calcul", "format" => "Texte", "description" => "Période de couverture (Date d'effet - Date d'échéance)."],
            ["group" => "Statut & Suivi", "code" => "delaiDepuisCreation", "intitule" => "Âge", "type" => "Calcul", "format" => "Texte", "description" => "Nombre de jours écoulés depuis la création de la cotation."],
            
            // NOUVEAU : Groupe SINISTRALITE
            ["group" => "SINISTRALITE", "code" => "indemnisationDue", "intitule" => "Indemnisation dûe", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant dû par l'assureur pour clôturer le dossier sinistre."],
            ["group" => "SINISTRALITE", "code" => "indemnisationVersee", "intitule" => "Indemnisation versée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Somme des paiements effectués pour les offres d'indemnisations."],
            ["group" => "SINISTRALITE", "code" => "indemnisationSolde", "intitule" => "Indemnisation solde", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Solde dû au client ou à la victime."],
            ["group" => "SINISTRALITE", "code" => "tauxSP", "intitule" => "Le taux S/P", "type" => "Calcul", "format" => "Pourcentage", "description" => "Rapport Sinistre sur Prime (S/P)."],
            ["group" => "SINISTRALITE", "code" => "tauxSPInterpretation", "intitule" => "Interprétation S/P", "type" => "Calcul", "format" => "Texte", "description" => "Analyse de la rentabilité technique basée sur le ratio S/P."],
            ["group" => "SINISTRALITE", "code" => "dateDernierReglement", "intitule" => "Date du dernier règlement", "type" => "Calcul", "format" => "Date", "description" => "Date du dernier paiement de l'offre d'indemnisation."],
            ["group" => "SINISTRALITE", "code" => "vitesseReglement", "intitule" => "Vitesse de règlement", "type" => "Calcul", "format" => "Texte", "description" => "Temps écoulé entre la notification et le règlement final."],

            ["group" => "Contexte", "code" => "contextePiste", "intitule" => "Contexte Piste", "type" => "Calcul", "format" => "Texte", "description" => "Rappelle la piste commerciale à laquelle cette cotation est rattachée."],
            ["group" => "Plan de Paiement", "code" => "nombreTranches", "intitule" => "Nb. Tranches", "type" => "Calcul", "format" => "Nombre", "description" => "Nombre de tranches de paiement définies pour cette cotation."],
            ["group" => "Plan de Paiement", "code" => "montantMoyenTranche", "intitule" => "Moy. par Tranche", "type" => "Calcul", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "Montant moyen d'une tranche de paiement."],

            // NOUVEAU : Indicateurs financiers globaux de la cotation
            ["group" => "Prime brutte", "code" => "primeTotale", "intitule" => "Prime Totale", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant total de la prime payable par le client."],
            ["group" => "Prime brutte", "code" => "primePayee", "intitule" => "Prime Payée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant de la prime déjà réglé."],
            ["group" => "Prime brutte", "code" => "primeSoldeDue", "intitule" => "Prime Solde Dû", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant de la prime restant à payer."],

            ["group" => "Revenu Brut", "code" => "tauxCommission", "intitule" => "Taux de Com. Global", "type" => "Calcul", "format" => "Pourcentage", "description" => "Taux de commission moyen par rapport à la prime totale."],
            ["group" => "Revenu Brut", "code" => "montantHT", "intitule" => "Commission HT", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Commission totale HT."],
            ["group" => "Revenu Brut", "code" => "montantTTC", "intitule" => "Commission TTC", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Commission totale TTC."],
            ["group" => "Revenu Brut", "code" => "detailCalcul", "intitule" => "Détail du Calcul", "type" => "Calcul", "format" => "Texte", "description" => "Explication du calcul."],

            ["group" => "Taxes sur Commission", "code" => "taxeCourtierMontant", "intitule" => "Taxe Courtier (ARCA)", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Total taxe courtier."],
            ["group" => "Taxes sur Commission", "code" => "taxeAssureurMontant", "intitule" => "Taxe Assureur (TVA)", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Total taxe assureur."],

            ["group" => "Facturation & Paiements", "code" => "montant_du", "intitule" => "Commission TTC", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant total TTC à facturer."],
            ["group" => "Facturation & Paiements", "code" => "montant_paye", "intitule" => "Commission Encaissée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant effectivement encaissé."],
            ["group" => "Facturation & Paiements", "code" => "solde_restant_du", "intitule" => "Solde Commission", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Reste à encaisser."],
            ["group" => "Facturation & Paiements", "code" => "taxeCourtierPayee", "intitule" => "Taxe Courtier Payée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Taxe courtier reversée."],
            ["group" => "Facturation & Paiements", "code" => "taxeCourtierSolde", "intitule" => "Solde Taxe Courtier", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Taxe courtier restant à reverser."],
            ["group" => "Facturation & Paiements", "code" => "taxeAssureurPayee", "intitule" => "Taxe Assureur Payée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Taxe assureur reversée."],
            ["group" => "Facturation & Paiements", "code" => "taxeAssureurSolde", "intitule" => "Solde Taxe Assureur", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Taxe assureur restant à reverser."],

            ["group" => "Partage Partenaire", "code" => "montantPur", "intitule" => "Commission Pure", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Assiette de partage (Commission Pure)."],
            ["group" => "Partage Partenaire", "code" => "retroCommission", "intitule" => "Rétro-commission Due", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Rétro-commission due."],
            ["group" => "Partage Partenaire", "code" => "retroCommissionReversee", "intitule" => "Rétro-commission Reversée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Rétro-commission déjà payée."],
            ["group" => "Partage Partenaire", "code" => "retroCommissionSolde", "intitule" => "Rétro-commission Solde", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Solde de rétro-commission à payer."],

            ["group" => "Résultat Final", "code" => "reserve", "intitule" => "Réserve Courtier", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Revenu net final pour le courtier."],
        ];
    }
}