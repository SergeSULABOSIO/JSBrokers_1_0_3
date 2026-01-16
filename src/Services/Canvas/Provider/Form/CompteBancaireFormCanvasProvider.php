<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\CompteBancaire;

class CompteBancaireFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === CompteBancaire::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var CompteBancaire $object */
        $isParentNew = ($object->getId() === null);
        $compteId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouveau Compte Bancaire",
            "titre_modification" => "Modification du Compte Bancaire #%id%",
            "endpoint_submit_url" => "/admin/comptebancaire/api/submit",
            "endpoint_delete_url" => "/admin/comptebancaire/api/delete",
            "endpoint_form_url" => "/admin/comptebancaire/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildCompteBancaireLayout($compteId, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildCompteBancaireLayout(int $compteId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]], ["champs" => ["banque"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["intitule"]], ["champs" => ["numero"]], ["champs" => ["codeSwift"]]]],
        ];
        $collections = [
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'compteBancaire'],
            ['fieldName' => 'paiements', 'entityRouteName' => 'paiement', 'formTitle' => 'Paiement', 'parentFieldName' => 'compteBancaire'],
        ];
        $this->addCollectionWidgetsToLayout($layout, $compteId, $isParentNew, $collections);
        return $layout;
    }
}
