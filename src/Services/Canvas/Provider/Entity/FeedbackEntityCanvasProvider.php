<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Document;
use App\Entity\Feedback;
use App\Entity\Invite;
use App\Entity\Tache;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class FeedbackEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Feedback::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Feedback",
                "icone" => "feedback",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Feedback du [[createdAt]] par [[invite]].",
                    " Type: [[typeString]].",
                    " « [[*description]] »"
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                ["code" => "invite", "intitule" => "Auteur", "type" => "Relation", "targetEntity" => Invite::class, "displayField" => "email"],
                ["code" => "tache", "intitule" => "Tâche", "type" => "Relation", "targetEntity" => Tache::class, "displayField" => "description"],
                ["code" => "createdAt", "intitule" => "Date", "type" => "Date"],
                ["code" => "hasNextAction", "intitule" => "Prochaine Action", "type" => "Booleen"],
                ["code" => "nextAction", "intitule" => "Détail Action", "type" => "Texte"],
                ["code" => "nextActionAt", "intitule" => "Date Action", "type" => "Date"],
                ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["group" => "Informations Générales", "code" => "typeString", "intitule" => "Type", "type" => "Calcul", "format" => "Texte", "description" => "Type de communication (Réunion, Appel, Email, etc.)."],
            ["group" => "Informations Générales", "code" => "ageFeedback", "intitule" => "Âge", "type" => "Calcul", "format" => "Texte", "description" => "Nombre de jours depuis la création du feedback."],
            ["group" => "Informations Générales", "code" => "auteurNom", "intitule" => "Auteur", "type" => "Calcul", "format" => "Texte", "description" => "Nom de l'utilisateur ayant créé ce feedback."],
            
            ["group" => "Suivi & Actions", "code" => "statutProchaineAction", "intitule" => "Statut Action", "type" => "Calcul", "format" => "Texte", "description" => "Indique si une prochaine action est planifiée ou non."],
            ["group" => "Suivi & Actions", "code" => "delaiProchaineAction", "intitule" => "Délai Proch. Action", "type" => "Calcul", "format" => "Texte", "description" => "Temps restant avant la prochaine action planifiée."],
            ["group" => "Suivi & Actions", "code" => "estEnRetard", "intitule" => "En retard ?", "type" => "Calcul", "format" => "Texte", "description" => "Indique si la prochaine action est en retard."],

            ["group" => "Contenu", "code" => "nombreDocuments", "intitule" => "Nb. Documents", "type" => "Calcul", "format" => "Nombre", "description" => "Nombre de documents joints à ce feedback."],
        ];
    }
}