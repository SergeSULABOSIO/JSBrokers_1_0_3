<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\ConditionPartage;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class ConditionPartageFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ConditionPartage::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var ConditionPartage $object */
        $isParentNew = ($object->getId() === null);
        $conditionId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouvelle Condition de Partage",
            "titre_modification" => "Modification de la Condition #%id%",
            "endpoint_submit_url" => "/admin/conditionpartage/api/submit",
            "endpoint_delete_url" => "/admin/conditionpartage/api/delete",
            "endpoint_form_url" => "/admin/conditionpartage/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildConditionPartageLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildConditionPartageLayout(ConditionPartage $object, bool $isParentNew): array
    {
        $conditionId = $object->getId() ?? 0;
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["taux"]], ["champs" => ["seuil"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["uniteMesure"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["formule"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["critereRisque"]]]],
        ];
        $collections = [['fieldName' => 'produits', 'entityRouteName' => 'risque', 'formTitle' => 'Risque', 'parentFieldName' => 'conditionPartage']];
        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);
        return $layout;
    }
}
