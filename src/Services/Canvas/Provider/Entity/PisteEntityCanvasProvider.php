<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\Risque;
use App\Entity\Tache;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class PisteEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Piste::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Piste Commerciale",
                "icone" => "piste",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Piste [[*nom]] pour le client [[client]].",
                    " Risque: [[risque]].",
                    " Statut: [[statutTransformation]]."
                ]
            ],
            "liste" => array_merge(
                [
                    ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                    ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                    ["code" => "risque", "intitule" => "Risque", "type" => "Relation", "targetEntity" => Risque::class, "displayField" => "nomComplet"],
                    ["code" => "client", "intitule" => "Client", "type" => "Relation", "targetEntity" => Client::class, "displayField" => "nom"],
                    ["code" => "invite", "intitule" => "Gestionnaire", "type" => "Relation", "targetEntity" => Invite::class, "displayField" => "email"],
                    ["code" => "primePotentielle", "intitule" => "Prime Potentielle", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                    ["code" => "commissionPotentielle", "intitule" => "Com. Potentielle", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                    ["code" => "createdAt", "intitule" => "Créée le", "type" => "Date"],
                    ["code" => "descriptionDuRisque", "intitule" => "Description du Risque", "type" => "Texte"],
                    ["code" => "exercice", "intitule" => "Exercice", "type" => "Entier"],
                    ["code" => "avenantDeBase", "intitule" => "Avenant de Base", "type" => "Relation", "targetEntity" => Avenant::class, "displayField" => "numero"],
                    ["code" => "cotations", "intitule" => "Cotations", "type" => "Collection", "targetEntity" => Cotation::class, "displayField" => "nom"],
                    ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
                    ["code" => "taches", "intitule" => "Tâches", "type" => "Collection", "targetEntity" => Tache::class, "displayField" => "description"],
                    ["code" => "partenaires", "intitule" => "Partenaires", "type" => "Collection", "targetEntity" => Partenaire::class, "displayField" => "nom"],
                    ["code" => "conditionsPartageExceptionnelles", "intitule" => "Conditions de Partage (Except.)", "type" => "Collection", "targetEntity" => ConditionPartage::class, "displayField" => "nom"],
                ],
                $this->getSpecificIndicators()
            )
        ];
    }

    private function getSpecificIndicators(): array
    {
        $monnaie = $this->serviceMonnaies->getCodeMonnaieAffichage();
        return [
            ["group" => "Informations Générales", "code" => "typeAvenantString", "intitule" => "Type d'Avenant", "type" => "Calcul", "format" => "Texte", "description" => "Le type de mouvement d'avenant (Souscription, Annulation, etc.)."],
            ["group" => "Informations Générales", "code" => "renewalConditionString", "intitule" => "Condition de Renouvellement", "type" => "Calcul", "format" => "Texte", "description" => "La condition de renouvellement de la police."],
            ["group" => "Informations Générales", "code" => "agePiste", "intitule" => "Âge", "type" => "Calcul", "format" => "Texte", "description" => "Nombre de jours depuis la création de la piste."],
            ["group" => "Statut & Suivi", "code" => "statutTransformation", "intitule" => "Statut", "type" => "Calcul", "format" => "Texte", "description" => "Indique si la piste a été transformée en police (Souscrite) ou non."],
            ["group" => "Statut & Suivi", "code" => "nombreCotations", "intitule" => "Nb. Cotations", "type" => "Calcul", "format" => "Nombre", "description" => "Nombre de cotations émises pour cette piste."],
            // NOUVEAU : Indicateurs financiers agrégés des cotations souscrites.
            ["group" => "Performance Financière (Souscriptions)", "code" => "primeTotale", "intitule" => "Prime Totale", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Somme des primes totales des cotations souscrites."],
            ["group" => "Performance Financière (Souscriptions)", "code" => "primePayee", "intitule" => "Prime Payée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Somme des primes payées des cotations souscrites."],
            ["group" => "Performance Financière (Souscriptions)", "code" => "primeSoldeDue", "intitule" => "Solde Prime", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Somme des soldes de prime des cotations souscrites."],
            ["group" => "Revenus du Courtier (Souscriptions)", "code" => "montantTTC", "intitule" => "Commission TTC", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Somme des commissions TTC des cotations souscrites."],
            ["group" => "Revenus du Courtier (Souscriptions)", "code" => "montant_paye", "intitule" => "Commission Encaissée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Somme des commissions encaissées des cotations souscrites."],
            ["group" => "Revenus du Courtier (Souscriptions)", "code" => "solde_restant_du", "intitule" => "Solde Commission", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Somme des soldes de commission des cotations souscrites."],
            ["group" => "Résultat (Souscriptions)", "code" => "montantPur", "intitule" => "Commission Pure", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Somme des commissions pures des cotations souscrites."],
            ["group" => "Résultat (Souscriptions)", "code" => "retroCommission", "intitule" => "Rétro-commission", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Somme des rétro-commissions des cotations souscrites."],
            ["group" => "Résultat (Souscriptions)", "code" => "reserve", "intitule" => "Réserve Courtier", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Somme des réserves des cotations souscrites."],
        ];
    }
}