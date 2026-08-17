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
                // LE CLASSEUR N'EST PLUS À PRÉCISER, et il fallait cesser de le demander :
                // un document rattaché à un client est désormais rangé tout seul dans le
                // classeur de ce client. Laisser la consigne d'avant ferait chercher à
                // l'utilisateur une décision qu'il n'a plus à prendre — et le champ, resté
                // facultatif, ne sert plus qu'à ranger AILLEURS que dans le dossier du client.
                "description" => "Vous enregistrez un document en précisant son nom et le fichier à téléverser. Le classement est automatique : une pièce rattachée à un client rejoint le classeur de ce client. Le champ « classeur » ne sert qu'à la ranger ailleurs.",
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