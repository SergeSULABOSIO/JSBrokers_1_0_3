<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Feedback;
use App\Entity\Invite;
use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\Piste;
use App\Entity\Tache;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class TacheEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Tache::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Tâche",
                "icone" => "tache",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Tâche: [[*description]].",
                    " À terminer avant le [[toBeEndedAt]].",
                    " Exécuteur: [[executor]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                ["code" => "executor", "intitule" => "Exécuteur", "type" => "Relation", "targetEntity" => Invite::class, "displayField" => "email"],
                ["code" => "toBeEndedAt", "intitule" => "Échéance", "type" => "Date"],
                ["code" => "closed", "intitule" => "Clôturée", "type" => "Booleen"],
                ["code" => "piste", "intitule" => "Piste", "type" => "Relation", "targetEntity" => Piste::class, "displayField" => "nom"],
                ["code" => "cotation", "intitule" => "Cotation", "type" => "Relation", "targetEntity" => Cotation::class, "displayField" => "nom"],
                ["code" => "notificationSinistre", "intitule" => "Sinistre", "type" => "Relation", "targetEntity" => NotificationSinistre::class, "displayField" => "referenceSinistre"],
                ["code" => "offreIndemnisationSinistre", "intitule" => "Offre d'indemnisation", "type" => "Relation", "targetEntity" => OffreIndemnisationSinistre::class, "displayField" => "nom"],
                ["code" => "feedbacks", "intitule" => "Feedbacks", "type" => "Collection", "targetEntity" => Feedback::class, "displayField" => "description"],
                ["code" => "documents", "intitule" => "Documents", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["code" => "statutExecution", "intitule" => "Statut", "type" => "Texte", "format" => "Texte", "description" => "Statut d'exécution de la tâche (En cours, Expirée, Terminée)."],
            ["code" => "delaiRestant", "intitule" => "Délai Restant", "type" => "Texte", "format" => "Texte", "description" => "Temps restant avant l'échéance de la tâche."],
            ["code" => "ageTache", "intitule" => "Âge", "type" => "Texte", "format" => "Texte", "description" => "Nombre de jours depuis la création de la tâche."],
            ["code" => "nombreFeedbacks", "intitule" => "Nb. Feedbacks", "type" => "Entier", "format" => "Nombre", "description" => "Nombre de feedbacks enregistrés pour cette tâche."],
            ["code" => "contexteTache", "intitule" => "Contexte", "type" => "Texte", "format" => "Texte", "description" => "Entité parente à laquelle la tâche est rattachée (Piste, Cotation, etc.)."],
        ];
    }
}