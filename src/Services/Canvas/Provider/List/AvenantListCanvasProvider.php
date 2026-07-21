<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Avenant;
use App\Services\Search\AvenantEcheanceScope;
use App\Services\ServiceMonnaies;

class AvenantListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Avenant::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Avenants",
                "texte_principal" => [
                    "attribut_code" => "titrePrincipal",
                    "icone" => "mdi:file-document-edit",
                ],
                // Badge d'urgence d'échéance rendu par _list_row à côté du texte principal,
                // coloré par niveau (critique/élevée/modérée/faible). Texte vide = pas de badge.
                "badges" => [
                    ["attribut_code" => "urgenceEcheance", "attribut_niveau" => "urgenceEcheanceNiveau"],
                ],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Avt n°", "attribut_code" => "numero"],
                    ["attribut_code" => "risqueCode"],
                    ["attribut_code" => "periodeCouverture"],
                    // Présence d'une piste dérivée : toujours renseigné (« Aucune piste
                    // dérivée » à défaut), sur le modèle du portefeuille des invités.
                    ["attribut_code" => "pisteDeriveeLibelle", "icone" => "piste"],
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
            // pose/retire le critère synthétique « Échéance » via le Cerveau. Le moteur filtre
            // et trie par urgence (endingAt croissant) en SQL. `icon` = alias IconCanvasProvider.
            "filtres_predefinis" => [
                [
                    "critere" => AvenantEcheanceScope::CRITERION_KEY,
                    "libelle" => "Échéance",
                    "options" => [
                        ["value" => AvenantEcheanceScope::STATUT_ECHUS, "label" => AvenantEcheanceScope::libelle(AvenantEcheanceScope::STATUT_ECHUS), "icon" => "action:alert"],
                        ["value" => AvenantEcheanceScope::STATUT_30J, "label" => AvenantEcheanceScope::libelle(AvenantEcheanceScope::STATUT_30J), "icon" => "action:calendar"],
                        ["value" => AvenantEcheanceScope::STATUT_31_60J, "label" => AvenantEcheanceScope::libelle(AvenantEcheanceScope::STATUT_31_60J), "icon" => "action:renew"],
                        ["value" => AvenantEcheanceScope::STATUT_60_PLUS, "label" => AvenantEcheanceScope::libelle(AvenantEcheanceScope::STATUT_60_PLUS), "icon" => "avenant"],
                        ["value" => "", "label" => "Toutes", "icon" => "action:filter"],
                    ],
                ],
            ],
        ];
    }
}