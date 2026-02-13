<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\NotificationSinistre;

class NotificationSinistreFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === NotificationSinistre::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var NotificationSinistre $object */
        $isParentNew = ($object->getId() === null);
        $notificationId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouvelle Notification de Sinistre",
            "titre_modification" => "Modification de la Notification #%id%",
            "endpoint_submit_url" => "/admin/notificationsinistre/api/submit",
            "endpoint_delete_url" => "/admin/notificationsinistre/api/delete",
            "endpoint_form_url" => "/admin/notificationsinistre/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildNotificationSinistreLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildNotificationSinistreLayout(NotificationSinistre $object, bool $isParentNew): array
    {
        $notificationId = $object->getId() ?? 0;
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["assure"]], ["champs" => ["assureur"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["risque"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["referencePolice"]], ["champs" => ["referenceSinistre"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["descriptionDeFait"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["occuredAt"]], ["champs" => ["notifiedAt"]], ["champs" => ["lieu"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["descriptionVictimes"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["dommage"]], ["champs" => ["evaluationChiffree"]]]]
        ];

        $collections = [
            ['fieldName' => 'contacts', 'entityRouteName' => 'contact', 'formTitle' => 'Contact', 'parentFieldName' => 'notificationSinistre'],
            ['fieldName' => 'pieces', 'entityRouteName' => 'piecesinistre', 'formTitle' => 'Pièce Sinistre', 'parentFieldName' => 'notificationSinistre'],
            ['fieldName' => 'offreIndemnisationSinistres', 'entityRouteName' => 'offreindemnisationsinistre', 'formTitle' => "Offre d'indemnisation", 'parentFieldName' => 'notificationSinistre'],
            ['fieldName' => 'taches', 'entityRouteName' => 'tache', 'formTitle' => 'Tâche', 'parentFieldName' => 'notificationSinistre'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);

        return $layout;
    }
}