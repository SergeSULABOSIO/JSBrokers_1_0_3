<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\DemandeConge;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\TypeAbsence;

class DemandeCongeEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === DemandeConge::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Demande de congé",
                "icone" => "conge",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Congé de [[*agentNom]].",
                    " [[periodeLibelle]].",
                    " [[typeAbsenceLibelle]].",
                    " Statut : [[statutLibelle]].",
                ],
            ],
            // ⚠ CE CANEVAS A TROIS CONSOMMATEURS : la fiche, la recherche avancée et le
            // vocabulaire de l'assistant. Retirer un attribut d'ici supprime aussi le
            // FILTRE correspondant de la barre de recherche.
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "agent", "intitule" => "Collaborateur", "type" => "Relation", "targetEntity" => Invite::class, "displayField" => "nom"],
                ["code" => "typeAbsence", "intitule" => "Type d'absence", "type" => "Relation", "targetEntity" => TypeAbsence::class, "displayField" => "libelle"],
                ["code" => "dateDebut", "intitule" => "Du", "type" => "Date"],
                ["code" => "dateFin", "intitule" => "Au", "type" => "Date"],
                ["code" => "demiJourneeDebut", "intitule" => "Demi-journée de début", "type" => "Booleen"],
                ["code" => "demiJourneeFin", "intitule" => "Demi-journée de fin", "type" => "Booleen"],
                ["code" => "nbJours", "intitule" => "Jours décomptés", "type" => "Nombre", "unite" => "j"],
                ["code" => "motif", "intitule" => "Motif", "type" => "Texte"],
                ["code" => "statut", "intitule" => "Statut", "type" => "Texte"],
                ["code" => "valideur", "intitule" => "Décidé par", "type" => "Relation", "targetEntity" => Invite::class, "displayField" => "nom"],
                ["code" => "dateDecision", "intitule" => "Date de décision", "type" => "Date"],
                ["code" => "commentaireDecision", "intitule" => "Commentaire de décision", "type" => "Texte"],
                ["code" => "origine", "intitule" => "Origine", "type" => "Texte"],
                // Ce qu'un valideur a fait franchir à cette demande : préavis, plafond
                // d'absents, période de blocage. Vide dans le cas ordinaire.
                ["code" => "controlesContournes", "intitule" => "Contrôles contournés", "type" => "Texte"],
                ["code" => "documents", "intitule" => "Justificatifs", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
                ["code" => "entreprise", "intitule" => "Entreprise", "type" => "Relation", "targetEntity" => Entreprise::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators()),
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["group" => "Demande", "code" => "agentNom", "intitule" => "Collaborateur", "type" => "Calcul", "format" => "Texte", "description" => "Nom du collaborateur qui pose le congé."],
            ["group" => "Demande", "code" => "periodeLibelle", "intitule" => "Période", "type" => "Calcul", "format" => "Texte", "description" => "Dates, nombre de jours décomptés et demi-journées de bord."],
            ["group" => "Demande", "code" => "typeAbsenceLibelle", "intitule" => "Type d'absence", "type" => "Calcul", "format" => "Texte", "description" => "Nature de l'absence demandée."],
            ["group" => "Demande", "code" => "statutLibelle", "intitule" => "Statut", "type" => "Calcul", "format" => "Texte", "description" => "État de la demande, complété de « en cours » ou « échue » selon la date."],
            ["group" => "Décision", "code" => "valideurNom", "intitule" => "Décidé par", "type" => "Calcul", "format" => "Texte", "description" => "Valideur ayant rendu la décision."],
            // LE COMPTEUR, SUR LA FICHE. Le valideur décide en le voyant, et c'est le
            // même calcul que la liste et que les e-mails : un seul chiffre, partout.
            ["group" => "Compteur", "code" => "soldeDisponibleAgent", "intitule" => "Solde disponible du collaborateur", "type" => "Calcul", "format" => "Nombre", "unite" => "j", "description" => "Ce que le collaborateur peut encore poser sur l'exercice, engagements compris."],
            ["group" => "Dossier", "code" => "nombreDocuments", "intitule" => "Justificatifs", "type" => "Calcul", "format" => "Entier", "description" => "Nombre de pièces attachées à la demande."],
        ];
    }
}
