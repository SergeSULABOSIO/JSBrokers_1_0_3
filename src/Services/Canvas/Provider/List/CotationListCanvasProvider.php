<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Cotation;
use App\Services\Canvas\Provider\List\ListCanvasProviderInterface;
use App\Services\Search\CotationSouscriptionScope;
use App\Services\ServiceMonnaies;

class CotationListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Cotation::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Cotations",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "cotation"],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    // LE VOYANT DE L'EFFORT COMMERCIAL, en tête : c'est ce qui distingue un
                    // compte apporté par un agent interne d'un compte gagné par le cabinet
                    // seul — lequel reste MUET, étant le cas normal.
                    //
                    // ⚠ Code PLAT obligatoire : le rendu d'une ligne fait
                    // `attribute(entity, code)`, où un chemin pointé (« agent.nom ») est lu
                    // comme un nom de propriété et fait tomber la rubrique ENTIÈRE.
                    ["attribut_code" => "effortCommercialAgent", "icone" => "invite"],
                    ["attribut_code" => "assureur"],
                    ["attribut_prefixe" => "Piste: ", "attribut_code" => "piste"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Prime Totale",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "primeTotale",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Comm. Totale",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantTTC",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Rétro-comm.",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "retroCommission",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Réserve",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "reserve",
                    "attribut_type" => "nombre",
                ],
            ],
            // Chips de filtre rapide rendus par _List_manager (hors dialogues) : chaque option
            // pose/retire le critère synthétique « Statut de souscription » via le Cerveau. Le
            // moteur filtre en SQL (EXISTS / NOT EXISTS sur les avenants). Même clé que le badge
            // de la barre de recherche → les deux restent synchronisés. `icon` = alias
            // IconCanvasProvider, résolu par resolve_icon_name().
            "filtres_predefinis" => [
                [
                    "critere" => CotationSouscriptionScope::CRITERION_KEY,
                    "libelle" => "Statut",
                    "options" => [
                        ["value" => CotationSouscriptionScope::STATUT_SOUSCRITES, "label" => CotationSouscriptionScope::libelle(CotationSouscriptionScope::STATUT_SOUSCRITES), "icon" => "action:completed"],
                        ["value" => CotationSouscriptionScope::STATUT_EN_ATTENTE, "label" => CotationSouscriptionScope::libelle(CotationSouscriptionScope::STATUT_EN_ATTENTE), "icon" => "action:ongoing"],
                        ["value" => CotationSouscriptionScope::STATUT_CADUQUES, "label" => CotationSouscriptionScope::libelle(CotationSouscriptionScope::STATUT_CADUQUES), "icon" => "action:cancel"],
                        ["value" => "", "label" => "Toutes", "icon" => "action:filter"],
                    ],
                ],
            ],
        ];
    }
}