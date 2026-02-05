<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Document;

class DocumentListCanvasProvider implements ListCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Document::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Documents",
                "texte_principal" => [
                    "attribut_prefixe" => "",
                    "attribut_code" => "nom",
                    "attribut_type" => "text",
                    "attribut_taille_max" => 50,
                    "icone" => "document",
                    "icone_taille" => "19px",
                ],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    [
                        "attribut_prefixe" => "Créé le: ",
                        "attribut_code" => "createdAt",
                        "attribut_type" => "date",
                    ],
                ],
            ],
        ];
    }
}