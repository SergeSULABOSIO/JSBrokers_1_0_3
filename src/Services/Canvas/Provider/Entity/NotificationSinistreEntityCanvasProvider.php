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
                "description" => "Notification de Sinistre",
                "icone" => "sinistre",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Sinistre [[referenceSinistre]] pour [[assureNom]].",
                    " Police n° [[referencePolice]].",
                    " Évaluation: [[evaluationChiffree]] [[currency_symbol]].",
                    " Compensation due: [[compensation]] [[currency_symbol]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "referenceSinistre", "intitule" => "Référence Sinistre", "type" => "Texte"],
                ["code" => "referencePolice", "intitule" => "Référence Police", "type" => "Texte"],
                ["code" => "descriptionDeFait", "intitule" => "Description des faits", "type" => "Texte"],
                ["code" => "occuredAt", "intitule" => "Date de survenance", "type" => "Date"],
                ["code" => "notifiedAt", "intitule" => "Date de notification", "type" => "Date"],
                ["code" => "lieu", "intitule" => "Lieu", "type" => "Texte"],
                ["code" => "descriptionVictimes", "intitule" => "Description des victimes", "type" => "Texte"],
                ["code" => "dommage", "intitule" => "Dommage (avant éval.)", "type" => "Nombre"],
                ["code" => "evaluationChiffree", "intitule" => "Évaluation chiffrée", "type" => "Nombre"],
                ["code" => "assure", "intitule" => "Assuré", "type" => "Relation", "targetEntity" => Client::class, "displayField" => "nom"],
                ["code" => "assureur", "intitule" => "Assureur", "type" => "Relation", "targetEntity" => Assureur::class, "displayField" => "nom"],
                ["code" => "risque", "intitule" => "Risque", "type" => "Relation", "targetEntity" => Risque::class, "displayField" => "nomComplet"],
                ["code" => "invite", "intitule" => "Gestionnaire", "type" => "Relation", "targetEntity" => Invite::class, "displayField" => "nom"],
                ["code" => "contacts", "intitule" => "Contacts", "type" => "Collection", "targetEntity" => Contact::class, "displayField" => "nom"],
                ["code" => "pieces", "intitule" => "Pièces", "type" => "Collection", "targetEntity" => PieceSinistre::class, "displayField" => "description"],
                ["code" => "offreIndemnisationSinistres", "intitule" => "Offres d'indemnisation", "type" => "Collection", "targetEntity" => OffreIndemnisationSinistre::class, "displayField" => "nom"],
                ["code" => "taches", "intitule" => "Tâches", "type" => "Collection", "targetEntity" => Tache::class, "displayField" => "description"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["code" => "assureNom", "intitule" => "Nom de l'assuré", "type" => "Calcul", "format" => "Texte", "fonction" => "getAssureNom", "description" => "Nom du client assuré."],
            ["code" => "delaiDeclaration", "intitule" => "Délai de déclaration", "type" => "Calcul", "format" => "Texte", "fonction" => "calculateDelaiDeclaration", "description" => "Délai en jours entre la survenance et la notification du sinistre."],
            ["code" => "ageDossier", "intitule" => "Âge du dossier", "type" => "Calcul", "format" => "Texte", "fonction" => "calculateAgeDossier", "description" => "Âge du dossier depuis sa création."],
            ["code" => "compensationFranchise", "intitule" => "Franchise appliquée", "type" => "Calcul", "format" => "Monnaie", "fonction" => "calculateFranchise", "description" => "Montant de la franchise appliquée."],
            ["code" => "tauxIndemnisation", "intitule" => "Taux d'indemnisation", "type" => "Calcul", "format" => "Pourcentage", "fonction" => "getNotificationSinistreTauxIndemnisation", "description" => "Rapport entre les offres et l'évaluation chiffrée."],
            ["code" => "nombreOffres", "intitule" => "Nombre d'offres", "type" => "Calcul", "format" => "Nombre", "fonction" => "getNotificationSinistreNombreOffres", "description" => "Nombre total d'offres d'indemnisation."],
            ["code" => "nombrePaiements", "intitule" => "Nombre de paiements", "type" => "Calcul", "format" => "Nombre", "fonction" => "getNotificationSinistreNombrePaiements", "description" => "Nombre total de paiements effectués."],
            ["code" => "montantMoyenParPaiement", "intitule" => "Paiement moyen", "type" => "Calcul", "format" => "Monnaie", "fonction" => "getNotificationSinistreMontantMoyenParPaiement", "description" => "Montant moyen par paiement."],
            ["code" => "delaiTraitementInitial", "intitule" => "Délai traitement initial", "type" => "Calcul", "format" => "Texte", "fonction" => "getNotificationSinistreDelaiTraitementInitial", "description" => "Délai entre la création du dossier et la notification."],
            ["code" => "ratioPaiementsEvaluation", "intitule" => "Ratio Paiements/Éval.", "type" => "Calcul", "format" => "Pourcentage", "fonction" => "getNotificationSinistreRatioPaiementsEvaluation", "description" => "Ratio des paiements par rapport à l'évaluation chiffrée."],
            ["code" => "compensation", "intitule" => "Compensation Due", "type" => "Calcul", "format" => "Monnaie", "fonction" => "getNotificationSinistreCompensation", "description" => "Montant total de l'indemnisation convenue."],
            ["code" => "compensationVersee", "intitule" => "Compensation Versée", "type" => "Calcul", "format" => "Monnaie", "fonction" => "getNotificationSinistreCompensationVersee", "description" => "Montant total déjà versé."],
            ["code" => "compensationSoldeAverser", "intitule" => "Solde à verser", "type" => "Calcul", "format" => "Monnaie", "fonction" => "getNotificationSinistreSoldeAVerser", "description" => "Montant restant à verser pour solder l'indemnisation."],
            ["code" => "indiceCompletude", "intitule" => "Indice de complétude", "type" => "Calcul", "format" => "Pourcentage", "fonction" => "getNotificationSinistreIndiceCompletude", "description" => "Pourcentage de pièces fournies par rapport aux pièces attendues."],
            ["code" => "dateDernierReglement", "intitule" => "Date du dernier règlement", "type" => "Calcul", "format" => "Date", "fonction" => "getNotificationSinistreDateDernierReglement", "description" => "Date du tout dernier paiement effectué pour ce sinistre."],
            ["code" => "dureeReglement", "intitule" => "Durée de règlement", "type" => "Calcul", "format" => "Texte", "fonction" => "getNotificationSinistreDureeReglement", "description" => "Durée totale en jours entre la notification et le dernier règlement."],
            ["code" => "statusDocumentsAttendus", "intitule" => "Statut des documents", "type" => "Calcul", "format" => "Tableau", "fonction" => "getNotificationSinistreStatusDocumentsAttendus", "description" => "Statut des documents attendus, fournis et manquants."],
        ];
    }
}