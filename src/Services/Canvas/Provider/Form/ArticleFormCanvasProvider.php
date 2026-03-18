<?php

declare(strict_types=1);

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Article;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Provider de layout pour l'Article (Version sans Nom ni Taxe)
 */
class ArticleFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private readonly CanvasBuilder $canvasBuilder,
        private readonly EntityManagerInterface $em
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
            "titre_creation" => "Ajouter une ligne de facturation",
            "titre_modification" => "Modifier l'article #%id%",
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
        return [
            // Ligne 1 : Revenu (Le point d'entrée)
            [
                    ["champs" => ["revenuFacture"], "width" => 12]
                ]
            ],
            // Ligne 2 : Tranche (Dépend du revenu)
            [
                "colonnes" => [
                    ["champs" => ["tranche"], "width" => 12]
                ]
            ],
            // Ligne 3 : Quantité et Montant Total (Côte à côte 50/50)
            [
    }
}