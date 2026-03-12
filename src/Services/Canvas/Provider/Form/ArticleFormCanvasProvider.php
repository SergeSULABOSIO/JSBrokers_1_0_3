<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Article;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class ArticleFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Article::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Article $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouvel Article",
            "titre_modification" => "Modification de l'Article #%id%",
            "endpoint_submit_url" => "/admin/article/api/submit",
            "endpoint_delete_url" => "/admin/article/api/delete",
            "endpoint_form_url" => "/admin/article/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        
        $layout = $this->buildArticleLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildArticleLayout(Article $object, bool $isParentNew): array
    {
        $layout = [
            // Ligne 1: Nom et Montant
            [
                "colonnes" => [
                    ["champs" => ["nom"], "width" => 8],
                    ["champs" => ["montant"], "width" => 4]
                ]
            ],
            // Ligne 2: Les 3 liaisons possibles côte à côte
            [
                "colonnes" => [
                    ["champs" => ["tranche"], "width" => 4],
                    ["champs" => ["revenuFacture"], "width" => 4],
                    ["champs" => ["taxeFacturee"], "width" => 4]
                ]
            ]
        ];

        return $layout;
    }
}