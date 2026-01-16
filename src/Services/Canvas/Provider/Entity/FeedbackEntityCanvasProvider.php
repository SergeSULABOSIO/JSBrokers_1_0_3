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
                "icone" => "mdi:message-reply-text-outline",
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
            ], $this->getSpecificIndicators(), $this->canvasHelper->getGlobalIndicatorsCanvas("Feedback"))
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["code" => "typeString", "intitule" => "Type", "type" => "Texte", "format" => "Texte", "description" => "Type de communication (Réunion, Appel, Email, etc.)."],
            ["code" => "delaiProchaineAction", "intitule" => "Délai Proch. Action", "type" => "Texte", "format" => "Texte", "description" => "Temps restant avant la prochaine action planifiée."],
            ["code" => "ageFeedback", "intitule" => "Âge", "type" => "Texte", "format" => "Texte", "description" => "Nombre de jours depuis la création du feedback."],
            ["code" => "statutProchaineAction", "intitule" => "Statut Action", "type" => "Texte", "format" => "Texte", "description" => "Indique si une prochaine action est planifiée ou non."],
        ];
    }
}