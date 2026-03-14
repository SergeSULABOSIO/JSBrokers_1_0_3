<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Article;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fournisseur de Canvas pour l'entité Article.
 * Définit la structure du formulaire dynamique pour l'affichage step-by-step.
 */
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

    /**
     * Génère la configuration du Canvas pour le formulaire d'article.
     */
    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Article $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Ajouter un élément de facturation",
            "titre_modification" => "Modifier la ligne #%id%",
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

    /**
     * Construit la grille (layout) du formulaire Article.
     * Note: Le champ 'nom' a été supprimé.
     */
    private function buildArticleLayout(Article $object, bool $isParentNew): array
    {
        return [
            // Ligne 1 : Revenu (Le déclencheur de la cascade)
            [
                "colonnes" => [
                    ["champs" => ["revenuFacture"], "width" => 12]
                ]
            ],
            // Ligne 2 : Tranche (Masquée initialement par ArticleType)
            [
                "colonnes" => [
                    ["champs" => ["tranche"], "width" => 12]
                ]
            ],
            // Ligne 3 : Quantité et Montant TTC côte à côte (50% / 50%)
            [
                "colonnes" => [
                    ["champs" => ["quantite"], "width" => 6],
                    ["champs" => ["montant"], "width" => 6]
                ]
            ],
            // Ligne 4 : Taxe (Masquée initialement)
            [
                "colonnes" => [
                    ["champs" => ["taxeFacturee"], "width" => 12]
                ]
            ],
        ];
    }
}