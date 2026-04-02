<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\CompteBancaire;
use App\Entity\Document;
use App\Entity\Note;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\Paiement;
use App\Services\ServiceMonnaies;

class PaiementEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Paiement::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Paiement",
                "icone" => "paiement",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Paiement [[*reference]] du [[paidAt|date('d/m/Y')]].",
                    " Montant: [[montant]] [[currency_symbol]].",
                    " Contexte: [[contexte]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "reference", "intitule" => "Référence", "type" => "Texte"],
                ["code" => "montant", "intitule" => "Montant", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                ["code" => "paidAt", "intitule" => "Date de paiement", "type" => "Date"],
                ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                ["code" => "CompteBancaire", "intitule" => "Compte Bancaire", "type" => "Relation", "targetEntity" => CompteBancaire::class, "displayField" => "nom"],
                ["code" => "note", "intitule" => "Note liée", "type" => "Relation", "targetEntity" => Note::class, "displayField" => "reference"],
                ["code" => "offreIndemnisationSinistre", "intitule" => "Offre Indemnisation", "type" => "Relation", "targetEntity" => OffreIndemnisationSinistre::class, "displayField" => "nom"],
                ["code" => "preuves", "intitule" => "Preuves", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        $monnaie = $this->serviceMonnaies->getCodeMonnaieAffichage();
        return [
            ["group" => "Contexte", "code" => "typePaiement", "intitule" => "Type", "type" => "Calcul", "format" => "Texte", "description" => "Nature du paiement (Prime ou Sinistre)."],
            ["group" => "Contexte", "code" => "contexte", "intitule" => "Dossier", "type" => "Calcul", "format" => "Texte", "description" => "Référence du dossier associé (Note ou Sinistre)."],
            ["group" => "Détails Police", "code" => "referencePolice", "intitule" => "Réf. Police", "type" => "Calcul", "format" => "Texte", "description" => "Police d'assurance concernée."],
            ["group" => "Détails Police", "code" => "clientNom", "intitule" => "Client / Assuré", "type" => "Calcul", "format" => "Texte", "description" => "Nom du client ou de l'assuré concerné."],
            ["group" => "Finances", "code" => "montantPaiement", "intitule" => "Montant du Paiement", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Le montant payé."],
        ];
    }
}