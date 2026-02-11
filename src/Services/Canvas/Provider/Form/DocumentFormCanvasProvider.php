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
            "isCreationMode" => $isParentNew
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
            // En ajoutant le champ 'cotation', on s'assure que la liaison avec l'entité parente
            // est gérée automatiquement par le formulaire, suivant le même modèle que pour Tranche.php.
            // Le champ 'classeur' est conservé pour les cas où un document est ajouté depuis un classeur.
            // Le système gérera correctement l'association car un seul des deux champs (cotation ou classeur) sera rempli.
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]], ["champs" => ["cotation"]], ["champs" => ["classeur"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["fichier"]]]],
        ];

        return $layout;
    }
}