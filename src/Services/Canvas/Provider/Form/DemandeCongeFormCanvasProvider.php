<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\DemandeConge;

/**
 * Le dialogue d'une demande de congé, et les quatre gestes du circuit.
 *
 * ── UN SEUL ÉVÉNEMENT POUR QUATRE ACTIONS ───────────────────────────────────────────
 * Soumettre, approuver, refuser et annuler partagent l'événement
 * « ui:conge.decision-request » ; c'est l'URL qui porte le geste (`?geste=…`). Quatre
 * événements distincts auraient voulu quatre `case` dans le cerveau, donc quatre
 * occasions d'en oublier un — et une action déclarée sans `case` s'affiche, se laisse
 * cliquer et ne produit RIEN, sans la moindre trace.
 *
 * ── LES CONDITIONS SONT UN CONFORT, PAS UNE PROTECTION ──────────────────────────────
 * Les drapeaux `peutEtre*` sont des propriétés DÉCLARÉES de l'entité (groupe list:read) :
 * c'est ce qui les fait voyager jusqu'à la ligne de liste, donc jusqu'à la barre d'outils
 * et au menu contextuel. La règle véritable est rejouée côté serveur par
 * DemandeCongeWorkflow à chaque exécution.
 */
class DemandeCongeFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === DemandeConge::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var DemandeConge $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouvelle demande de congé",
            "titre_modification" => "Demande de congé #%id%",
            "endpoint_submit_url" => "/admin/demandeconge/api/submit",
            "endpoint_delete_url" => "/admin/demandeconge/api/delete",
            "endpoint_form_url" => "/admin/demandeconge/api/get-form",
            "isCreationMode" => $isParentNew,
            "form_intro" => [
                "titre" => "Demander un congé",
                "description" => "Choisissez la période : le nombre de jours réellement décompté est calculé à l'enregistrement, week-ends, jours fériés et votre régime de travail retirés. La demande part ensuite vers vos valideurs, qui verront votre solde avant de décider.",
                "facts_labels" => [
                    "agent" => "Collaborateur",
                    "statut" => "État de la demande",
                ],
            ],
            "suppression_note" => "Supprimer une demande efface aussi son historique. Pour revenir en arrière sur un congé déjà décidé, utilisez « Annuler » : le solde est recrédité et la trace reste lisible.",
            "field_icons" => [
                "agent"               => "invite",
                "typeAbsence"         => "type-absence",
                "dateDebut"           => "action:calendar",
                "dateFin"             => "action:calendar",
                "demiJourneeDebut"    => "action:calendar",
                "demiJourneeFin"      => "action:calendar",
                "motif"               => "action:description",
                "statut"              => "action:check",
                "valideur"            => "invite",
                "dateDecision"        => "action:calendar",
                "commentaireDecision" => "action:reply",
                "controlesContournes" => "action:alert",
                "documents"           => "document",
            ],
            "attribute_actions" => [
                [
                    "label"     => "Soumettre la demande",
                    "icon"      => "action:upload",
                    "groupe"    => "Circuit de validation",
                    "groupe_icone" => "conge",
                    "event"     => "ui:conge.decision-request",
                    "url"       => "/admin/demandeconge/api/decision-picker/%id%?geste=soumettre",
                    "condition" => ["field" => "peutEtreSoumise", "value" => true],
                ],
                [
                    "label"     => "Approuver",
                    "icon"      => "action:completed",
                    "groupe"    => "Circuit de validation",
                    "groupe_icone" => "conge",
                    "event"     => "ui:conge.decision-request",
                    "url"       => "/admin/demandeconge/api/decision-picker/%id%?geste=approuver",
                    "condition" => ["field" => "peutEtreDecidee", "value" => true],
                ],
                [
                    "label"     => "Refuser",
                    "icon"      => "action:cancel",
                    "groupe"    => "Circuit de validation",
                    "groupe_icone" => "conge",
                    "event"     => "ui:conge.decision-request",
                    "url"       => "/admin/demandeconge/api/decision-picker/%id%?geste=refuser",
                    "condition" => ["field" => "peutEtreDecidee", "value" => true],
                ],
                [
                    // LE CALENDRIER N'EST PAS UNE ACTION SUR UNE LIGNE : `multi` le rend
                    // cliquable sans sélection, comme « Ajouter au chat ». Sans lui, il
                    // faudrait cocher une demande au hasard pour voir le mois.
                    "label"     => "Calendrier de l'équipe",
                    "icon"      => "action:calendar",
                    "groupe"    => "Circuit de validation",
                    "groupe_icone" => "conge",
                    "event"     => "ui:conge.calendrier-request",
                    "url"       => "/admin/demandeconge/api/calendrier",
                    "multi"     => true,
                ],
                [
                    "label"     => "Annuler le congé",
                    "icon"      => "action:annulation",
                    "groupe"    => "Circuit de validation",
                    "groupe_icone" => "conge",
                    "event"     => "ui:conge.decision-request",
                    "url"       => "/admin/demandeconge/api/decision-picker/%id%?geste=annuler",
                    "condition" => ["field" => "peutEtreAnnulee", "value" => true],
                ],
            ],
        ];

        $layout = $this->buildDemandeCongeLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout),
        ];
    }

    private function buildDemandeCongeLayout(DemandeConge $object, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["agent"], 'width' => 6], ["champs" => ["typeAbsence"], 'width' => 6]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["dateDebut"], 'width' => 6], ["champs" => ["dateFin"], 'width' => 6]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["demiJourneeDebut"], 'width' => 6], ["champs" => ["demiJourneeFin"], 'width' => 6]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["motif"], 'width' => 12]]],
            // LES CHAMPS DE DÉCISION SONT MASQUÉS, mais PRÉSENTS : ils sont soumis avec
            // le formulaire, et c'est par eux que l'assistant écrit une décision. Un
            // champ absent d'ici serait une écriture morte en silence — le plan passerait
            // et la colonne « Décidé par » resterait vide.
            //
            // Les afficher, en revanche, inviterait à changer un état à la main en
            // contournant le circuit — sans mouvement de compteur ni ligne d'historique.
            ["couleur_fond" => "white", "hidden" => true, "colonnes" => [["champs" => ["statut"]]]],
            ["couleur_fond" => "white", "hidden" => true, "colonnes" => [["champs" => ["valideur"]]]],
            ["couleur_fond" => "white", "hidden" => true, "colonnes" => [["champs" => ["dateDecision"]]]],
            ["couleur_fond" => "white", "hidden" => true, "colonnes" => [["champs" => ["commentaireDecision"]]]],
            ["couleur_fond" => "white", "hidden" => true, "colonnes" => [["champs" => ["controlesContournes"]]]],
        ];

        // Justificatifs : certificat médical, acte, attestation…
        $collections = [
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Justificatif', 'parentFieldName' => 'demandeConge'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);

        return $layout;
    }
}
