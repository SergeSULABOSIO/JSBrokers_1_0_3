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
            "attribute_actions" => [
                [
                    "label"     => "Ajouter une piste dérivée",
                    "icon"      => "piste",
                    "event"     => "ui:avenant.piste-derivee-form-request",
                    "url"       => "/admin/avenant/api/get-piste-derivee-context/%id%",
                    "condition" => ["field" => "hasPisteDerivee", "value" => false],
                ],
                [
                    "label"     => "Éditer la piste dérivée",
                    "icon"      => "action:edit",
                    "event"     => "ui:avenant.piste-derivee-form-request",
                    "url"       => "/admin/avenant/api/get-piste-derivee-context/%id%",
                    "condition" => ["field" => "hasPisteDerivee", "value" => true],
                ],
                [
                    "label"     => "Supprimer la piste dérivée",
                    "icon"      => "action:delete",
                    // Pas de %id% : l'id de l'avenant est transmis en `ids` par le flux
                    // générique app:api.delete-request après confirmation.
                    "event"     => "ui:avenant.delete-piste-derivee",
                    "url"       => "/admin/avenant/api/delete-piste-derivee",
                    "condition" => ["field" => "hasPisteDerivee", "value" => true],
                ],
                // Picker de documents générique (pipe complet de la police).
                [
                    "label" => "Voir les documents",
                    "icon"  => "classeur",
                    "event" => "ui:soa.docs-picker-request",
                    "url"   => "/admin/soa/api/documents/avenant/%id%",
                ],
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
        ];

        $collections = [
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'avenant'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);
        return $layout;
    }
}