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
            "titre_creation" => "Reversement de rétrocommission",
            "titre_modification" => "Reversement #%id%",
            "endpoint_submit_url" => "/admin/reversementretroagent/api/submit",
            "endpoint_delete_url" => "/admin/reversementretroagent/api/delete",
            "endpoint_form_url" => "/admin/reversementretroagent/api/get-form",
            "isCreationMode" => $isParentNew,
            "creation_interdite" => true,
            "creation_interdite_message" => "Un reversement s'enregistre depuis « Voir le rapport de production » "
                . "de l'agent, ou en le demandant à l'assistant : le justificatif du virement est exigé du même geste.",
            "form_intro" => [
                "titre" => "Reversement de rétrocommission",
                "description" => "La part d'une commission déjà encaissée par le cabinet, reversée à l'agent qui a "
                    . "apporté l'affaire. La sortie de fonds est réelle : elle se justifie par un bordereau de "
                    . "virement ou un reçu signé. Plusieurs affaires réglées par un même virement partagent une "
                    . "référence de lot, et une seule pièce vaut pour tout le lot.",
            ],
            "field_icons" => [
                "agent"          => "invite",
                "avenant"        => "avenant",
                "montant"        => "action:count",
                "paidAt"         => "action:calendar",
                "reference"      => "action:count",
                "lotReference"   => "action:count",
                "compteBancaire" => "compte-bancaire",
                "description"    => "action:description",
                "documents"      => "document",
            ],
            "facts_labels" => [
                "lotReference" => "Virement groupé (référence de lot)",
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
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["agent"], "width" => 6], ["champs" => ["avenant"], "width" => 6]]],
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
