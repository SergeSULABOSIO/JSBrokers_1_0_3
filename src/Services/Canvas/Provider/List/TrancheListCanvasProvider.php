<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Tranche;
use App\Services\Search\TranchePaiementScope;
use App\Services\ServiceMonnaies;

class TrancheListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Tranche::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Tranches",
                "texte_principal" => ["attribut_code" => "nomCompletAvecStatut", "icone" => "tranche"],
                // Badges d'état rendus par _list_row à côté du texte principal :
                // 1) urgence du recouvrement (prime/commission à collecter), colorée par niveau ;
                // 2) rétro partenaire à payer (solde dû ET commission partageable encaissée).
                "badges" => [
                    ["attribut_code" => "urgenceRecouvrement", "attribut_niveau" => "urgenceNiveau"],
                    ["attribut_code" => "commissionExigibleAffiche", "niveau_fixe" => "exigible"],
                    ["attribut_code" => "retroAPayerAffiche", "niveau_fixe" => "retro"],
                ],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Échéance: ", "attribut_code" => "echeanceAt", "attribut_type" => "date"],
                    ["attribut_prefixe" => "Statut : ", "attribut_code" => "statutPaiement"],
                    ["attribut_prefixe" => "Retard : ", "attribut_code" => "retardPaiement"],
                    ["attribut_code" => "cotationNom"],
                    ["attribut_code" => "clientNom"],
                    ["attribut_code" => "taxeCourtierAffichee"],
                    ["attribut_code" => "taxeAssureurAffichee"],
                    ["attribut_code" => "commissionTTCAffichee"],
                    ["attribut_code" => "retroCommissionAffichee"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Prime Tranche",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "primeTranche",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Reste prime",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "resteAPayer",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Reste commission",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "solde_restant_du",
                    "attribut_type" => "nombre",
                ],
                ["titre_colonne" => "Pourcentage", "attribut_unité" => "%", "attribut_code" => "pourcentageAffiche", "attribut_type" => "nombre"],
            ],
            // Chips de filtre rapide rendus par _List_manager (hors dialogues) : UN GROUPE PAR
            // AXE, chaque option posant/retirant sa propre clé de critère via le Cerveau. Les
            // groupes sont COMPLÉMENTAIRES : ils se cumulent en ET (« Prime impayée » +
            // « Échues » = les primes en retard). Un groupe unique mélangeait auparavant trois
            // dettes de débiteurs différents sous le mot « Impayées » — ambiguïté à l'origine
            // de l'incident du 2026-08-05, désormais inexprimable.
            // Dérivés de TranchePaiementScope::AXES : aucun libellé n'est recopié ici.
            // `icon` (optionnel) = alias IconCanvasProvider, résolu par resolve_icon_name().
            "filtres_predefinis" => $this->groupesDeChips(),
        ];
    }

    /**
     * @return array<int, array{critere: string, libelle: string, options: array<int, array{value: string, label: string, icon: string}>}>
     */
    private function groupesDeChips(): array
    {
        $groupes = [];
        foreach (TranchePaiementScope::AXES as $cle => $axe) {
            $options = [];
            foreach (array_keys($axe['valeurs']) as $valeur) {
                // Libellé COURT : le critère est écrit une seule fois, dans le titre du
                // groupe. Le répéter sur chacun des trois boutons triplait leur largeur,
                // au point qu'on ne pouvait pas aligner plus de deux groupes sur une ligne.
                // L'icône, elle, reste sur CHAQUE chip — comme dans toutes les autres
                // rubriques — et porte l'ÉTAT (soldé / entamé / dû), le critère étant déjà
                // dit par le titre.
                $options[] = [
                    "value" => $valeur,
                    "label" => TranchePaiementScope::libelleCourt($cle, $valeur),
                    "icon" => $axe['icones'][$valeur],
                    // Le titre du groupe ne suit pas le bouton dans les lecteurs d'écran :
                    // chaque chip garde donc le libellé complet en infobulle accessible.
                    "titre_complet" => TranchePaiementScope::libelle($cle, $valeur),
                ];
            }
            // L'option vide retire le critère de CE groupe seulement : les autres axes
            // posés restent actifs (cf. list-manager_controller#applyPresetFilter).
            $options[] = [
                "value" => "",
                "label" => "Toutes",
                "icon" => "action:filter",
                "titre_complet" => $axe['libelle'] . ' : toutes',
            ];

            $groupes[] = [
                "critere" => $cle,
                "libelle" => $axe['libelle'],
                "titre" => $axe['titre'],
                "options" => $options,
            ];
        }

        return $groupes;
    }
}