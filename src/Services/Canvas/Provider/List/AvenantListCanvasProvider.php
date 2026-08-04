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
                // Badges rendus par _list_row à côté du texte principal, colorés par niveau
                // (critique/élevée/modérée/faible). Texte vide = badge non rendu.
                //
                // Le second badge est le SEUL endroit où une police signalée non renouvelable
                // reste repérable d'un coup d'œil : le pipeline d'échéance l'ayant écartée,
                // elle ne se retrouve plus que par le chip « Toutes ». Les deux ne coexistent
                // jamais — une police marquée n'est pas en retard, son badge d'urgence s'efface
                // (AvenantIndicatorStrategy::getUrgenceEcheance).
                "badges" => [
                    ["attribut_code" => "urgenceEcheance", "attribut_niveau" => "urgenceEcheanceNiveau"],
                    ["attribut_code" => "nonRenouvelableBadge", "attribut_niveau" => "nonRenouvelableNiveau"],
                ],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Avt n°", "attribut_code" => "numero"],
                    ["attribut_code" => "risqueCode"],
                    ["attribut_code" => "periodeCouverture"],
                    // Présence d'une piste dérivée : toujours renseigné (« Aucune piste
                    // dérivée » à défaut), sur le modèle du portefeuille des invités.
                    ["attribut_code" => "pisteDeriveeLibelle", "icone" => "piste"],
                    // Le MOTIF, lisible sans ouvrir la fiche : c'est la note écrite pour celui
                    // qui rouvrira le dossier. Null quand la police n'est pas marquée → l'item
                    // n'est pas rendu.
                    ["attribut_code" => "nonRenouvelableDetail", "icone" => "action:no-renew"],
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
                        // Le groupe des DÉCISIONS, et non une fenêtre de dates : il rassemble ce
                        // que les quatre autres écartent. Sans lui, une police signalée non
                        // renouvelable n'était plus retrouvable que par « Toutes », noyée — alors
                        // que le marquage peut être posé des mois avant l'échéance et que leur
                        // nombre ne fait que croître.
                        ["value" => AvenantEcheanceScope::STATUT_NON_RENOUVELABLES, "label" => AvenantEcheanceScope::libelle(AvenantEcheanceScope::STATUT_NON_RENOUVELABLES), "icon" => "action:no-renew"],
                        ["value" => "", "label" => "Toutes", "icon" => "action:filter"],
                    ],
                ],
            ],
        ];
    }
}