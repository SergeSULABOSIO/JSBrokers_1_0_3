<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Avenant;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Portefeuille;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class AvenantEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Avenant::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Avenant",
                "icone" => "avenant",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Avenant n°[[*numero]] de la police [[referencePolice]].",
                    " Période de couverture du [[startingAt]] au [[endingAt]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "referencePolice", "intitule" => "Réf. Police", "type" => "Texte"],
                ["code" => "numero", "intitule" => "Numéro", "type" => "Texte"],
                ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                ["code" => "startingAt", "intitule" => "Date d'effet", "type" => "Date"],
                ["code" => "endingAt", "intitule" => "Date d'échéance", "type" => "Date"],
                ["code" => "cotation", "intitule" => "Cotation", "type" => "Relation", "targetEntity" => Cotation::class, "displayField" => "nom"],
                ["code" => "cotation.piste.client.portefeuille", "intitule" => "Portefeuille", "type" => "Relation", "targetEntity" => Portefeuille::class, "displayField" => "nom"],
                ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        $monnaie = $this->serviceMonnaies->getCodeMonnaieAffichage();
        return [
            ["group" => "Statut & Suivi", "code" => "typeAffaire", "intitule" => "Type d'Affaire", "type" => "Calcul", "format" => "Texte", "description" => "Indique s'il s'agit d'une nouvelle affaire ou d'une affaire existante dans le portefeuille."],
            ["group" => "Statut & Suivi", "code" => "periodeCouverture", "intitule" => "Période", "type" => "Calcul", "format" => "Texte", "description" => "Période de couverture (Date d'effet - Date d'échéance)."],
            ["group" => "Statut & Suivi", "code" => "dureeCouverture", "intitule" => "Durée de couverture", "type" => "Calcul", "format" => "Texte", "description" => "Durée totale de la couverture de l'avenant en jours."],
            ["group" => "Statut & Suivi", "code" => "joursRestants", "intitule" => "Jours restants", "type" => "Calcul", "format" => "Texte", "description" => "Nombre de jours restants avant l'échéance de l'avenant."],
            ["group" => "Statut & Suivi", "code" => "ageAvenant", "intitule" => "Âge de l'avenant", "type" => "Calcul", "format" => "Texte", "description" => "Nombre de jours écoulés depuis la création de l'avenant."],

            // NOUVEAU : Groupe SINISTRALITE
            ["group" => "SINISTRALITE", "code" => "indemnisationDue", "intitule" => "Indemnisation dûe", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant dû par l'assureur pour clôturer le dossier sinistre."],
            ["group" => "SINISTRALITE", "code" => "indemnisationVersee", "intitule" => "Indemnisation versée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Somme des paiements effectués pour les offres d'indemnisations."],
            ["group" => "SINISTRALITE", "code" => "indemnisationSolde", "intitule" => "Indemnisation solde", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Solde dû au client ou à la victime."],
            ["group" => "SINISTRALITE", "code" => "tauxSP", "intitule" => "Le taux S/P", "type" => "Calcul", "format" => "Pourcentage", "description" => "Rapport Sinistre sur Prime (S/P)."],
            ["group" => "SINISTRALITE", "code" => "tauxSPInterpretation", "intitule" => "Interprétation S/P", "type" => "Calcul", "format" => "Texte", "description" => "Analyse de la rentabilité technique basée sur le ratio S/P."],
            ["group" => "SINISTRALITE", "code" => "dateDernierReglement", "intitule" => "Date du dernier règlement", "type" => "Calcul", "format" => "Date", "description" => "Date du dernier paiement de l'offre d'indemnisation."],
            ["group" => "SINISTRALITE", "code" => "vitesseReglement", "intitule" => "Vitesse de règlement", "type" => "Calcul", "format" => "Texte", "description" => "Temps écoulé entre la notification et le règlement final."],

            ["group" => "Contexte", "code" => "contextePiste", "intitule" => "Contexte Piste", "type" => "Calcul", "format" => "Texte", "description" => "Rappelle la piste commerciale à laquelle cette cotation est rattachée."],
            ["group" => "Contexte Affaire", "code" => "clientDescription", "intitule" => "Client", "type" => "Calcul", "format" => "Texte", "description" => "Détails descriptifs du client."],
            ["group" => "Contexte Affaire", "code" => "risqueDescription", "intitule" => "Risque", "type" => "Calcul", "format" => "Texte", "description" => "Description de la couverture d'assurance."],
            ["group" => "Plan de Paiement", "code" => "nombreTranches", "intitule" => "Nb. Tranches", "type" => "Calcul", "format" => "Nombre", "description" => "Nombre de tranches de paiement définies pour cette cotation."],
            ["group" => "Plan de Paiement", "code" => "montantMoyenTranche", "intitule" => "Moy. par Tranche", "type" => "Calcul", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "Montant moyen d'une tranche de paiement."],

            // NOUVEAU : Indicateurs financiers globaux de la cotation
            ["group" => "Prime brutte", "code" => "primeTotale", "intitule" => "Prime Totale", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant total de la prime payable par le client."],
            ["group" => "Prime brutte", "code" => "primePayee", "intitule" => "Prime Payée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant de la prime déjà réglé."],
            ["group" => "Prime brutte", "code" => "primeSoldeDue", "intitule" => "Prime Solde Dû", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant de la prime restant à payer."],

            ["group" => "Revenu Brut", "code" => "tauxCommission", "intitule" => "Taux de Com. Global", "type" => "Calcul", "format" => "Pourcentage", "description" => "Taux de commission moyen par rapport à la prime totale."],
            ["group" => "Revenu Brut", "code" => "montantHT", "intitule" => "Commission HT", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Revenu net hors taxe assureur. = Revenu TTC − Taxe Assureur."],
            ["group" => "Revenu Brut", "code" => "montantTTC", "intitule" => "Commission TTC", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Commission brute toutes taxes incluses. Montant théorique total dû au courtier sur cette police, avant toute déduction."],
            ["group" => "Revenu Brut", "code" => "detailCalcul", "intitule" => "Détail du Calcul", "type" => "Calcul", "format" => "Texte", "description" => "Décomposition du calcul de la commission : taux appliqués, assiette, et composantes (HT, taxe courtier, taxe assureur)."],

            ["group" => "Taxes sur Commission", "code" => "taxeCourtierMontant", "intitule" => "Taxe Courtier (ARCA)", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Taxe réglementaire à la charge du courtier, calculée sur la commission brute. Retenue sur le Revenu TTC et reversée à l'administration fiscale."],
            ["group" => "Taxes sur Commission", "code" => "taxeAssureurMontant", "intitule" => "Taxe Assureur (TVA)", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Taxe à la charge de l'assureur sur les primes, collectée par le courtier pour son compte. Reversée à l'administration fiscale."],

            ["group" => "Facturation & Paiements", "code" => "montant_du", "intitule" => "Commission TTC", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Commission totale TTC à facturer au titre de cette police. Correspond au Revenu Brut TTC calculé sur la prime."],
            ["group" => "Facturation & Paiements", "code" => "montant_paye", "intitule" => "Commission Encaissée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Cumul des paiements reçus sur les notes de commission émises pour cette police à ce jour."],
            ["group" => "Facturation & Paiements", "code" => "solde_restant_du", "intitule" => "Solde Commission", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Commission facturée mais non encore encaissée sur cette police. = Commission TTC due − Paiements déjà reçus."],
            ["group" => "Facturation & Paiements", "code" => "taxeCourtierPayee", "intitule" => "Taxe Courtier Payée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Taxe courtier reversée."],
            ["group" => "Facturation & Paiements", "code" => "taxeCourtierSolde", "intitule" => "Solde Taxe Courtier", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Taxe courtier restant à reverser."],
            ["group" => "Facturation & Paiements", "code" => "taxeAssureurPayee", "intitule" => "Taxe Assureur Payée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Taxe assureur reversée."],
            ["group" => "Facturation & Paiements", "code" => "taxeAssureurSolde", "intitule" => "Solde Taxe Assureur", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Taxe assureur restant à reverser."],

            ["group" => "Partage Partenaire", "code" => "montantPur", "intitule" => "Commission Pure", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Revenu excluant toutes les taxes (assureur et courtier). = Revenu HT − Taxe Courtier. C'est l'assiette partageable avec un partenaire éventuel."],
            ["group" => "Partage Partenaire", "code" => "retroCommission", "intitule" => "Rétro-commission Due", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Part à rétrocéder au partenaire. = Revenu Pur × Taux partenaire. Calculée sur l'assiette partageable (Revenu Pur), selon le taux convenu."],
            ["group" => "Partage Partenaire", "code" => "retroCommissionReversee", "intitule" => "Rétro-commission Reversée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Rétro-commission déjà payée."],
            ["group" => "Partage Partenaire", "code" => "retroCommissionSolde", "intitule" => "Rétro-commission Solde", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Solde de rétro-commission à payer."],

            ["group" => "Résultat Final", "code" => "reserve", "intitule" => "Réserve Courtier", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Ce qui reste au courtier après partage. = Revenu Pur − Rétrocommission. Sa part nette, après rémunération de tous les intermédiaires."],
        ];
    }
}