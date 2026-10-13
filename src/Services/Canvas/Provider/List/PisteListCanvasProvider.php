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
                // Pas de clé `icone` ici : la ligne prend l'icône du canevas d'ENTITÉ (une
                // empreinte de pas). Celle qu'on déclarait (« mdi:road-variant ») n'était lue
                // par personne — une configuration qui ment sur ce qu'on voit à l'écran.
                "texte_principal" => ["attribut_code" => "nom"],
                // L'ÉTAPE DU PIPE, en marqueur coloré à côté du nom : le MÊME libellé que le
                // chip et que la colonne de la vue Cartes, la MÊME couleur (`--etape-*`).
                // Elle remplace « Transformée (Souscrite) » / « En cours » de la sous-ligne,
                // qui ne parlait pas la langue du chip (« Transformées »).
                "badges" => [
                    ["attribut_code" => "etapePipeLibelle", "attribut_niveau" => "etapePipeNiveau"],
                ],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    // LE VOYANT DE L'EFFORT COMMERCIAL, en tête : c'est ce qui distingue un
                    // compte apporté par un agent interne d'un compte gagné par le cabinet
                    // seul — lequel reste MUET, étant le cas normal.
                    //
                    // ⚠ Code PLAT obligatoire : le rendu d'une ligne fait
                    // `attribute(entity, code)`, où un chemin pointé (« agent.nom ») est lu
                    // comme un nom de propriété et fait tomber la rubrique ENTIÈRE.
                    ["attribut_code" => "partageLibelle", "icone" => "partenaire"],
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
            // pose/retire le critère synthétique du pipe via le Cerveau ; le moteur filtre en
            // SQL (JSBDynamicSearchService::predicatsDEtapePiste). Même clé que le badge de la
            // barre de recherche → les deux restent synchronisés.
            //
            // LES ÉTAPES DANS L'ORDRE DU PIPE, puis « En cours » qui réunit les trois
            // premières, puis « Toutes ». Libellés et icônes viennent de
            // PisteTransformationScope : le chip, la ligne et la colonne ne peuvent pas diverger.
            "filtres_predefinis" => [
                [
                    "critere" => PisteTransformationScope::CRITERION_KEY,
                    "libelle" => "Statut",
                    "options" => [
                        ...$this->optionsDesEtapes(),
                        ["value" => PisteTransformationScope::STATUT_EN_COURS, "label" => PisteTransformationScope::libelle(PisteTransformationScope::STATUT_EN_COURS), "icon" => "action:ongoing"],
                        ["value" => "", "label" => "Toutes", "icon" => "action:filter"],
                    ],
                ],
            ],
        ];
    }

    /**
     * Une option de chip par étape du pipe, dans l'ordre.
     *
     * @return list<array{value: string, label: string, icon: string}>
     */
    private function optionsDesEtapes(): array
    {
        $options = [];
        foreach (PisteTransformationScope::ETAPES as $code => $etape) {
            $options[] = [
                "value" => $code,
                "label" => PisteTransformationScope::libelle($code),
                "icon" => $etape['icone'],
            ];
        }

        return $options;
    }
}