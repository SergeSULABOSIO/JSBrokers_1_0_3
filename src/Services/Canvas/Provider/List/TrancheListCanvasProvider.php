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
            // Chips de filtre rapide rendus par _List_manager (hors dialogues) : chaque option
            // pose/retire le critère synthétique « Statut de paiement » via le Cerveau.
            "filtres_predefinis" => [
                [
                    "critere" => TranchePaiementScope::CRITERION_KEY,
                    "libelle" => "Statut de paiement",
                    "options" => [
                        ["value" => TranchePaiementScope::STATUT_IMPAYEES, "label" => TranchePaiementScope::libelle(TranchePaiementScope::STATUT_IMPAYEES)],
                        ["value" => TranchePaiementScope::STATUT_ECHUES, "label" => TranchePaiementScope::libelle(TranchePaiementScope::STATUT_ECHUES)],
                        ["value" => TranchePaiementScope::STATUT_COMMISSION_EXIGIBLE, "label" => TranchePaiementScope::libelle(TranchePaiementScope::STATUT_COMMISSION_EXIGIBLE)],
                        ["value" => TranchePaiementScope::STATUT_PAYEES, "label" => TranchePaiementScope::libelle(TranchePaiementScope::STATUT_PAYEES)],
                        ["value" => TranchePaiementScope::STATUT_RETRO_A_PAYER, "label" => "Rétro à payer"],
                        ["value" => "", "label" => "Toutes"],
                    ],
                ],
            ],
        ];
    }
}