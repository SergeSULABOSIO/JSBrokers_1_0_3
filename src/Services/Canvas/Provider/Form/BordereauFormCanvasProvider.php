<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Bordereau;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class BordereauFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Bordereau::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Bordereau $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouveau Bordereau",
            "titre_modification" => "Modification du Bordereau #%id%",
            "endpoint_submit_url" => "/admin/bordereau/api/submit",
            "endpoint_delete_url" => "/admin/bordereau/api/delete",
            "endpoint_form_url" => "/admin/bordereau/api/get-form",
            "isCreationMode" => $isParentNew,
            // NOUVEAU : Définition de la barre d'outils pour le volet des attributs.
            // Cette barre ne sera affichée qu'en mode édition.
            "attribute_actions" => [
                [
                    "label" => "Analyser le bordereau",
                    "icon"  => "action:analyser",
                    "event" => "ui:bordereau.analysis-request",
                    "url"   => "/admin/bordereau/api/get-analysis-url/%id%",
                ],
                // Les quatre actions suivantes n'apparaissent que lorsque le bordereau
                // est à l'étape STEP_NOTE_EMISE (= 20), c'est-à-dire qu'une note lui est liée.
                [
                    "label"     => "Éditer la note",
                    "icon"      => "action:edit",
                    "event"     => "ui:bordereau.edit-linked-note",
                    "url"       => "/admin/bordereau/api/get-linked-note-context/%id%",
                    "condition" => ["field" => "currentAnalysisStep", "value" => Bordereau::STEP_NOTE_EMISE],
                ],
                [
                    "label"     => "Voir la note",
                    "icon"      => "action:view",
                    "event"     => "ui:note.preview-request",
                    "url"       => "/admin/bordereau/api/get-linked-note-preview-url/%id%",
                    "condition" => ["field" => "currentAnalysisStep", "value" => Bordereau::STEP_NOTE_EMISE],
                ],
                [
                    "label"     => "Télécharger la note",
                    "icon"      => "action:download",
                    "event"     => "ui:note.preview-request",
                    "url"       => "/admin/bordereau/api/get-linked-note-preview-url/%id%?download=1",
                    "condition" => ["field" => "currentAnalysisStep", "value" => Bordereau::STEP_NOTE_EMISE],
                ],
                [
                    "label"     => "Imprimer la note",
                    "icon"      => "action:print",
                    "event"     => "ui:note.preview-request",
                    "url"       => "/admin/bordereau/api/get-linked-note-preview-url/%id%?print=1",
                    "condition" => ["field" => "currentAnalysisStep", "value" => Bordereau::STEP_NOTE_EMISE],
                ],
            ]
        ];
        $layout = $this->buildBordereauLayout($object, $isParentNew); // buildBordereauLayout ne prend plus idEntreprise

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildBordereauLayout(Bordereau $object, bool $isParentNew): array // Signature alignée sur NoteFormCanvasProvider
    {
        $bordereauId = $object->getId() ?? 0;
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["type"]]]], // Ligne 1: Type
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"], "width" => 6], ["champs" => ["reference"], "width" => 6]]], // Ligne 2: Nom et Référence
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["assureur"]]]], // Ligne 3: Assureur
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["periodeDebut"], "width" => 4], ["champs" => ["periodeFin"], "width" => 4], ["champs" => ["receivedAt"], "width" => 4]]], // Ligne 4: Période début, Période fin et Date de reception
        ];
        $collections = [
            [
                'fieldName' => 'operations', 
                'entityRouteName' => 'operation', 
                'formTitle' => 'Opération', 
                'parentFieldName' => 'bordereau',
                'totalizableField' => 'montantTTC', // Champ à totaliser
                'secondaryField' => 'referencePolice', // Champ secondaire à afficher
                'secondaryLabel' => 'Police: ' // Label pour le champ secondaire
            ],
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'bordereau']
        ];

        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);

        return $layout;
    }
}