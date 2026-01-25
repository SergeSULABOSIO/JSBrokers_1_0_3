<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Client;

class ClientFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Client::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Client $object */
        $isParentNew = ($object->getId() === null);
        $clientId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouveau Client",
            "titre_modification" => "Modification du Client #%id%",
            "endpoint_submit_url" => "/admin/client/api/submit",
            "endpoint_delete_url" => "/admin/client/api/delete",
            "endpoint_form_url" => "/admin/client/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildClientLayout($clientId, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildClientLayout(int $clientId, bool $isParentNew): array
    {
        // NOUVEAU : On définit la condition de visibilité une seule fois pour la réutiliser.
        $visibilityConditionForLegalFields = [
            'visibility_conditions' => [
                [
                    'field' => 'civilite', // Le champ à écouter
                    'operator' => 'in',    // L'opérateur de comparaison
                    'value' => [Client::CIVILITE_ENTREPRISE, Client::CIVILITE_ASBL] // Les valeurs qui déclenchent la visibilité
                ]
            ]
        ];

        $layout = [
            // Ligne 1: "civilité" (1/3), "nom" (2/3)
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["civilite"], "width" => 4], ["champs" => ["nom"], "width" => 8]]],
            // Ligne 2: "email", "telephone", "groupe"
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["email"]], ["champs" => ["telephone"]], ["champs" => ["groupe"]]]],
            // Ligne 3: "adresse" (2/3), "exonere" (1/3)
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["adresse"], "width" => 8], ["champs" => ["exonere"], "width" => 4]]],
            // Ligne 4: "numimpot", "rccm", "idnat" - MAINTENANT DYNAMIQUES
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => [array_merge(['field_code' => 'numimpot'], $visibilityConditionForLegalFields)]],
                ["champs" => [array_merge(['field_code' => 'rccm'], $visibilityConditionForLegalFields)]],
                ["champs" => [array_merge(['field_code' => 'idnat'], $visibilityConditionForLegalFields)]]
            ]],
        ];

        $collections = [
            // Ligne 5: "Contacts"
            ['fieldName' => 'contacts', 'entityRouteName' => 'contact', 'formTitle' => 'Contact', 'parentFieldName' => 'client'],
            // Ligne 6: "Partenaires"
            ['fieldName' => 'partenaires', 'entityRouteName' => 'partenaire', 'formTitle' => 'Partenaire', 'parentFieldName' => 'client'],
            // Ligne 7: "Documents"
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'client'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $clientId, $isParentNew, $collections);
        return $layout;
    }
}