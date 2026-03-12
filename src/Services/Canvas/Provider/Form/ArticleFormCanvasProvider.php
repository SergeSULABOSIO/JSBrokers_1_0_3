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
        // On définit la condition : "Visible SI le champ revenuFacture n'est pas vide"
        $conditionRevenu = [
            'visibility_conditions' => [
                [
                    'field' => 'revenuFacture',
                    'operator' => '!=',
                    'value' => '' // Le champ Autocomplete renvoie une chaîne vide quand rien n'est sélectionné
                ]
            ]
        ];

        $layout = [
            // Ligne 1: Nom et description (Toujours visible)
            [
                "colonnes" => [
                    ["champs" => ["nom"], "width" => 12]
                ]
            ],
            // Ligne 2: Revenu / Commission liée (Le champ "maître", toujours visible)
            [
                "colonnes" => [
                    ["champs" => ["revenuFacture"], "width" => 12]
                ]
            ],
            // Ligne 3: Tranche (Prime liée) - Conditionnel
            [
                "colonnes" => [
                    ["champs" => [array_merge(['field_code' => 'tranche'], $conditionRevenu)], "width" => 12]
                ]
            ],
            // Ligne 4: Taxe liée - Conditionnel
            [
                "colonnes" => [
                    ["champs" => [array_merge(['field_code' => 'taxeFacturee'], $conditionRevenu)], "width" => 12]
                ]
            ],
            // Ligne 5: Montant - Conditionnel
            [
                "colonnes" => [
                    ["champs" => [array_merge(['field_code' => 'montant'], $conditionRevenu)], "width" => 12]
                ]
            ]
        ];

        return $layout;
    }
}