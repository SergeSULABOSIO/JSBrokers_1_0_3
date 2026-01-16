<?php

namespace App\Services\Canvas;

use App\Entity\Bordereau;
use App\Entity\Chargement;
use App\Entity\Classeur;
use App\Entity\AutoriteFiscale;
use App\Entity\Document;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Paiement;
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

}