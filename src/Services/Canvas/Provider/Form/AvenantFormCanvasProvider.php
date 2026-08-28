<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Avenant;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class AvenantFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Avenant::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Avenant $object */
        $isParentNew = ($object->getId() === null);
        $avenantId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouvel Avenant",
            "titre_modification" => "Modification de l'Avenant #%id%",
            "endpoint_submit_url" => "/admin/avenant/api/submit",
            "endpoint_delete_url" => "/admin/avenant/api/delete",
            "endpoint_form_url" => "/admin/avenant/api/get-form",
            "isCreationMode" => $isParentNew,
            // Actions « piste dérivée » conditionnelles (pattern Invité→Portefeuille) :
            // condition évaluée côté front contre l'attribut calculé hasPisteDerivee
            // (AvenantIndicatorStrategy). Ajouter/Éditer ouvrent le même endpoint de
            // contexte ; le backend adapte le mode (create/edit) à l'état réel de l'avenant.
            // Actions spécifiques de la rubrique. Le CLÉ « groupe » replie une FAMILLE
            // d'actions en une seule entrée (bouton déroulant en barre d'outils,
            // sous-menu au clic droit) : sans elle, les sept actions ci-dessous
            // saturaient la barre. Cf. assets/controllers/actions-groupees.js — la
            // règle de regroupement est partagée par les deux surfaces.
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
                    "url"          => "/admin/partage/avenant/conditions-picker",
                    "multi"        => true,
                    // AUCUNE CONDITION D'AFFICHAGE. Le picker rattache ce qui est libre et
                    // détache ce qui est posé : il n'y a plus d'état où l'action n'aurait
                    // rien à offrir, et lui-même sait dire qu'aucune condition n'existe —
                    // là où un bouton masqué n'apprend rien.
                ],
                // MOUVEMENTS DE LA POLICE — parité stricte avec l'assistant : mêmes
                // quatre actes, même moteur (MouvementAvenantBuilder), mêmes défauts.
                // Condition `hasPisteDerivee == false` : une police déjà mouvementée ne
                // l'est pas deux fois (miroir exact du garde « dejaTraite » de l'outil).
                [
                    "label"        => "Renouveler à l'identique",
                    "icon"         => "action:renew",
                    "groupe"       => "Mouvements de la police",
                    "groupe_icone" => "action:renew",
                    "event"        => "ui:avenant.mouvement-request",
                    "url"          => "/admin/avenant/api/mouvement-picker/renouvellement/%id%",
                    "condition"    => ["field" => "hasPisteDerivee", "value" => false],
                ],
                [
                    "label"     => "Proroger la police",
                    "icon"      => "action:prorogation",
                    "groupe"    => "Mouvements de la police",
                    "event"     => "ui:avenant.mouvement-request",
                    "url"       => "/admin/avenant/api/mouvement-picker/prorogation/%id%",
                    "condition" => ["field" => "hasPisteDerivee", "value" => false],
                ],
                [
                    "label"     => "Annuler la police",
                    "icon"      => "action:annulation",
                    "groupe"    => "Mouvements de la police",
                    "event"     => "ui:avenant.mouvement-request",
                    "url"       => "/admin/avenant/api/mouvement-picker/annulation/%id%",
                    "condition" => ["field" => "hasPisteDerivee", "value" => false],
                ],
                [
                    "label"     => "Résilier la police",
                    "icon"      => "action:resiliation",
                    "groupe"    => "Mouvements de la police",
                    "event"     => "ui:avenant.mouvement-request",
                    "url"       => "/admin/avenant/api/mouvement-picker/resiliation/%id%",
                    "condition" => ["field" => "hasPisteDerivee", "value" => false],
                ],
                // Gestion manuelle de l'opportunité dérivée : même famille métier que
                // les mouvements (elle en est le support), donc même repli.
                [
                    "label"     => "Ajouter une piste dérivée",
                    "icon"      => "piste",
                    "groupe"    => "Mouvements de la police",
                    "event"     => "ui:avenant.piste-derivee-form-request",
                    "url"       => "/admin/avenant/api/get-piste-derivee-context/%id%",
                    "condition" => ["field" => "hasPisteDerivee", "value" => false],
                ],
                [
                    "label"        => "Éditer la piste dérivée",
                    "icon"         => "action:edit",
                    "groupe"       => "Mouvements de la police",
                    "groupe_icone" => "piste",
                    "event"        => "ui:avenant.piste-derivee-form-request",
                    "url"          => "/admin/avenant/api/get-piste-derivee-context/%id%",
                    "condition"    => ["field" => "hasPisteDerivee", "value" => true],
                ],
                [
                    "label"     => "Supprimer la piste dérivée",
                    "icon"      => "action:delete",
                    "groupe"    => "Mouvements de la police",
                    // Pas de %id% : l'id de l'avenant est transmis en `ids` par le flux
                    // générique app:api.delete-request après confirmation.
                    "event"     => "ui:avenant.delete-piste-derivee",
                    "url"       => "/admin/avenant/api/delete-piste-derivee",
                    "condition" => ["field" => "hasPisteDerivee", "value" => true],
                ],
                // SUIVI DU RENOUVELLEMENT — famille distincte des mouvements : ici rien n'est
                // créé, on consigne une DÉCISION. Aucune condition de date : l'information
                // arrive quand elle arrive (le client annonce en mars ce qui se produira en
                // décembre), et c'est en pleine couverture que la note a le plus de valeur.
                // Les trois entrées sont exclusives entre elles via le seul booléen.
                [
                    "label"        => "Signaler : à ne pas renouveler",
                    "icon"         => "action:no-renew",
                    "groupe"       => "Suivi du renouvellement",
                    "groupe_icone" => "action:no-renew",
                    "event"        => "ui:avenant.non-renouvelable-request",
                    "url"          => "/admin/avenant/api/non-renouvelable-picker/%id%",
                    "condition"    => ["field" => "nonRenouvelable", "value" => false],
                ],
                [
                    // Le motif s'affine avec le temps : le corriger ne doit pas obliger à
                    // démarquer puis remarquer, ce qui écraserait la date de la décision.
                    "label"     => "Modifier le motif de non-renouvellement",
                    "icon"      => "action:edit",
                    "groupe"    => "Suivi du renouvellement",
                    "event"     => "ui:avenant.non-renouvelable-request",
                    "url"       => "/admin/avenant/api/non-renouvelable-picker/%id%?mode=motif",
                    "condition" => ["field" => "nonRenouvelable", "value" => true],
                ],
                [
                    // Une décision commerciale se révise : le retrait est un chemin de premier
                    // rang, ouvert à tout collègue qui reçoit l'information — pas au seul auteur.
                    "label"     => "Rétablir le renouvellement",
                    "icon"      => "action:renew",
                    "groupe"    => "Suivi du renouvellement",
                    "event"     => "ui:avenant.non-renouvelable-request",
                    "url"       => "/admin/avenant/api/non-renouvelable-picker/%id%?mode=lever",
                    "condition" => ["field" => "nonRenouvelable", "value" => true],
                ],
                // Picker de documents générique (pipe complet de la police) : hors
                // famille, il reste accessible en un seul clic.
                [
                    "label" => "Voir les documents",
                    "icon"  => "classeur",
                    "event" => "ui:soa.docs-picker-request",
                    "url"   => "/admin/soa/api/documents/avenant/%id%",
                ],
            ],
            // BANDEAU D'ALERTE en tête du volet de saisie. La colonne d'attributs calculés
            // compte une cinquantaine de lignes : une décision de non-renouvellement y serait
            // NOYÉE, alors qu'elle doit sauter aux yeux de quiconque ouvre la police. Rendu
            // seulement si l'attribut nommé est non vide — donc invisible sur toute police
            // ordinaire, et sur toute autre entité (clé opt-in, cf. _form_content.html.twig).
            "form_alerte" => [
                "niveau"     => "warning",
                "titre"      => "Police signalée non renouvelable",
                "texte_code" => "nonRenouvelableDetail",
            ],
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Fiche avenant",
                "description" => "Vous précisez la modification contractuelle apportée à la police : numéro, référence, période d'effet et pièces associées. Chaque avenant trace l'évolution du contrat et sécurise le suivi de la couverture.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "cotation"        => "cotation",
                "numero"          => "action:edit",
                "referencePolice" => "action:edit",
                "description"     => "action:description",
                "startingAt"      => "action:calendar",
                "endingAt"        => "action:calendar",
                "renewalStatus"   => "avenant",
                "nonRenouvelable" => "action:no-renew",
                "nonRenouvelableMotif" => "action:description",
                "documents"       => "document",
            ],
        ];
        $layout = $this->buildAvenantLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildAvenantLayout(Avenant $object, bool $isParentNew): array
    {
        $avenantId = $object->getId() ?? 0;
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["cotation"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["numero"]], ["champs" => ["referencePolice"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["startingAt"]], ["champs" => ["endingAt"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["renewalStatus"]]]],
            // Décision de non-renouvellement : la case et son motif restent côte à côte, la
            // seconde n'ayant aucun sens sans la première.
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nonRenouvelable"]], ["champs" => ["nonRenouvelableMotif"]]]],
        ];

        $collections = [
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'avenant'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);
        return $layout;
    }
}