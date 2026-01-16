<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Bordereau;
use App\Entity\Entreprise;
use App\Entity\Feedback;
use App\Entity\Invite;
use App\Entity\Note;
use App\Entity\NotificationSinistre;
use App\Entity\PieceSinistre;
use App\Entity\Piste;
use App\Entity\RolesEnAdministration;
use App\Entity\RolesEnFinance;
use App\Entity\RolesEnMarketing;
use App\Entity\RolesEnProduction;
use App\Entity\RolesEnSinistre;
use App\Entity\Tache;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class InviteEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Invite::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Collaborateur (Invité)",
                "icone" => "mdi:account-key-outline",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Collaborateur [[*nom]] ([[email]]).",
                    " Rôle principal: [[rolePrincipal]].",
                    " Propriétaire: [[proprietaireString]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "email", "intitule" => "Email", "type" => "Texte"],
                ["code" => "proprietaire", "intitule" => "Propriétaire", "type" => "Booleen"],
                ["code" => "entreprise", "intitule" => "Entreprise", "type" => "Relation", "targetEntity" => Entreprise::class, "displayField" => "nom"],
                ["code" => "invite", "intitule" => "Superviseur", "type" => "Relation", "targetEntity" => Invite::class, "displayField" => "nom"],
                ["code" => "createdAt", "intitule" => "Invité le", "type" => "Date"],
                ["code" => "pistes", "intitule" => "Pistes", "type" => "Collection", "targetEntity" => Piste::class, "displayField" => "nom"],
                ["code" => "taches", "intitule" => "Tâches", "type" => "Collection", "targetEntity" => Tache::class, "displayField" => "description"],
                ["code" => "feedback", "intitule" => "Feedbacks", "type" => "Collection", "targetEntity" => Feedback::class, "displayField" => "description"],
                ["code" => "bordereaus", "intitule" => "Bordereaux", "type" => "Collection", "targetEntity" => Bordereau::class, "displayField" => "nom"],
                ["code" => "pieceSinistres", "intitule" => "Pièces Sinistre", "type" => "Collection", "targetEntity" => PieceSinistre::class, "displayField" => "description"],
                ["code" => "notificationSinistres", "intitule" => "Sinistres", "type" => "Collection", "targetEntity" => NotificationSinistre::class, "displayField" => "referenceSinistre"],
                ["code" => "assistants", "intitule" => "Assistants", "type" => "Collection", "targetEntity" => Invite::class, "displayField" => "nom"],
                ["code" => "notes", "intitule" => "Notes", "type" => "Collection", "targetEntity" => Note::class, "displayField" => "reference"],
                ["code" => "rolesEnFinance", "intitule" => "Rôles Finance", "type" => "Collection", "targetEntity" => RolesEnFinance::class, "displayField" => "nom"],
                ["code" => "rolesEnMarketing", "intitule" => "Rôles Marketing", "type" => "Collection", "targetEntity" => RolesEnMarketing::class, "displayField" => "nom"],
                ["code" => "rolesEnProduction", "intitule" => "Rôles Production", "type" => "Collection", "targetEntity" => RolesEnProduction::class, "displayField" => "nom"],
                ["code" => "rolesEnSinistre", "intitule" => "Rôles Sinistre", "type" => "Collection", "targetEntity" => RolesEnSinistre::class, "displayField" => "nom"],
                ["code" => "rolesEnAdministration", "intitule" => "Rôles Admin", "type" => "Collection", "targetEntity" => RolesEnAdministration::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators(), $this->canvasHelper->getGlobalIndicatorsCanvas("Invite"))
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["code" => "ageInvitation", "intitule" => "Ancienneté", "type" => "Texte", "format" => "Texte", "description" => "Nombre de jours depuis l'invitation du collaborateur."],
            ["code" => "tachesEnCours", "intitule" => "Tâches en cours", "type" => "Entier", "format" => "Nombre", "description" => "Nombre de tâches non clôturées assignées à ce collaborateur."],
            ["code" => "rolePrincipal", "intitule" => "Rôle Principal", "type" => "Texte", "format" => "Texte", "description" => "Le ou les départements principaux dans lesquels le collaborateur a des rôles."],
            ["code" => "proprietaireString", "intitule" => "Est Propriétaire", "type" => "Texte", "format" => "Texte", "description" => "Indique si le collaborateur est propriétaire de l'entreprise."],
        ];
    }
}