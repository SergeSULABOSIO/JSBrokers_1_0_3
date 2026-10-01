<?php

namespace App\Services\Canvas\Provider\List;

use App\Entity\Note;
use App\Services\Search\NoteReglementScope;
use App\Services\ServiceMonnaies;

class NoteListCanvasProvider implements ListCanvasProviderInterface
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Note::class;
    }

    public function getCanvas(): array
    {
        return [
            "colonne_principale" => [
                "titre_colonne" => "Notes",
                "texte_principal" => ["attribut_code" => "nom", "icone" => "note"],
                // ── D'OÙ VIENT CETTE NOTE ───────────────────────────────────────────
                // Rien ne distinguait une note issue d'un bordereau de production d'une
                // note composée à la main : mêmes montants, même statut, même ligne. Or
                // les deux ne se corrigent pas de la même façon — l'une se reprend dans
                // son bordereau, l'autre ligne à ligne.
                //
                // NIVEAU NEUTRE, délibérément. Les niveaux existants disent tous une
                // urgence ou une action (critique, exigible, rétro à payer) ; une
                // provenance n'en est pas une. Le badge ne paraît que sur les notes
                // concernées : une note ordinaire reste muette plutôt que marquée.
                "badges" => [
                    ["attribut_code" => "bordereauAffiche"],
                ],
                "textes_secondaires_separateurs" => " • ",
                "textes_secondaires" => [
                    ["attribut_prefixe" => "Réf: ", "attribut_code" => "reference"],
                    // Savoir qu'une note vient d'un bordereau ne dit pas DUQUEL : la
                    // référence permet d'aller le retrouver sans ouvrir la note.
                    ["attribut_prefixe" => "Bordereau: ", "attribut_code" => "bordereauReference", "icone" => "bordereau"],
                    ["attribut_prefixe" => "Dest.: ", "attribut_code" => "addressedToString"],
                    ["attribut_prefixe" => "Statut: ", "attribut_code" => "statutPaiement"],
                ],
            ],
            "colonnes_numeriques" => [
                [
                    "titre_colonne" => "Montant Total",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantTotal",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Montant Payé",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "montantPaye",
                    "attribut_type" => "nombre",
                ],
                [
                    "titre_colonne" => "Solde",
                    "attribut_unité" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
                    "attribut_code" => "solde",
                    "attribut_type" => "nombre",
                ],
            ],
            // Chips de filtre rapide, rendus par `_list_manager` hors dialogue. UN SEUL
            // groupe : une note n'a qu'une dette, la sienne, envers son seul destinataire.
            // Les quatre axes de Tranche répondent à quatre dettes de débiteurs différents —
            // les imiter ici inventerait des distinctions qui n'existent pas.
            //
            // Dérivés de NoteReglementScope : aucun libellé ni icône n'est recopié.
            "filtres_predefinis" => [$this->groupeDeChips()],
        ];
    }

    /**
     * Le groupe de chips, construit depuis la source unique des états.
     *
     * ⚠ `titre_complet` n'est pas décoratif : il alimente `aria-label`, `title` ET
     * `data-criterion-label`, donc le libellé du badge de la barre de recherche. L'omettre
     * ferait retomber le badge sur le libellé court — « Partielles », qui isolé ne dit pas
     * de quoi il s'agit.
     *
     * L'option vide retire le critère. Elle ne déclare aucune condition : elle doit rester
     * indestructible.
     */
    private function groupeDeChips(): array
    {
        $options = [];
        foreach (NoteReglementScope::ETATS as $valeur => $etat) {
            $options[] = [
                "value" => $valeur,
                "label" => NoteReglementScope::libelleCourt($valeur),
                "icon" => $etat['icone'],
                "titre_complet" => NoteReglementScope::libelle($valeur),
            ];
        }
        $options[] = [
            "value" => "",
            "label" => "Toutes",
            "icon" => "action:filter",
            "titre_complet" => NoteReglementScope::LIBELLE_AXE . ' : toutes',
        ];

        return [
            "critere" => NoteReglementScope::CRITERION_KEY,
            "libelle" => NoteReglementScope::LIBELLE_AXE,
            "titre" => NoteReglementScope::TITRE_CHIPS,
            "options" => $options,
        ];
    }
}