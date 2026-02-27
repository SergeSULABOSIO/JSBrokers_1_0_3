<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Feedback;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class FeedbackFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

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
        $layout = $this->buildFeedbackLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildFeedbackLayout(Feedback $object, bool $isParentNew): array
    {
        $feedbackId = $object->getId() ?? 0;
        $layout = [
            // Ligne 1: Description
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
            
            // Ligne 2: Prochaine action (1/2) et Moyen de contact (1/2)
            ["couleur_fond" => "white", "colonnes" => [
                ["width" => 6, "champs" => ["hasNextAction"]],
                ["width" => 6, "champs" => ["type"]]
            ]],
            
            // Ligne 3: Documents
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => [$this->getCollectionWidgetConfig('documents', 'document', $feedbackId, "Document", 'feedback', null, $isParentNew)]]
            ]],

            // Ligne 4: Date de la prochaine action
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["nextActionAt"]]
            ]],

            // Ligne 5: Prochaine action (Détail)
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["nextAction"]]
            ]],
        ];
        return $layout;
    }
}