<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Feedback;

class FeedbackFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Feedback::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Feedback $object */
        $isParentNew = ($object->getId() === null);
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

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
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
}