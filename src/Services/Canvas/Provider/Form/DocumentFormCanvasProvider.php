<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Document;

class DocumentFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Document::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Document $object */
        $isParentNew = ($object->getId() === null);
        $documentId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouveau Document",
            "titre_modification" => "Modification du document #%id%",
            "endpoint_submit_url" => "/admin/document/api/submit",
            "endpoint_delete_url" => "/admin/document/api/delete",
            "endpoint_form_url" => "/admin/document/api/get-form",
            "isCreationMode" => $isParentNew,
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Document du classeur",
                "description" => "Vous enregistrez un document en précisant son nom, le classeur de rattachement et le fichier à téléverser. Chaque pièce ainsi archivée reste traçable et accessible pour la gestion administrative du cabinet.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "nom"      => "action:edit",
                "classeur" => "classeur",
                "fichier"  => "action:upload",
            ],
        ];
        $layout = $this->buildDocumentLayout($documentId, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildDocumentLayout(int $documentId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["classeur"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["fichier"]]]],
        ];

        return $layout;
    }
}