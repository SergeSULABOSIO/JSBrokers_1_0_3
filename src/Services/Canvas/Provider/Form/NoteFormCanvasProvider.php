<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Note;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class NoteFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Note::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Note $object */
        $isParentNew = ($object->getId() === null);
        $noteId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouvelle Note",
            "titre_modification" => "Modification de la Note #%id%",
            "endpoint_submit_url" => "/admin/note/api/submit",
            "endpoint_delete_url" => "/admin/note/api/delete",
            "endpoint_form_url" => "/admin/note/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildNoteLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildNoteLayout(Note $object, bool $isParentNew): array
    {
        $noteId = $object->getId() ?? 0;
        $layout = [
            // Ligne 1: nom (2/3), reference (1/3)
            [
                "colonnes" => [
                    ["champs" => ["nom"], "width" => 8],
                    ["champs" => ["reference"], "width" => 4]
                ]
            ],
            // Ligne 2: type (1/2), addressedTo (1/2)
            [
                "colonnes" => [
                    ["champs" => ["type"], "width" => 6],
                    ["champs" => ["addressedTo"], "width" => 6]
                ]
            ],
            // Ligne 3: Description
            [
                "colonnes" => [["champs" => ["description"]]]
            ],
            // Lignes conditionnelles
            [
                "colonnes" => [["champs" => [['field_code' => 'client']]]],
                'visibility_conditions' => [['field' => 'addressedTo', 'operator' => 'in', 'value' => [Note::TO_CLIENT]]]
            ],
            [
                "colonnes" => [["champs" => [['field_code' => 'assureur']]]],
                'visibility_conditions' => [['field' => 'addressedTo', 'operator' => 'in', 'value' => [Note::TO_ASSUREUR]]]
            ],
            [
                "colonnes" => [["champs" => [['field_code' => 'partenaire']]]],
                'visibility_conditions' => [['field' => 'addressedTo', 'operator' => 'in', 'value' => [Note::TO_PARTENAIRE]]]
            ],
            [
                "colonnes" => [["champs" => [['field_code' => 'autoritefiscale']]]],
                'visibility_conditions' => [['field' => 'addressedTo', 'operator' => 'in', 'value' => [Note::TO_AUTORITE_FISCALE]]]
            ],
            // Ligne 5: Comptes bancaires
            [
                "colonnes" => [["champs" => ["comptes"]]]
            ],
            // Ligne 6: Validated
            [
                "colonnes" => [["champs" => ["validated"]]]
            ],
        ];
        $collections = [
            ['fieldName' => 'articles', 'entityRouteName' => 'article', 'formTitle' => 'Article', 'parentFieldName' => 'note'],
            ['fieldName' => 'paiements', 'entityRouteName' => 'paiement', 'formTitle' => 'Paiement', 'parentFieldName' => 'note']
        ];
        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);
        return $layout;
    }
}
