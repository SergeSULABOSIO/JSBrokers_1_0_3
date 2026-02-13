<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Note;

class NoteFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

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
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]], ["champs" => ["reference"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["validated"]]]],
        ];
        $collections = [['fieldName' => 'paiements', 'entityRouteName' => 'paiement', 'formTitle' => 'Paiement', 'parentFieldName' => 'note']];
        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);
        return $layout;
    }
}
