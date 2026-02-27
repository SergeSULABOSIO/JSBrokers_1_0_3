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
            ["group" => "Suivi & Délais", "code" => "statutExecution", "intitule" => "Statut", "type" => "Texte", "format" => "Texte", "description" => "Statut d'exécution de la tâche (En cours, Expirée, Terminée)."],
            ["group" => "Suivi & Délais", "code" => "prioriteCalculee", "intitule" => "Priorité suggérée", "type" => "Calcul", "format" => "Texte", "description" => "Niveau d'urgence calculé en fonction de la date d'échéance."],
            ["group" => "Suivi & Délais", "code" => "delaiRestant", "intitule" => "Délai Restant", "type" => "Texte", "format" => "Texte", "description" => "Temps restant avant l'échéance de la tâche."],
            ["group" => "Suivi & Délais", "code" => "ageTache", "intitule" => "Âge", "type" => "Texte", "format" => "Texte", "description" => "Nombre de jours depuis la création de la tâche."],
            
            ["group" => "Activité", "code" => "nombreFeedbacks", "intitule" => "Nb. Feedbacks", "type" => "Entier", "format" => "Nombre", "description" => "Nombre de feedbacks enregistrés pour cette tâche."],
            ["group" => "Activité", "code" => "dernierFeedbackDate", "intitule" => "Dernier Feedback", "type" => "Calcul", "format" => "Date", "description" => "Date du dernier compte-rendu enregistré."],
            ["group" => "Activité", "code" => "nombreDocuments", "intitule" => "Nb. Documents", "type" => "Calcul", "format" => "Nombre", "description" => "Nombre de documents joints à la tâche."],

            ["group" => "Contexte", "code" => "contexteTache", "intitule" => "Contexte", "type" => "Calcul", "format" => "Texte", "fonction" => "getTacheContexteString", "description" => "Entité parente à laquelle la tâche est rattachée (Piste, Cotation, etc.)."],
            ["group" => "Contexte", "code" => "clientConcerne", "intitule" => "Client concerné", "type" => "Calcul", "format" => "Texte", "description" => "Nom du client lié à l'affaire ou au sinistre concerné."],
        ];
    }
}