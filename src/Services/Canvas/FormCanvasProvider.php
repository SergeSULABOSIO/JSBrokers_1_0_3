<?php

namespace App\Services\Canvas;

use App\Entity\Avenant;
use App\Entity\Bordereau;
use App\Entity\Chargement;
use App\Entity\Classeur;
use App\Entity\AutoriteFiscale;
use App\Entity\CompteBancaire;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Groupe;
use App\Entity\Invite;
use App\Entity\ModelePieceSinistre;
use App\Entity\Paiement;
use App\Entity\Partenaire;
use App\Entity\Risque;
use App\Entity\TypeRevenu;
use App\Services\Canvas\Provider\Form\FormCanvasProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

class FormCanvasProvider
{
    /**
     * @var FormCanvasProviderInterface[]
     */
    private iterable $providers;

    public function __construct(
        #[TaggedIterator('app.form_canvas_provider')] iterable $providers
    ) {
        $this->providers = $providers;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        $entityClassName = get_class($object);

        foreach ($this->providers as $provider) {
            if ($provider->supports($entityClassName)) {
                return $provider->getCanvas($object, $idEntreprise);
            }
        }

        $isParentNew = ($object->getId() === null);
        $layout = [];
        $parametres = [];

        switch ($entityClassName) {
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

    private function buildDocumentLayout(int $documentId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]], ["champs" => ["classeur"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["fichier"]]]],
        ];

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

}