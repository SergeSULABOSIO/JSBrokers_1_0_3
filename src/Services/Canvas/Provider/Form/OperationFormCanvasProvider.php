<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Operation;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class OperationFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Operation::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Operation $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouvelle Opération",
            "titre_modification" => "Modification de l'Opération #%id%",
            "endpoint_submit_url" => "/admin/operation/api/submit",
            "endpoint_delete_url" => "/admin/operation/api/delete",
            "endpoint_form_url" => "/admin/operation/api/get-form",
            "isCreationMode" => $isParentNew,
            // Pas d'actions spécifiques pour les opérations pour l'instant
            "attribute_actions" => []
        ];
        $layout = $this->buildOperationLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout),
        ];
    }

    private function buildOperationLayout(Operation $object, bool $isParentNew): array
    {
        $layout = [
            ["colonnes" => [
                ["champs" => ["referencePolice"], "width" => 8],
                ["champs" => ["numeroAvenant"], "width" => 4]
            ]],
            ["colonnes" => [["champs" => ["montantHT"], "width" => 6], ["champs" => ["montantTaxe"], "width" => 6]]],
        ];

        return $layout;
    }
}