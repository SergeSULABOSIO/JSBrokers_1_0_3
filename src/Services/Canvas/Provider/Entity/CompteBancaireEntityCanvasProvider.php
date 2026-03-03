<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\CompteBancaire;
use App\Entity\Document;
use App\Entity\Paiement;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class CompteBancaireEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === CompteBancaire::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Compte Bancaire",
                "icone" => "compte-bancaire",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Compte [[*nom]] - [[banque]].",
                    " N° [[numero]] / [[intitule]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "intitule", "intitule" => "Intitulé", "type" => "Texte"],
                ["code" => "numero", "intitule" => "Numéro", "type" => "Texte"],
                ["code" => "banque", "intitule" => "Banque", "type" => "Texte"],
                ["code" => "codeSwift", "intitule" => "Code Swift", "type" => "Texte"],
                ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
                ["code" => "paiements", "intitule" => "Paiements", "type" => "Collection", "targetEntity" => Paiement::class, "displayField" => "reference"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        $monnaie = $this->serviceMonnaies->getCodeMonnaieAffichage();
        return [
            ["group" => "Solde & Mouvements", "code" => "soldeActuel", "intitule" => "Solde Actuel", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Solde calculé (Entrées - Sorties)."],
            ["group" => "Solde & Mouvements", "code" => "totalEntrees", "intitule" => "Total Entrées", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Somme des encaissements."],
            ["group" => "Solde & Mouvements", "code" => "totalSorties", "intitule" => "Total Sorties", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Somme des décaissements."],
            
            ["group" => "Activité", "code" => "nombreTransactions", "intitule" => "Nb. Transactions", "type" => "Calcul", "format" => "Nombre", "description" => "Nombre total de mouvements."],
            ["group" => "Activité", "code" => "moyenneTransaction", "intitule" => "Moyenne / Trans.", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant moyen d'une transaction."],
            ["group" => "Activité", "code" => "dateDerniereTransaction", "intitule" => "Dernier Mvt.", "type" => "Calcul", "format" => "Date", "description" => "Date de la dernière transaction enregistrée."],
        ];
    }
}