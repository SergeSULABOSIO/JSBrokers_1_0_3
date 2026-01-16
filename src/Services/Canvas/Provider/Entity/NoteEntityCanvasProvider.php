<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Article;
use App\Entity\Assureur;
use App\Entity\AutoriteFiscale;
use App\Entity\Client;
use App\Entity\CompteBancaire;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Entity\Partenaire;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class NoteEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Note::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Note de Débit/Crédit",
                "icone" => "mdi:file-document-multiple-outline",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Note [[*reference]] - [[nom]].",
                    " Type: [[typeString]], Destinataire: [[addressedToString]].",
                    " Montant: [[montantTotal]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "reference", "intitule" => "Référence", "type" => "Texte"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                ["code" => "invite", "intitule" => "Créateur", "type" => "Relation", "targetEntity" => Invite::class, "displayField" => "email"],
                ["code" => "client", "intitule" => "Client", "type" => "Relation", "targetEntity" => Client::class, "displayField" => "nom"],
                ["code" => "partenaire", "intitule" => "Partenaire", "type" => "Relation", "targetEntity" => Partenaire::class, "displayField" => "nom"],
                ["code" => "assureur", "intitule" => "Assureur", "type" => "Relation", "targetEntity" => Assureur::class, "displayField" => "nom"],
                ["code" => "autoritefiscale", "intitule" => "Autorité Fiscale", "type" => "Relation", "targetEntity" => AutoriteFiscale::class, "displayField" => "nom"],
                ["code" => "validated", "intitule" => "Validée", "type" => "Booleen"],
                ["code" => "sentAt", "intitule" => "Envoyée le", "type" => "Date"],
                ["code" => "articles", "intitule" => "Articles", "type" => "Collection", "targetEntity" => Article::class, "displayField" => "nom"],
                ["code" => "paiements", "intitule" => "Paiements", "type" => "Collection", "targetEntity" => Paiement::class, "displayField" => "reference"],
                ["code" => "comptes", "intitule" => "Comptes Bancaires", "type" => "Collection", "targetEntity" => CompteBancaire::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators(), $this->canvasHelper->getGlobalIndicatorsCanvas("Note"))
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["code" => "typeString", "intitule" => "Type", "type" => "Texte", "format" => "Texte", "description" => "Indique s'il s'agit d'une note de débit ou de crédit."],
            ["code" => "addressedToString", "intitule" => "Destinataire", "type" => "Texte", "format" => "Texte", "description" => "Entité à qui la note est adressée (Client, Assureur, etc.)."],
            ["code" => "montantTotal", "intitule" => "Montant Total", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "Somme totale des articles de la note."],
            ["code" => "montantPaye", "intitule" => "Montant Payé", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "Somme totale des paiements reçus pour cette note."],
            ["code" => "solde", "intitule" => "Solde", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "Montant restant à payer."],
            ["code" => "statutPaiement", "intitule" => "Statut Paiement", "type" => "Texte", "format" => "Texte", "description" => "Statut du paiement (Impayée, Partiel, Payée)."],
        ];
    }
}