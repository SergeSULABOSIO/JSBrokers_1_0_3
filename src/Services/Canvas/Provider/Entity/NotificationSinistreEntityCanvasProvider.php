<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Assureur;
use App\Entity\Client;
use App\Entity\Contact;
use App\Entity\Invite;
use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\PieceSinistre;
use App\Entity\Risque;
use App\Entity\Tache;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class NotificationSinistreEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === NotificationSinistre::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Déclaration de Sinistre",
                "icone" => "mdi:alert-decagram-outline",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Sinistre [[*referenceSinistre]] pour l'assuré [[assure]].",
                    " Police n°[[referencePolice]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "referencePolice", "intitule" => "Réf. Police", "type" => "Texte"],
                ["code" => "referenceSinistre", "intitule" => "Réf. Sinistre", "type" => "Texte"],
                ["code" => "descriptionDeFait", "intitule" => "Description", "type" => "Texte"],
                ["code" => "assure", "intitule" => "Assuré", "type" => "Relation", "targetEntity" => Client::class, "displayField" => "nom"],
                ["code" => "occuredAt", "intitule" => "Date de survenance", "type" => "Date"],
                ["code" => "lieu", "intitule" => "Lieu", "type" => "Texte"],
                ["code" => "risque", "intitule" => "Risque", "type" => "Relation", "targetEntity" => Risque::class, "displayField" => "nomComplet"],
                ["code" => "createdAt", "intitule" => "Créé le", "type" => "Date"],
                ["code" => "updatedAt", "intitule" => "Modifié le", "type" => "Date"],
                ["code" => "invite", "intitule" => "Gestionnaire", "type" => "Relation", "targetEntity" => Invite::class, "displayField" => "email"],
                ["code" => "dommage", "intitule" => "Dommage estimé", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                ["code" => "evaluationChiffree", "intitule" => "Évaluation chiffrée", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                ["code" => "notifiedAt", "intitule" => "Date de déclaration", "type" => "Date"],
                ["code" => "assureur", "intitule" => "Assureur", "type" => "Relation", "targetEntity" => Assureur::class, "displayField" => "nom"],
                ["code" => "descriptionVictimes", "intitule" => "Victimes", "type" => "Texte"],
                ["code" => "offreIndemnisationSinistres", "intitule" => "Offres", "type" => "Collection", "targetEntity" => OffreIndemnisationSinistre::class, "displayField" => "nom"],
                ["code" => "pieces", "intitule" => "Pièces", "type" => "Collection", "targetEntity" => PieceSinistre::class, "displayField" => "description"],
                ["code" => "contacts", "intitule" => "Contacts", "type" => "Collection", "targetEntity" => Contact::class, "displayField" => "nom"],
                ["code" => "taches", "intitule" => "Tâches", "type" => "Collection", "targetEntity" => Tache::class, "displayField" => "description"],
            ], $this->getSpecificIndicators(), $this->canvasHelper->getGlobalIndicatorsCanvas("NotificationSinistre"))
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["code" => "delaiDeclaration", "intitule" => "Délai Déclaration", "type" => "Texte", "format" => "Texte", "description" => "Délai entre la survenance et la déclaration du sinistre."],
            ["code" => "ageDossier", "intitule" => "Âge du Dossier", "type" => "Texte", "format" => "Texte", "description" => "Nombre de jours depuis la création du dossier."],
            ["code" => "compensation", "intitule" => "Indemnité Totale", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "Montant total des offres d'indemnisation."],
            ["code" => "compensationVersee", "intitule" => "Indemnité Versée", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "Montant total déjà versé pour ce sinistre."],
            ["code" => "compensationSoldeAverser", "intitule" => "Solde à Verser", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "Montant restant à payer pour ce sinistre."],
            ["code" => "compensationFranchise", "intitule" => "Franchise Appliquée", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "Montant total de la franchise appliquée."],
            ["code" => "tauxIndemnisation", "intitule" => "Taux Indemnisation", "type" => "Nombre", "unite" => "%", "description" => "Ratio entre l'indemnité totale offerte et l'évaluation chiffrée."],
            ["code" => "nombreOffres", "intitule" => "Nb. Offres", "type" => "Entier", "format" => "Nombre", "description" => "Nombre total d'offres d'indemnisation."],
            ["code" => "nombrePaiements", "intitule" => "Nb. Paiements", "type" => "Entier", "format" => "Nombre", "description" => "Nombre total de paiements effectués."],
            ["code" => "montantMoyenParPaiement", "intitule" => "Moy. par Paiement", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "Montant moyen par paiement."],
            ["code" => "delaiTraitementInitial", "intitule" => "Délai Trait. Initial", "type" => "Texte", "format" => "Texte", "description" => "Délai entre la création du dossier et la déclaration à l'assureur."],
            ["code" => "ratioPaiementsEvaluation", "intitule" => "Ratio Paiement/Éval.", "type" => "Nombre", "unite" => "%", "description" => "Ratio entre le montant versé et l'évaluation chiffrée."],
            ["code" => "indiceCompletude", "intitule" => "Complétude Dossier", "type" => "Texte", "format" => "Texte", "description" => "Pourcentage des pièces requises qui ont été fournies."],
            ["code" => "dateDernierReglement", "intitule" => "Date Dernier Règlement", "type" => "Date", "description" => "Date du dernier paiement effectué pour ce sinistre."],
            ["code" => "dureeReglement", "intitule" => "Durée Règlement (j)", "type" => "Entier", "format" => "Nombre", "description" => "Nombre de jours entre la déclaration et le dernier règlement."],
            ["code" => "statusDocumentsAttendus", "intitule" => "Statut Documents", "type" => "Texte", "format" => "Texte", "description" => "Résumé des documents attendus, fournis et manquants."],
        ];
    }
}