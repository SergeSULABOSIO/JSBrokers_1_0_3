<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Groupe;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class GroupeFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Groupe::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Groupe $object */
        $isParentNew = ($object->getId() === null);
        $groupeId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouveau Groupe",
            "titre_modification" => "Modification du Groupe #%id%",
            "endpoint_submit_url" => "/admin/groupe/api/submit",
            "endpoint_delete_url" => "/admin/groupe/api/delete",
            "endpoint_form_url" => "/admin/groupe/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildGroupeLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildGroupeLayout(Groupe $object, bool $isParentNew): array
    {
        $groupeId = $object->getId() ?? 0;
        $layout = [
            [
                "couleur_fond" => "white",
                "colonnes" => [
                    ["width" => 12, "champs" => ["nom"]],
                ]
            ],
            [
                "couleur_fond" => "white",
                "colonnes" => [
                    ["width" => 12, "champs" => ["description"]],
                ]
            ],
        ];
        return $layout;
    }
}
