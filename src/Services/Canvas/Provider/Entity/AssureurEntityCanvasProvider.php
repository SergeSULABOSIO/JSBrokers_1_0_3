<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Assureur;
use App\Entity\Bordereau;
use App\Entity\Cotation;
use App\Entity\NotificationSinistre;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class AssureurEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Assureur::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Assureur",
                "icone" => "assureur",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "L'assureur [[*nom]] est une entité clé de notre portefeuille.",
                    " Contactable par email à l'adresse [[email]], par téléphone au [[telephone]] et physiquement à [[adressePhysique]].",
                    " Les informations légales sont : N° Impôt [[numimpot]], ID.NAT [[idnat]], et RCCM [[rccm]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "email", "intitule" => "Email", "type" => "Texte"],
                ["code" => "telephone", "intitule" => "Téléphone", "type" => "Texte"],
                ["code" => "url", "intitule" => "Site Web", "type" => "Texte"],
                ["code" => "adressePhysique", "intitule" => "Adresse", "type" => "Texte"],
                ["code" => "numimpot", "intitule" => "N° Impôt", "type" => "Texte"],
                ["code" => "idnat", "intitule" => "ID.NAT", "type" => "Texte"],
                ["code" => "rccm", "intitule" => "RCCM", "type" => "Texte"],
                ["code" => "cotations", "intitule" => "Cotations", "type" => "Collection", "targetEntity" => Cotation::class, "displayField" => "nom"],
                ["code" => "bordereaus", "intitule" => "Bordereaux", "type" => "Collection", "targetEntity" => Bordereau::class, "displayField" => "nom"],
                ["code" => "notificationSinistres", "intitule" => "Sinistres", "type" => "Collection", "targetEntity" => NotificationSinistre::class, "displayField" => "referenceSinistre"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        $monnaie = $this->serviceMonnaies->getCodeMonnaieAffichage();
        return [
            ["group" => "Activité", "code" => "nombrePolicesSouscrites", "intitule" => "Nb. Polices", "type" => "Entier", "format" => "Nombre", "description" => "Nombre total de polices souscrites auprès de cet assureur."],
            ["group" => "Activité", "code" => "nombreSinistresGeres", "intitule" => "Nb. Sinistres", "type" => "Entier", "format" => "Nombre", "description" => "Nombre total de sinistres gérés par cet assureur."],
            ["group" => "Activité", "code" => "tauxTransformationCotations", "intitule" => "Taux Transfo.", "type" => "Texte", "format" => "Texte", "description" => "Pourcentage de cotations transformées en polices d'assurance (Nb. Polices / Nb. Cotations)."],

            ["group" => "Prime brutte", "code" => "primeTotale", "intitule" => "Prime Totale", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant total de la prime payable par les clients."],
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

            ["group" => "SINISTRALITE", "code" => "indemnisationDue", "intitule" => "Indemnisation dûe", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant dû par l'assureur pour clôturer les dossiers sinistres."],
            ["group" => "SINISTRALITE", "code" => "indemnisationVersee", "intitule" => "Indemnisation versée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Somme des paiements effectués pour les offres d'indemnisations."],
            ["group" => "SINISTRALITE", "code" => "indemnisationSolde", "intitule" => "Indemnisation solde", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Solde dû aux clients ou victimes."],
            ["group" => "SINISTRALITE", "code" => "tauxSP", "intitule" => "Le taux S/P", "type" => "Calcul", "format" => "Pourcentage", "description" => "Rapport Sinistre sur Prime (S/P)."],
        ];
    }
}