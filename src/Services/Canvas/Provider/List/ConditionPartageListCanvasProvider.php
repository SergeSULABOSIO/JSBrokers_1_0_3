<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\ConditionPartage;
use App\Services\ServiceMonnaies;

class ConditionPartageListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ConditionPartage::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Conditions de Partage",
                "texte_principal" => [
                    "attribut_code" => "nom", 
                    "icone" => "mdi:share-variant"
                    ],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    // QUI TOUCHE, avant COMBIEN. Une liste de conditions dont on ne lit
                    // pas le bénéficiaire oblige à ouvrir chaque fiche pour savoir si la
                    // ligne concerne un partenaire externe ou un salarié du cabinet.
                    ["attribut_code" => "beneficiaireNom", "icone" => "lucide:user-round"],
                    ["attribut_code" => "descriptionRegle"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Assiette",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "totalAssiette",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Rétro-comm.",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "totalRetroCommission",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Dossiers",
                    "attribut_code" => "nombreDossiersConcernes",
                    "attribut_type" => "nombre",
                    "attribut_unité" => "",
                ],
            ],
        ];
    }
}