<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Piste;
use App\Services\Search\PisteTransformationScope;
use App\Services\ServiceMonnaies;

class PisteListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Piste::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Pistes",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "mdi:road-variant"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_code" => "statutTransformation"],
                    ["attribut_code" => "client"],
                    ["attribut_code" => "risqueCode"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Prime Potentielle",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "primePotentielle",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Comm. Potentielle",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "commissionPotentielle",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Prime Souscrite",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "primeTotale",
                    "attribut_type" => "calcul", // Changé pour refléter que c'est un calcul
                ],
                [
                    "titre_colonne" => "Comm. Souscrite",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantTTC",
                    "attribut_type" => "calcul", // Changé pour refléter que c'est un calcul
                ],
            ],
            // Chips de filtre rapide rendus par _List_manager (hors dialogues) : chaque option
            // pose/retire le critère synthétique « Statut de transformation » via le Cerveau. Le
            // moteur filtre en SQL (EXISTS / NOT EXISTS sur les avenants des cotations de la
            // piste). Même clé que le badge de la barre de recherche → les deux restent
            // synchronisés. `icon` = alias IconCanvasProvider, résolu par resolve_icon_name().
            "filtres_predefinis" => [
                [
                    "critere" => PisteTransformationScope::CRITERION_KEY,
                    "libelle" => "Statut",
                    "options" => [
                        ["value" => PisteTransformationScope::STATUT_TRANSFORMEES, "label" => PisteTransformationScope::libelle(PisteTransformationScope::STATUT_TRANSFORMEES), "icon" => "action:completed"],
                        ["value" => PisteTransformationScope::STATUT_EN_COURS, "label" => PisteTransformationScope::libelle(PisteTransformationScope::STATUT_EN_COURS), "icon" => "action:ongoing"],
                        ["value" => "", "label" => "Toutes", "icon" => "action:filter"],
                    ],
                ],
            ],
        ];
    }
}