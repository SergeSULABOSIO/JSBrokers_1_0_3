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
use App\Services\ServiceMonnaies;

class NoteEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies
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
                "icone" => "note", // Alias for 'tdesign:bill-filled'
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Note [[*reference]] - [[nom]].",
                    " Type: [[typeString]], Destinataire: [[addressedToString]].",
                    " Montant: [[montantTotal]], Solde: [[solde]]."
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
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        $monnaie = $this->serviceMonnaies->getCodeMonnaieAffichage();
        return [
            ["group" => "Détails", "code" => "typeString", "intitule" => "Type", "type" => "Calcul", "format" => "Texte", "description" => "Indique s'il s'agit d'une note de débit ou de crédit."],
            ["group" => "Détails", "code" => "addressedToString", "intitule" => "Destinataire", "type" => "Calcul", "format" => "Texte", "description" => "Entité à qui la note est adressée (Client, Assureur, etc.)."],
            ["group" => "Finances", "code" => "montantTotal", "intitule" => "Montant Total", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Somme totale des articles de la note."],
            ["group" => "Finances", "code" => "montantPaye", "intitule" => "Montant Payé", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Somme totale des paiements reçus pour cette note."],
            ["group" => "Finances", "code" => "solde", "intitule" => "Solde", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant restant à payer."],
            ["group" => "Finances", "code" => "statutPaiement", "intitule" => "Statut Paiement", "type" => "Calcul", "format" => "Texte", "description" => "Statut du paiement (Impayée, Partiel, Payée)."],
        ];
    }
}