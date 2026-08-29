<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\ReversementRetroAgent;

/**
 * LE FORMULAIRE D'UN REVERSEMENT — consultable et corrigeable, mais PAS créable ici.
 *
 * ── POURQUOI LA CRÉATION EST FERMÉE ────────────────────────────────────────────────
 * Un reversement ne s'enregistre pas sans justificatif. Or les pièces d'une fiche
 * s'attachent APRÈS sa création, élément par élément, par l'API des documents : exiger la
 * preuve à la création serait donc une contradiction — il faudrait créer pour pouvoir
 * attacher. Un reversement n'est du reste pas une fiche qu'on saisit, c'est la trace d'une
 * décision de paiement : ses deux portes sont le picker du rapport de production et
 * l'assistant, tous deux transactionnels, tous deux exigeant la pièce d'un seul geste.
 *
 * Le bouton reste VISIBLE et grisé, avec son motif : masqué, il aurait laissé croire à un
 * droit manquant.
 */
class ReversementRetroAgentFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ReversementRetroAgent::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var ReversementRetroAgent $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Rétro intermédiaire",
            "titre_modification" => "Reversement #%id%",
            "endpoint_submit_url" => "/admin/reversementretroagent/api/submit",
            "endpoint_delete_url" => "/admin/reversementretroagent/api/delete",
            "endpoint_form_url" => "/admin/reversementretroagent/api/get-form",
            "isCreationMode" => $isParentNew,
            "creation_interdite" => true,
            "creation_interdite_message" => "Un reversement s'enregistre depuis « Voir le rapport de production » "
                . "du bénéficiaire — agent interne ou partenaire externe —, ou en le demandant à l'assistant : "
                . "le justificatif du virement est exigé du même geste.",
            "form_intro" => [
                "titre" => "Rétro intermédiaire",
                "description" => "La part d'une commission déjà encaissée par le cabinet, reversée à l'intermédiaire "
                    . "qui a apporté l'affaire — un agent interne, ou un partenaire externe qui a facturé par sa "
                    . "note de débit. Le versement se rattache à l'ÉCHÉANCE réglée : c'est par tranche que la "
                    . "prime et la commission sont payées. La sortie de fonds est réelle : elle se justifie par "
                    . "un bordereau de virement ou un reçu signé. Plusieurs échéances réglées par un même "
                    . "virement partagent une référence de lot, et une seule pièce vaut pour tout le lot.",
            ],
            "field_icons" => [
                "agent"          => "invite",
                "partenaire"     => "partenaire",
                "tranche"        => "tranche",
                "avenant"        => "avenant",
                "montant"        => "action:count",
                "paidAt"         => "action:calendar",
                "reference"      => "action:count",
                "lotReference"   => "action:count",
                "compteBancaire" => "compte-bancaire",
                "description"    => "action:description",
                "documents"      => "document",
            ],
            // VOIR D'OÙ VIENT CE VERSEMENT. Un reversement isolé ne dit ni ce qui reste dû
            // à l'agent, ni sur quelles affaires : le rapport de production le dit, et
            // c'est là qu'on décide du versement suivant.
            "attribute_actions" => [
                // LA RELECTURE EST CELLE DU VIREMENT, pas de la ligne. En la déclarant ici,
                // on empêche l'injection de l'action générique (porteDejaUneVueDocuments) :
                // deux entrées du même nom disant deux choses seraient pires qu'une seule.
                // « Attacher des pièces », elle, reste générique — puisque la lecture est
                // lot-consciente, une pièce déposée sur n'importe quel membre est vue par
                // tout le virement.
                [
                    "label"        => "Voir les documents",
                    "icon"         => "classeur",
                    "groupe"       => "Pièces jointes",
                    "groupe_icone" => "classeur",
                    "event"        => "ui:documents.liste-request",
                    "url"          => "/admin/retro-agent/reversement/%id%/justificatifs",
                ],
                [
                    "label"        => "Voir le rapport de production du bénéficiaire",
                    "icon"         => "invite",
                    "groupe"       => "Rétrocommissions",
                    "groupe_icone" => "depense",
                    "event"        => "ui:retroagent.rapport-request",
                    "url"          => "/admin/retro-agent/reversement/%id%/rapport",
                ],
            ],
            // ── « ÉDITER » OUVRE LA FENÊTRE DE REVERSEMENT ────────────────────────────
            //
            // Une ligne de cette rubrique représente un VIREMENT entier — la rubrique replie
            // chaque lot sur son porteur. Le dialogue générique n'en aurait montré qu'une
            // échéance sur six, et laissé corriger un montant sans voir les autres.
            //
            // L'aiguillage est DÉCLARATIF, comme les actions ci-dessus : le cerveau lit cette
            // clé sur `ui:toolbar.edit-request` et émet l'événement au lieu d'ouvrir le
            // dialogue. Rien ne change pour les entités qui n'en déclarent pas — et
            // « Ouvrir », lui, continue d'ouvrir la fiche.
            "edition_personnalisee" => [
                "event" => "ui:retroagent.reversement-request",
                "url"   => "/admin/retro-agent/reversement/%id%/editer",
            ],
            // UN VIREMENT SE SUPPRIME EN ENTIER, et la boîte de confirmation doit le dire
            // AVANT : la ligne sélectionnée en représente plusieurs, et l'utilisateur ne
            // peut pas le deviner d'un écran qui n'en montre qu'une.
            "suppression_note" => "Un virement se supprime en entier : toutes les échéances "
                . "qu'il règle sont retirées, et son écriture comptable défaite.",
            "facts_labels" => [
                "lotReference" => "Virement groupé (référence de lot)",
                "tranche" => "Échéance réglée",
            ],
        ];
        $layout = $this->buildReversementLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout),
        ];
    }

    private function buildReversementLayout(ReversementRetroAgent $object, bool $isParentNew): array
    {
        $layout = [
            // Les DEUX bénéficiaires sont rendus : l'un est vide, et c'est ainsi qu'on
            // voit lequel porte le versement. Les masquer par condition aurait exigé un
            // champ de pilotage non mappé pour une fiche qu'on ne CRÉE jamais ici.
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["agent"], "width" => 6], ["champs" => ["partenaire"], "width" => 6]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["tranche"], "width" => 6], ["champs" => ["avenant"], "width" => 6]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["montant"], "width" => 6], ["champs" => ["paidAt"], "width" => 6]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["reference"], "width" => 6], ["champs" => ["compteBancaire"], "width" => 6]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["lotReference"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
        ];

        $collections = [
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Pièce', 'parentFieldName' => 'reversementRetroAgent'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);

        return $layout;
    }
}
