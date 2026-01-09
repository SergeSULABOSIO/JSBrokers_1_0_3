<?php

namespace App\Services\Canvas;

use App\Entity\Assureur;
use App\Entity\AutoriteFiscale;
use App\Entity\Avenant;
use App\Entity\Bordereau;
use App\Entity\Chargement;
use App\Entity\ChargementPourPrime;
use App\Entity\Classeur;
use App\Entity\Client;
use App\Entity\CompteBancaire;
use App\Entity\ConditionPartage;
use App\Entity\Contact;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Feedback;
use App\Entity\Groupe;
use App\Entity\Invite;
use App\Entity\ModelePieceSinistre;
use App\Entity\Monnaie;
use App\Entity\Note;
use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\Paiement;
use App\Entity\Partenaire;
use App\Entity\PieceSinistre;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\Tache;
use App\Entity\Taxe;
use App\Entity\Tranche;
use App\Entity\TypeRevenu;

class FormCanvasProvider
{
    public function getCanvas($object, $idEntreprise): array
    {
        $isParentNew = ($object->getId() === null);
        $entityClassName = get_class($object);
        $layout = [];
        $parametres = [];

        switch ($entityClassName) {
            case NotificationSinistre::class:
                $notificationId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouvelle Notification de Sinistre",
                    "titre_modification" => "Modification de la Notification #%id%",
                    "endpoint_submit_url" => "/admin/notificationsinistre/api/submit",
                    "endpoint_delete_url" => "/admin/notificationsinistre/api/delete",
                    "endpoint_form_url" => "/admin/notificationsinistre/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildNotificationSinistreLayout($notificationId, $isParentNew);
                break;

            case Contact::class:
                $contactId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouveau contact",
                    "titre_modification" => "Modification du contact #%id%",
                    "endpoint_submit_url" => "/admin/contact/api/submit",
                    "endpoint_delete_url" => "/admin/contact/api/delete",
                    "endpoint_form_url" => "/admin/contact/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildContactLayout($contactId, $isParentNew);
                break;

            case Client::class:
                $clientId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouveau Client",
                    "titre_modification" => "Modification du Client #%id%",
                    "endpoint_submit_url" => "/admin/client/api/submit",
                    "endpoint_delete_url" => "/admin/client/api/delete",
                    "endpoint_form_url" => "/admin/client/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildClientLayout($clientId, $isParentNew);
                break;

            case Assureur::class:
                $assureurId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouvel Assureur",
                    "titre_modification" => "Modification de l'Assureur #%id%",
                    "endpoint_submit_url" => "/admin/assureur/api/submit",
                    "endpoint_delete_url" => "/admin/assureur/api/delete",
                    "endpoint_form_url" => "/admin/assureur/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildAssureurLayout($assureurId, $isParentNew);
                break;

            case Piste::class:
                $pisteId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouvelle Piste",
                    "titre_modification" => "Modification de la Piste #%id%",
                    "endpoint_submit_url" => "/admin/piste/api/submit",
                    "endpoint_delete_url" => "/admin/piste/api/delete",
                    "endpoint_form_url" => "/admin/piste/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildPisteLayout($pisteId, $isParentNew);
                break;

            case Cotation::class:
                $cotationId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouvelle Cotation",
                    "titre_modification" => "Modification de la Cotation #%id%",
                    "endpoint_submit_url" => "/admin/cotation/api/submit",
                    "endpoint_delete_url" => "/admin/cotation/api/delete",
                    "endpoint_form_url" => "/admin/cotation/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildCotationLayout($cotationId, $isParentNew);
                break;

            case Avenant::class:
                $avenantId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouvel Avenant",
                    "titre_modification" => "Modification de l'Avenant #%id%",
                    "endpoint_submit_url" => "/admin/avenant/api/submit",
                    "endpoint_delete_url" => "/admin/avenant/api/delete",
                    "endpoint_form_url" => "/admin/avenant/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildAvenantLayout($avenantId, $isParentNew);
                break;

            case PieceSinistre::class:
                $pieceId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouvelle pièce",
                    "titre_modification" => "Modification de la pièce #%id%",
                    "endpoint_submit_url" => "/admin/piecesinistre/api/submit",
                    "endpoint_delete_url" => "/admin/piecesinistre/api/delete",
                    "endpoint_form_url" => "/admin/piecesinistre/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildPieceSinistreLayout($pieceId, $isParentNew);
                break;

            case Document::class:
                $documentId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouveau Document",
                    "titre_modification" => "Modification du document #%id%",
                    "endpoint_submit_url" => "/admin/document/api/submit",
                    "endpoint_delete_url" => "/admin/document/api/delete",
                    "endpoint_form_url" => "/admin/document/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildDocumentLayout($documentId, $isParentNew);
                break;

            case OffreIndemnisationSinistre::class:
                $offreId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouvelle offre d'indemnisation",
                    "titre_modification" => "Modification de l'offre #%id%",
                    "endpoint_submit_url" => "/admin/offreindemnisationsinistre/api/submit",
                    "endpoint_delete_url" => "/admin/offreindemnisationsinistre/api/delete",
                    "endpoint_form_url" => "/admin/offreindemnisationsinistre/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildOffreIndemnisationLayout($offreId, $isParentNew);
                break;

            case Tache::class:
                $tacheId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouvelle tâche",
                    "titre_modification" => "Modification de la tâche #%id%",
                    "endpoint_submit_url" => "/admin/tache/api/submit",
                    "endpoint_delete_url" => "/admin/tache/api/delete",
                    "endpoint_form_url" => "/admin/tache/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildTacheLayout($tacheId, $isParentNew);
                break;

            case Paiement::class:
                $paiementId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouveau Paiement",
                    "titre_modification" => "Modification du paiement #%id%",
                    "endpoint_submit_url" => "/admin/paiement/api/submit",
                    "endpoint_delete_url" => "/admin/paiement/api/delete",
                    "endpoint_form_url" => "/admin/paiement/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildPaiementLayout($paiementId, $isParentNew);
                break;

            case Feedback::class:
                $feedbackId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouveau Feedback",
                    "titre_modification" => "Modification du feedback #%id%",
                    "endpoint_submit_url" => "/admin/feedback/api/submit",
                    "endpoint_delete_url" => "/admin/feedback/api/delete",
                    "endpoint_form_url" => "/admin/feedback/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildFeedbackLayout($feedbackId, $isParentNew);
                break;

            case Groupe::class:
                $groupeId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouveau Groupe",
                    "titre_modification" => "Modification du Groupe #%id%",
                    "endpoint_submit_url" => "/admin/groupe/api/submit",
                    "endpoint_delete_url" => "/admin/groupe/api/delete",
                    "endpoint_form_url" => "/admin/groupe/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildGroupeLayout($groupeId, $isParentNew);
                break;

            case Partenaire::class:
                $partenaireId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouveau Partenaire",
                    "titre_modification" => "Modification du Partenaire #%id%",
                    "endpoint_submit_url" => "/admin/partenaire/api/submit",
                    "endpoint_delete_url" => "/admin/partenaire/api/delete",
                    "endpoint_form_url" => "/admin/partenaire/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildPartenaireLayout($partenaireId, $isParentNew);
                break;

            case Risque::class:
                $risqueId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouveau Risque",
                    "titre_modification" => "Modification du Risque #%id%",
                    "endpoint_submit_url" => "/admin/risque/api/submit",
                    "endpoint_delete_url" => "/admin/risque/api/delete",
                    "endpoint_form_url" => "/admin/risque/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildRisqueLayout($risqueId, $isParentNew);
                break;

            case Bordereau::class:
                $bordereauId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouveau Bordereau",
                    "titre_modification" => "Modification du Bordereau #%id%",
                    "endpoint_submit_url" => "/admin/bordereau/api/submit",
                    "endpoint_delete_url" => "/admin/bordereau/api/delete",
                    "endpoint_form_url" => "/admin/bordereau/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildBordereauLayout($bordereauId, $isParentNew);
                break;

            case Chargement::class:
                $chargementId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouveau Type de Chargement",
                    "titre_modification" => "Modification du Type de Chargement #%id%",
                    "endpoint_submit_url" => "/admin/chargement/api/submit",
                    "endpoint_delete_url" => "/admin/chargement/api/delete",
                    "endpoint_form_url" => "/admin/chargement/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildChargementLayout($chargementId, $isParentNew);
                break;

            case AutoriteFiscale::class:
                $autoriteId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouvelle Autorité Fiscale",
                    "titre_modification" => "Modification de l'Autorité Fiscale #%id%",
                    "endpoint_submit_url" => "/admin/autoritefiscale/api/submit",
                    "endpoint_delete_url" => "/admin/autoritefiscale/api/delete",
                    "endpoint_form_url" => "/admin/autoritefiscale/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildAutoriteFiscaleLayout($autoriteId, $isParentNew);
                break;

            case CompteBancaire::class:
                $compteId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouveau Compte Bancaire",
                    "titre_modification" => "Modification du Compte Bancaire #%id%",
                    "endpoint_submit_url" => "/admin/comptebancaire/api/submit",
                    "endpoint_delete_url" => "/admin/comptebancaire/api/delete",
                    "endpoint_form_url" => "/admin/comptebancaire/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildCompteBancaireLayout($compteId, $isParentNew);
                break;

            case Note::class:
                $noteId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouvelle Note",
                    "titre_modification" => "Modification de la Note #%id%",
                    "endpoint_submit_url" => "/admin/note/api/submit",
                    "endpoint_delete_url" => "/admin/note/api/delete",
                    "endpoint_form_url" => "/admin/note/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildNoteLayout($noteId, $isParentNew);
                break;

            case Taxe::class:
                $taxeId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouvelle Taxe",
                    "titre_modification" => "Modification de la Taxe #%id%",
                    "endpoint_submit_url" => "/admin/taxe/api/submit",
                    "endpoint_delete_url" => "/admin/taxe/api/delete",
                    "endpoint_form_url" => "/admin/taxe/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildTaxeLayout($taxeId, $isParentNew);
                break;

            case Tranche::class:
                $trancheId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouvelle Tranche",
                    "titre_modification" => "Modification de la Tranche #%id%",
                    "endpoint_submit_url" => "/admin/tranche/api/submit",
                    "endpoint_delete_url" => "/admin/tranche/api/delete",
                    "endpoint_form_url" => "/admin/tranche/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildTrancheLayout($trancheId, $isParentNew);
                break;

            case TypeRevenu::class:
                $typeRevenuId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouveau Type de Revenu",
                    "titre_modification" => "Modification du Type de Revenu #%id%",
                    "endpoint_submit_url" => "/admin/typerevenu/api/submit",
                    "endpoint_delete_url" => "/admin/typerevenu/api/delete",
                    "endpoint_form_url" => "/admin/typerevenu/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildTypeRevenuLayout($typeRevenuId, $isParentNew);
                break;

            case Classeur::class:
                $classeurId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouveau Classeur",
                    "titre_modification" => "Modification du Classeur #%id%",
                    "endpoint_submit_url" => "/admin/classeur/api/submit",
                    "endpoint_delete_url" => "/admin/classeur/api/delete",
                    "endpoint_form_url" => "/admin/classeur/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildClasseurLayout($classeurId, $isParentNew);
                break;

            case Entreprise::class:
                $entrepriseId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouvelle Entreprise",
                    "titre_modification" => "Modification de l'Entreprise #%id%",
                    "endpoint_submit_url" => "/admin/entreprise/api/submit",
                    "endpoint_delete_url" => "/admin/entreprise/api/delete",
                    "endpoint_form_url" => "/admin/entreprise/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildEntrepriseLayout($entrepriseId, $isParentNew);
                break;

            case Invite::class:
                $inviteId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouvelle Invitation",
                    "titre_modification" => "Modification de l'Invitation #%id%",
                    "endpoint_submit_url" => "/admin/invite/api/submit",
                    "endpoint_delete_url" => "/admin/invite/api/delete",
                    "endpoint_form_url" => "/admin/invite/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildInviteLayout($inviteId, $isParentNew);
                break;

            case ConditionPartage::class:
                $conditionId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouvelle Condition de Partage",
                    "titre_modification" => "Modification de la Condition #%id%",
                    "endpoint_submit_url" => "/admin/conditionpartage/api/submit",
                    "endpoint_delete_url" => "/admin/conditionpartage/api/delete",
                    "endpoint_form_url" => "/admin/conditionpartage/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildConditionPartageLayout($conditionId, $isParentNew);
                break;

            case RevenuPourCourtier::class:
                $revenuId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouveau Revenu pour Courtier",
                    "titre_modification" => "Modification du Revenu #%id%",
                    "endpoint_submit_url" => "/admin/revenupourcourtier/api/submit",
                    "endpoint_delete_url" => "/admin/revenupourcourtier/api/delete",
                    "endpoint_form_url" => "/admin/revenupourcourtier/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildRevenuPourCourtierLayout($revenuId, $isParentNew);
                break;

            default:
                return [];
        }

        // Si aucune configuration n'a été trouvée, on retourne un tableau vide.
        if (empty($parametres) && empty($layout)) {
            return [];
        }

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout) // Ajout de la carte des champs pour un accès optimisé
        ];
    }

    /**
     * Construit dynamiquement le layout du formulaire pour NotificationSinistre.
     *
     * @param integer $notificationId
     * @param boolean $isParentNew
     * @return array
     */
    private function buildNotificationSinistreLayout(int $notificationId, bool $isParentNew): array
    {
        $layout = [
            // Ligne 1 : 2 colonnes
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["assure"]], ["champs" => ["assureur"]]]],
            // Ligne 2 : 1 colonne
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["risque"]]]],
            // Ligne 3 : 2 colonnes
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["referencePolice"]], ["champs" => ["referenceSinistre"]]]],
            // Ligne 4 : 1 colonne
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["descriptionDeFait"]]]],
            // Ligne 5 : 3 colonnes
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["occuredAt"]], ["champs" => ["notifiedAt"]], ["champs" => ["lieu"]]]],
            // Ligne 6 : 1 colonne 
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["descriptionVictimes"]]]],
            // Ligne 7 : 2 colonnes
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["dommage"]], ["champs" => ["evaluationChiffree"]]]]
        ];

        $collections = [
            ['fieldName' => 'contacts', 'entityRouteName' => 'contact', 'formTitle' => 'Contact', 'parentFieldName' => 'notificationSinistre'],
            ['fieldName' => 'pieces', 'entityRouteName' => 'piecesinistre', 'formTitle' => 'Pièce Sinistre', 'parentFieldName' => 'notificationSinistre'],
            ['fieldName' => 'offreIndemnisationSinistres', 'entityRouteName' => 'offreindemnisationsinistre', 'formTitle' => "Offre d'indemnisation", 'parentFieldName' => 'notificationSinistre'],
            ['fieldName' => 'taches', 'entityRouteName' => 'tache', 'formTitle' => 'Tâche', 'parentFieldName' => 'notificationSinistre'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $notificationId, $isParentNew, $collections);

        return $layout;
    }

    private function buildClientLayout(int $clientId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["email"]], ["champs" => ["telephone"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["adresse"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["groupe"]]]],
        ];

        $collections = [
            ['fieldName' => 'contacts', 'entityRouteName' => 'contact', 'formTitle' => 'Contact', 'parentFieldName' => 'client'],
            ['fieldName' => 'pistes', 'entityRouteName' => 'piste', 'formTitle' => 'Piste', 'parentFieldName' => 'client'],
            ['fieldName' => 'notificationSinistres', 'entityRouteName' => 'notificationsinistre', 'formTitle' => 'Sinistre', 'parentFieldName' => 'assure'],
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'client'],
            ['fieldName' => 'partenaires', 'entityRouteName' => 'partenaire', 'formTitle' => 'Partenaire', 'parentFieldName' => 'client'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $clientId, $isParentNew, $collections);
        return $layout;
    }

    private function buildAssureurLayout(int $assureurId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["email"]], ["champs" => ["telephone"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["adressePhysique"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["numimpot"]], ["champs" => ["idnat"]], ["champs" => ["rccm"]]]],
        ];

        $collections = [
            ['fieldName' => 'cotations', 'entityRouteName' => 'cotation', 'formTitle' => 'Cotation', 'parentFieldName' => 'assureur'],
            ['fieldName' => 'bordereaus', 'entityRouteName' => 'bordereau', 'formTitle' => 'Bordereau', 'parentFieldName' => 'assureur'],
            ['fieldName' => 'notificationSinistres', 'entityRouteName' => 'notificationsinistre', 'formTitle' => 'Sinistre', 'parentFieldName' => 'assureur'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $assureurId, $isParentNew, $collections);
        return $layout;
    }

    private function buildPisteLayout(int $pisteId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["client"]], ["champs" => ["risque"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["primePotentielle"]]]],
        ];

        $collections = [
            ['fieldName' => 'cotations', 'entityRouteName' => 'cotation', 'formTitle' => 'Cotation', 'parentFieldName' => 'piste'],
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'piste'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $pisteId, $isParentNew, $collections);
        return $layout;
    }

    private function buildCotationLayout(int $cotationId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["piste"]], ["champs" => ["assureur"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["duree"]]]],
        ];

        $collections = [
            ['fieldName' => 'avenants', 'entityRouteName' => 'avenant', 'formTitle' => 'Avenant', 'parentFieldName' => 'cotation'],
            ['fieldName' => 'taches', 'entityRouteName' => 'tache', 'formTitle' => 'Tâche', 'parentFieldName' => 'cotation'],
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'cotation'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $cotationId, $isParentNew, $collections);
        return $layout;
    }

    private function buildAvenantLayout(int $avenantId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["cotation"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["numero"]], ["champs" => ["referencePolice"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["startingAt"]], ["champs" => ["endingAt"]]]],
        ];

        $collections = [
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'avenant'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $avenantId, $isParentNew, $collections);
        return $layout;
    }

    private function buildPieceSinistreLayout(int $pieceId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["fourniPar"]], ["champs" => ["receivedAt"]], ["champs" => ["type"]]]],
        ];

        $collections = [
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'pieceSinistre'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $pieceId, $isParentNew, $collections);
        return $layout;
    }

    private function buildContactLayout(int $contactId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["email"]], ["champs" => ["telephone"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["fonction"]], ["champs" => ["type"]]]],
        ];

        return $layout;
    }

    private function buildOffreIndemnisationLayout(int $offreId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["beneficiaire"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["franchiseAppliquee"]], ["champs" => ["montantPayable"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["referenceBancaire"]]]],
        ];

        $collections = [
            ['fieldName' => 'taches', 'entityRouteName' => 'tache', 'formTitle' => 'Tâche', 'parentFieldName' => 'offreIndemnisationSinistre'],
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'offreIndemnisationSinistre'],
            ['fieldName' => 'paiements', 'entityRouteName' => 'paiement', 'formTitle' => 'Paiement', 'parentFieldName' => 'offreIndemnisationSinistre', 'defaultValueConfig' => ['source' => 'montantPayable', 'target' => 'montant']],
        ];

        $this->addCollectionWidgetsToLayout($layout, $offreId, $isParentNew, $collections);
        return $layout;
    }

    private function buildDocumentLayout(int $documentId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]], ["champs" => ["classeur"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["fichier"]]]],
        ];

        return $layout;
    }

    private function buildTacheLayout(int $tacheId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["toBeEndedAt"]], ["champs" => ["executor"]], ["champs" => ["closed"]]]],
        ];

        $collections = [
            ['fieldName' => 'feedbacks', 'entityRouteName' => 'feedback', 'formTitle' => 'Feedback', 'parentFieldName' => 'tache'],
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'tache'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $tacheId, $isParentNew, $collections);
        return $layout;
    }

    private function buildPaiementLayout(int $paiementId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["montant"]], ["champs" => ["reference"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["paidAt"]], ["champs" => ["CompteBancaire"]]]],
        ];

        $collections = [
            ['fieldName' => 'preuves', 'entityRouteName' => 'document', 'formTitle' => 'Preuve', 'parentFieldName' => 'paiement'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $paiementId, $isParentNew, $collections);
        return $layout;
    }

    private function buildFeedbackLayout(int $feedbackId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["hasNextAction"]], ["champs" => ["nextActionAt"]], ["champs" => ["type"]]]],
        ];

        // On ajoute toujours la ligne de collection.
        $layout[] = [
            "couleur_fond" => "white",
            "colonnes" => [
                ["champs" => ["nextAction"]],
                ["champs" => [$this->getCollectionWidgetConfig('documents', 'document', $feedbackId, "Document", 'feedback', null, $isParentNew)]]
            ]
        ];
        return $layout;
    }

    private function buildGroupeLayout(int $groupeId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
        ];
        $collections = [['fieldName' => 'clients', 'entityRouteName' => 'client', 'formTitle' => 'Client', 'parentFieldName' => 'groupe']];
        $this->addCollectionWidgetsToLayout($layout, $groupeId, $isParentNew, $collections);
        return $layout;
    }

    private function buildPartenaireLayout(int $partenaireId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["email"]], ["champs" => ["telephone"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["part"]]]],
        ];
        $collections = [
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'partenaire'],
            ['fieldName' => 'clients', 'entityRouteName' => 'client', 'formTitle' => 'Client', 'parentFieldName' => 'partenaires'],
        ];
        $this->addCollectionWidgetsToLayout($layout, $partenaireId, $isParentNew, $collections);
        return $layout;
    }

    private function buildRisqueLayout(int $risqueId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nomComplet"]], ["champs" => ["code"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["branche"]], ["champs" => ["imposable"]]]],
        ];
        $collections = [
            ['fieldName' => 'pistes', 'entityRouteName' => 'piste', 'formTitle' => 'Piste', 'parentFieldName' => 'risque'],
            ['fieldName' => 'notificationSinistres', 'entityRouteName' => 'notificationsinistre', 'formTitle' => 'Sinistre', 'parentFieldName' => 'risque'],
        ];
        $this->addCollectionWidgetsToLayout($layout, $risqueId, $isParentNew, $collections);
        return $layout;
    }

    private function buildBordereauLayout(int $bordereauId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]], ["champs" => ["assureur"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["montantTTC"]], ["champs" => ["receivedAt"]]]],
        ];
        $collections = [['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'bordereau']];
        $this->addCollectionWidgetsToLayout($layout, $bordereauId, $isParentNew, $collections);
        return $layout;
    }

    private function buildChargementLayout(int $chargementId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]], ["champs" => ["fonction"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
        ];
        return $layout;
    }

    private function buildAutoriteFiscaleLayout(int $autoriteId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]], ["champs" => ["abreviation"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
        ];
        return $layout;
    }

    private function buildCompteBancaireLayout(int $compteId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]], ["champs" => ["banque"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["intitule"]], ["champs" => ["numero"]], ["champs" => ["codeSwift"]]]],
        ];
        $collections = [
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'compteBancaire'],
            ['fieldName' => 'paiements', 'entityRouteName' => 'paiement', 'formTitle' => 'Paiement', 'parentFieldName' => 'compteBancaire'],
        ];
        $this->addCollectionWidgetsToLayout($layout, $compteId, $isParentNew, $collections);
        return $layout;
    }

    private function buildNoteLayout(int $noteId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]], ["champs" => ["reference"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["validated"]]]],
        ];
        $collections = [['fieldName' => 'paiements', 'entityRouteName' => 'paiement', 'formTitle' => 'Paiement', 'parentFieldName' => 'note']];
        $this->addCollectionWidgetsToLayout($layout, $noteId, $isParentNew, $collections);
        return $layout;
    }

    private function buildTaxeLayout(int $taxeId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["code"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["tauxIARD"]], ["champs" => ["tauxVIE"]]]],
        ];
        return $layout;
    }

    private function buildTrancheLayout(int $trancheId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]], ["champs" => ["cotation"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["montantFlat"]], ["champs" => ["pourcentage"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["payableAt"]]]],
        ];
        return $layout;
    }

    private function buildTypeRevenuLayout(int $typeRevenuId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["pourcentage"]], ["champs" => ["montantflat"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["shared"]]]],
        ];
        return $layout;
    }

    private function buildClasseurLayout(int $classeurId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
        ];
        $collections = [['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'classeur']];
        $this->addCollectionWidgetsToLayout($layout, $classeurId, $isParentNew, $collections);
        return $layout;
    }

    private function buildEntrepriseLayout(int $entrepriseId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["licence"]]]],
        ];
        return $layout;
    }

    private function buildInviteLayout(int $inviteId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]], ["champs" => ["email"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["isVerified"]]]],
        ];
        return $layout;
    }

    private function buildConditionPartageLayout(int $conditionId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]], ["champs" => ["partenaire"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["taux"]], ["champs" => ["seuil"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["formule"]], ["champs" => ["uniteMesure"]], ["champs" => ["critereRisque"]]]],
        ];
        $collections = [['fieldName' => 'produits', 'entityRouteName' => 'risque', 'formTitle' => 'Risque', 'parentFieldName' => 'conditionPartage']];
        $this->addCollectionWidgetsToLayout($layout, $conditionId, $isParentNew, $collections);
        return $layout;
    }

    private function buildRevenuPourCourtierLayout(int $revenuId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]], ["champs" => ["cotation"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["typeRevenu"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["montantFlatExceptionel"]], ["champs" => ["tauxExceptionel"]]]],
        ];
        return $layout;
    }

    private function addCollectionWidgetsToLayout(array &$layout, int $parentId, bool $isParentNew, array $collectionsConfig): void
    {
        foreach ($collectionsConfig as $config) {
            $layout[] = [
                "couleur_fond" => "white",
                "colonnes" => [
                    ["champs" => [$this->getCollectionWidgetConfig(
                        $config['fieldName'],
                        $config['entityRouteName'],
                        $parentId,
                        $config['formTitle'],
                        $config['parentFieldName'],
                        $config['defaultValueConfig'] ?? null,
                        $isParentNew
                    )]],
                ]
            ];
        }
    }

    /**
     * MODIFIÉ : Accepte maintenant le nom de la route de l'entité en paramètre.
     *
     * @param string $fieldName Le nom de l'attribut dans l'entité parente (ex: 'contacts', 'pieces').
     * @param string $entityRouteName Le nom utilisé dans la route pour cette entité (ex: 'contact', 'piecesinistre').
     * @param integer $parentId L'ID de l'entité parente.
     * @return array
     */
    private function getCollectionWidgetConfig(string $fieldName, string $entityRouteName, int $parentId, string $formtitle, string $parentFieldName, ?array $defaultValueConfig = null, bool $isParentNew = false): array
    {
        // L'ancienne logique de mappage est supprimée. On utilise directement le paramètre.
        $config = [
            "field_code" => $fieldName,
            "widget" => "collection",
            "options" => [
                "listUrl"       => "/admin/" . strtolower($parentFieldName) . "/api/" . $parentId . "/" . $fieldName,
                "itemFormUrl"   => "/admin/" . $entityRouteName . "/api/get-form",
                "itemSubmitUrl" => "/admin/" . $entityRouteName . "/api/submit",
                "itemDeleteUrl" => "/admin/" . $entityRouteName . "/api/delete",
                "itemTitleCreate" => "Ajouter : " . $formtitle,
                "itemTitleEdit" => "Modifier : " . $formtitle . " #%id%",
                "parentEntityId" => $parentId,
                "parentFieldName" => $parentFieldName,
                "disabled" => $isParentNew, // Indique si le widget doit être désactivé
                // L'URL est maintenant correcte, le JS l'utilisera
                "url" => "/admin/" . strtolower($parentFieldName) . "/api/" . $parentId . "/" . $fieldName,
            ]
        ];

        // Si une configuration de valeur par défaut est fournie, on l'ajoute aux options
        if ($defaultValueConfig) {
            $config['options']['defaultValueConfig'] = json_encode($defaultValueConfig);
        }

        return $config;
    }

    /**
     * NOUVEAU : Construit une carte "aplatie" des champs du formulaire pour un accès direct.
     *
     * @param array $formLayout La structure hiérarchique du layout.
     * @return array Une carte où les clés sont les 'field_code' et les valeurs sont la configuration du champ.
     */
    private function buildFieldsMap(array $formLayout): array
    {
        $fieldsMap = [];
        if (empty($formLayout)) {
            return $fieldsMap;
        }

        foreach ($formLayout as $row) {
            if (!isset($row['colonnes']) || !is_array($row['colonnes'])) continue;

            foreach ($row['colonnes'] as $col) {
                // La colonne peut contenir directement un champ ou un tableau de champs
                $fields = $col['champs'] ?? (is_array($col) ? [$col] : []);

                foreach ($fields as $field) {
                    if (is_array($field) && isset($field['field_code'])) {
                        $fieldsMap[$field['field_code']] = $field;
                    }
                }
            }
        }
        return $fieldsMap;
    }
}