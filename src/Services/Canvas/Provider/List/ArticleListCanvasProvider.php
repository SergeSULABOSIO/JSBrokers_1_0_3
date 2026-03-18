<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Article;
use App\Services\ServiceMonnaies;

class ArticleListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Article::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Articles",
                "texte_principal" => ["attribut_code" => "elementLie", "icone" => "default"], // Utilisation de 'elementLie' comme texte principal
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Nature: ", "attribut_code" => "natureArticle"],
                    ["attribut_prefixe" => "Lié à: ", "attribut_code" => "elementLie"],
                    ["attribut_prefixe" => "Note: ", "attribut_code" => "statutNoteParent"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Montant",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantArticle",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Poids",
                    "attribut_unité" => "%",
                    "attribut_code" => "pourcentageNote",
                    "attribut_type" => "pourcentage",
                ],
            ],
        ];
    }
}