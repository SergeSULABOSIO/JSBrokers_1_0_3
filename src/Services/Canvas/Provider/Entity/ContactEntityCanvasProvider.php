<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Client;
use App\Entity\Contact;
use App\Entity\NotificationSinistre;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class ContactEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Contact::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Contact",
                "icone" => "contact",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Contact [[*nom]] ([[fonction]]).",
                    " Email: [[email]] / Téléphone: [[telephone]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "telephone", "intitule" => "Téléphone", "type" => "Texte"],
                ["code" => "email", "intitule" => "Email", "type" => "Texte"],
                ["code" => "fonction", "intitule" => "Fonction", "type" => "Texte"],
                ["code" => "client", "intitule" => "Client", "type" => "Relation", "targetEntity" => Client::class, "displayField" => "nom"],
                ["code" => "notificationSinistre", "intitule" => "Sinistre", "type" => "Relation", "targetEntity" => NotificationSinistre::class, "displayField" => "referenceSinistre"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        $monnaie = $this->serviceMonnaies->getCodeMonnaieAffichage();
        return [
            ["group" => "Information", "code" => "type_string", "intitule" => "Type", "type" => "Calcul", "format" => "Texte", "description" => "Le type de contact (Production, Sinistre, etc.)."],

            // Indicateurs financiers (basés sur le client associé)
            ["group" => "Prime (Client)", "code" => "primeTotale", "intitule" => "Prime Totale", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Volume total des primes générées par le client."],
            ["group" => "Prime (Client)", "code" => "primePayee", "intitule" => "Prime Payée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant des primes encaissées pour le client."],
            ["group" => "Prime (Client)", "code" => "primeSoldeDue", "intitule" => "Prime Solde Dû", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant des primes restant à recouvrer auprès du client."],

            ["group" => "Revenu (Client)", "code" => "montantTTC", "intitule" => "Commission TTC", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Commission totale TTC générée par le client."],
            ["group" => "Revenu (Client)", "code" => "montant_paye", "intitule" => "Commission Encaissée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant de commission effectivement encaissé pour le client."],
            ["group" => "Revenu (Client)", "code" => "solde_restant_du", "intitule" => "Solde Commission", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Reste de commission à encaisser pour le client."],
            ["group" => "Revenu (Client)", "code" => "montantPur", "intitule" => "Commission Pure", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Commission nette après déduction des taxes courtier pour le client."],
            ["group" => "Revenu (Client)", "code" => "reserve", "intitule" => "Réserve Courtier", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Bénéfice final pour le courtier sur ce client."],

            // Sinistralité (basée sur le client associé)
            ["group" => "SINISTRALITE (Client)", "code" => "indemnisationDue", "intitule" => "Indemnisation dûe", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant total des sinistres sur le portefeuille du client."],
            ["group" => "SINISTRALITE (Client)", "code" => "indemnisationVersee", "intitule" => "Indemnisation versée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Somme des paiements effectués pour les sinistres du client."],
            ["group" => "SINISTRALITE (Client)", "code" => "indemnisationSolde", "intitule" => "Indemnisation solde", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Solde dû pour les sinistres du client."],
            ["group" => "SINISTRALITE (Client)", "code" => "tauxSP", "intitule" => "Le taux S/P", "type" => "Calcul", "format" => "Pourcentage", "description" => "Rapport Sinistre sur Prime (S/P) global du client."],
            ["group" => "SINISTRALITE (Client)", "code" => "tauxSPInterpretation", "intitule" => "Interprétation S/P", "type" => "Calcul", "format" => "Texte", "description" => "Analyse de la rentabilité du portefeuille du client."],
        ];
    }
}