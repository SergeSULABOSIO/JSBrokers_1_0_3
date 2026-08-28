<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Tranche;

class TrancheFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Tranche::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Tranche $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouvelle Tranche",
            "titre_modification" => "Modification de la Tranche #%id%",
            "endpoint_submit_url" => "/admin/tranche/api/submit",
            "endpoint_delete_url" => "/admin/tranche/api/delete",
            "endpoint_form_url" => "/admin/tranche/api/get-form",
            "isCreationMode" => $isParentNew,
            // Action rapide « Signaler un paiement de prime » (menu contextuel, barre
            // d'outils, volet du dialogue) : ouvre le dialogue de création PaiementPrime
            // rattaché à la tranche. Toujours disponible (paiements partiels/correctifs).
            "attribute_actions" => [
                // ── L'EFFORT COMMERCIAL D'UN AGENT INTERNE ────────────────────────────
                //
                // Le rattachement s'ÉCRIT toujours sur la PISTE : c'est la règle métier, et
                // elle ne bouge pas. Ce qui change, c'est qu'on peut l'ORDONNER d'ici —
                // parce que c'est d'ici qu'on travaille. Le serveur remonte l'arbre
                // (RattachementDuPartage::piste) et écrit au seul endroit légitime.
                //
                // `multi` sur le rattachement : on couvre une sélection entière d'un geste.
                // ⚠ La condition d'une action `multi` n'est évaluée que sur la PREMIÈRE
                // ligne (toolbar_controller) : le bouton peut donc s'afficher alors qu'une
                // ligne plus bas est déjà prise. Le contrôle « toutes libres » est SERVEUR,
                // et c'est là qu'il doit être — ce gating-ci reste cosmétique.
                [
                    "label"        => "Rattacher une condition de partage",
                    "icon"         => "partenaire",
                    "groupe"       => "Partage",
                    "groupe_icone" => "partenaire",
                    "event"        => "ui:partage.picker-request",
                    "url"          => "/admin/partage/tranche/conditions-picker",
                    "multi"        => true,
                    // Vrai tant qu'une famille AU MOINS est libre : une affaire reçoit un
                    // apporteur ET un agent, mais pas deux du même camp.
                    "condition"    => ["field" => "partageRattachable", "value" => true],
                ],
                [
                    "label"        => "Détacher la condition de partage",
                    "icon"         => "action:detach",
                    "groupe"       => "Partage",
                    "groupe_icone" => "partenaire",
                    // LE DÉTACHEMENT PASSE PAR LE MÊME PICKER que le rattachement, en mode
                    // « détacher » : il était un appel direct, ce qui ne suffit plus depuis
                    // qu'une affaire peut porter DEUX conditions — il faut dire laquelle.
                    // Un seul chemin, plutôt qu'un branchement selon leur nombre.
                    "event"        => "ui:partage.picker-request",
                    "url"          => "/admin/partage/tranche/conditions-picker?mode=detacher",
                    "condition"    => ["field" => "partageDetachable", "value" => true],
                ],
                [
                    "label" => "Signaler un paiement de prime",
                    "icon"  => "paiement",
                    "event" => "ui:tranche.signaler-paiement-prime",
                    "url"   => "/admin/tranche/api/get-paiement-prime-context/%id%",
                ],
            ],
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Tranche",
                "description" => "Vous découpez le paiement d'une affaire en tranches : mode de calcul (montant fixe ou pourcentage), date d'exigibilité et échéance. Les tranches cadencent la facturation et le suivi des encaissements.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "documents" => "document",
                "nom"            => "action:edit",
                "modeCalcul"     => "action:options",
                "montantFlat"    => "action:count",
                "pourcentage"    => "action:count",
                "payableAt"      => "action:calendar",
                "echeanceAt"     => "action:calendar",
                "paiementsPrime" => "paiement",
            ],
        ];
        $layout = $this->buildTrancheLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildTrancheLayout(object $object, bool $isParentNew): array
    {
        // Conditions de visibilité pour les champs dynamiques
        $visibilityConditionPourcentage = [
            'visibility_conditions' => [
                ['field' => 'modeCalcul', 'operator' => 'in', 'value' => ['pourcentage']]
            ]
        ];
        $visibilityConditionMontant = [
            'visibility_conditions' => [
                ['field' => 'modeCalcul', 'operator' => 'in', 'value' => ['montant_fixe']]
            ]
        ];

        $layout = [
            // Ligne 1: Nom (Toute la largeur)
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            
            // Ligne 2: Mode de calcul (Toute la largeur)
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["modeCalcul"]]]],
            
            // Ligne 3: Montant Fixe (Visible si modeCalcul == montant_fixe)
            ["couleur_fond" => "white", "colonnes" => [["champs" => [array_merge(['field_code' => 'montantFlat'], $visibilityConditionMontant)]]]],
            
            // Ligne 4: Pourcentage (Visible si modeCalcul == pourcentage)
            ["couleur_fond" => "white", "colonnes" => [["champs" => [array_merge(['field_code' => 'pourcentage'], $visibilityConditionPourcentage)]]]],
            
            // Ligne 5: PayableAt et EcheanceAt (6/12 chacun)
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["payableAt"], "width" => 6],
                ["champs" => ["echeanceAt"], "width" => 6]
            ]],
        ];

        // Signalements de paiement de la prime (marché où l'ASSUREUR encaisse) :
        // trace déclarative qui rend la commission exigible — jamais la trésorerie.
        $collections = [
            ['fieldName' => 'paiementsPrime', 'entityRouteName' => 'paiementprime', 'formTitle' => 'Paiement de prime', 'parentFieldName' => 'tranche'],
        ];
        // Pièces jointes de cette fiche.
        $collections[] = ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'tranche'];
        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);

        return $layout;
    }
}
