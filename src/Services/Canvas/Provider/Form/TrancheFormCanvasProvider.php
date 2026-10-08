<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Invite;
use App\Entity\Tranche;

class TrancheFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    /**
     * Les pièces dont l'enregistrement change le relevé d'une tranche — routes d'enfant
     * de collection (`/admin/{route}/api/submit`) : signalement de prime, note, article,
     * paiement d'une note, reversement de rétrocommission.
     */
    private const ROUTES_FINANCIERES = ['paiementprime', 'note', 'article', 'paiement', 'reversementretroagent'];

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
            // Colonne gauche du dialogue EN CRÉATION : ce que cet objet apporte,
            // et ce qu'il engage en aval. Le premier paragraphe se suffit à
            // lui-même — c'est lui qui répond à qui ouvre le dialogue sans savoir.
            "description_creation" => [
                "Une tranche est une échéance de paiement : c'est elle qui rend la prime exigible à une date donnée, et qui cadence vos encaissements.",
                "Vous la calculez en pourcentage de la prime ou en montant fixe. Une cotation naît avec une tranche unique de 100 %, à ajuster dès que le client paie en plusieurs fois.",
                "Votre commission suit la tranche : elle devient exigible à mesure que la prime est encaissée, et les rétrocommissions dues aux intermédiaires en suivent le prorata.",
                "Une tranche échue et impayée apparaît dans le suivi des impayés : c'est de là que part la relance.",
            ],
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
                    "label"        => "Gérer le partage",
                    "icon"         => "partenaire",
                    "groupe"       => "Partage",
                    "groupe_icone" => "partenaire",
                    "event"        => "ui:partage.picker-request",
                    "url"          => "/admin/partage/tranche/conditions-picker",
                    "droit" => ["entite" => "Tranche", "niveau" => Invite::ACCESS_ECRITURE], // garde de l'endpoint
                    "multi"        => true,
                    // AUCUNE CONDITION D'AFFICHAGE. Le picker rattache ce qui est libre et
                    // détache ce qui est posé : il n'y a plus d'état où l'action n'aurait
                    // rien à offrir, et lui-même sait dire qu'aucune condition n'existe —
                    // là où un bouton masqué n'apprend rien.
                ],
                [
                    "label" => "Signaler un paiement de prime",
                    "icon"  => "paiement",
                    "event" => "ui:tranche.signaler-paiement-prime",
                    "url"   => "/admin/tranche/api/get-paiement-prime-context/%id%",
                    "droit" => ["entite" => "Tranche", "niveau" => Invite::ACCESS_ECRITURE], // garde de l'endpoint
                ],
                // ── RÉCLAMER LA COMMISSION DE CETTE ÉCHÉANCE ──────────────────────
                //
                // Le geste qui suit le précédent : la prime payée rend la commission
                // exigible, et c'est alors qu'on la facture. Le dialogue s'ouvre avec
                // le type, le destinataire et l'objet déjà posés — déduits de la
                // police par SourceDeFacturation, la MÊME règle que l'assistant.
                //
                // ⚠ SANS CE BOUTON, LA PARITÉ JOUERAIT À L'ENVERS. Ket sait facturer
                // depuis une échéance ; un écran qui ne le saurait pas obligerait le
                // courtier à passer par la conversation pour un geste de rubrique.
                //
                // AUCUNE CONDITION D'AFFICHAGE : le serveur seul sait si quelque chose
                // reste dû, et il le dira dans la fenêtre — en NOMMANT la note qui
                // retient, s'il y en a une. Un bouton masqué n'apprendrait rien à celui
                // qui cherche pourquoi il ne peut pas facturer.
                //
                // ⚠ `multi` ET UNE URL SANS `%id%` VONT ENSEMBLE. Le socle ne substitue
                // `%id%` que par la PREMIÈRE ligne cochée ; garder le gabarit ferait
                // donc facturer une échéance et oublier les autres, sans rien dire. La
                // sélection entière voyage dans `payload.selection`, et c'est le cerveau
                // qui la sérialise en `?ids=`.
                [
                    "label" => "Facturer la commission",
                    "icon"  => "note",
                    "multi" => true,
                    "event" => "ui:tranche.facturer-commission",
                    "url"   => "/admin/note/facturation-picker",
                    "droit" => ["entite" => "Note", "niveau" => Invite::ACCESS_ECRITURE], // garde de l'endpoint
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
                "relevePrime"      => "paiement",
                "releveCommission" => "revenu",
                "releveRetro"      => "depense",
                "releveTaxe"       => "taxe",
            ],
            // `paiementsPrime` reste dans TrancheType (Ket et la reprise l'écrivent), mais
            // n'a plus d'onglet : il ne doit pas réapparaître en bas du formulaire par
            // render_rest. Il est `mapped: false` — sa soumission n'écrit rien, et ne
            // peut donc effacer aucun paiement (orphanRemoval).
            "suppress_fields" => ["paiementsPrime"],
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

        // ── LE RELEVÉ FINANCIER : QUATRE ONGLETS EN LECTURE SEULE ───────────────────
        //
        // Le gestionnaire de compte voit tout de l'échéance — dû, exigible, payé, solde,
        // et chaque mouvement — sans rien toucher de ce qui relève de la comptabilité.
        // Chaque onglet est un widget de collection branché sur le relevé
        // (TrancheController::releveApi), qui répond au contrat des collections : il se
        // charge, se compte et se rafraîchit comme les autres, sans JS dédié.
        //
        // L'ancien onglet « Paiements de prime » est FONDU dans « Prime » : les
        // signalements y sont des lignes, à côté des factures client et des bordereaux.
        // On signale par l'action « Signaler un paiement de prime » ; une ligne de
        // signalement se corrige depuis le relevé, selon les droits sur PaiementPrime.
        //
        // Rien à relever avant la naissance de la tranche : masqués en création.
        $collections = [];
        foreach ([
            ['relevePrime', 'prime', 'Prime', 'paiementprime', 'Paiement de prime'],
            ['releveCommission', 'commission', 'Commission', 'tranche', 'Commission'],
            ['releveRetro', 'retrocommission', 'Rétrocommissions', 'tranche', 'Rétrocommission'],
            ['releveTaxe', 'taxe', 'Taxes', 'tranche', 'Taxe'],
        ] as [$champ, $famille, $onglet, $route, $titre]) {
            $collections[] = [
                'fieldName'       => $champ,
                'entityRouteName' => $route,
                'formTitle'       => $titre,
                'ongletTitre'     => $onglet,
                'parentFieldName' => 'tranche',
                'listUrl'         => '/admin/tranche/api/%parentId%/releve/' . $famille,
                'lectureSeule'    => true,
                'disabled'        => $isParentNew,
                'hidden'          => $isParentNew,
                // Un mouvement financier, d'où qu'il vienne, change plusieurs familles à
                // la fois (une commission encaissée rend des taxes et des rétros
                // exigibles) : c'est la fiche entière qui se recharge.
                'ficheParente'    => 'Tranche',
                'rechargerSur'    => self::ROUTES_FINANCIERES,
            ];
        }
        // Pièces jointes de cette fiche.
        $collections[] = ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'ongletTitre' => 'Documents', 'parentFieldName' => 'tranche'];
        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);

        return $layout;
    }
}
