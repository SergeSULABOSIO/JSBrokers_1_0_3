<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Client;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class ClientFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

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
        $layout = $this->buildClientLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildClientLayout(Client $object, bool $isParentNew): array
    {
        $clientId = $object->getId() ?? 0;
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
            // Ligne 1: "civilité" (full width)
            [
                // "couleur_fond" => "white", 
                "colonnes" => [["champs" => ["civilite"]]]
            ],
            // Ligne 2: "nom" (full width)
            [
                "colonnes" => [["champs" => ["nom"]]]
            ],
            // Ligne 3: "groupe" (full width)
            [
                // "couleur_fond" => "white", 
                "colonnes" => [["champs" => ["groupe"]]]
            ],
            // Ligne 4: "adresse" (full width)
            [
                // "couleur_fond" => "white", 
                "colonnes" => [["champs" => ["adresse"]]]
            ],
            // Ligne 5: "email", "telephone" (1/2 each)
            [
                // "couleur_fond" => "white", 
                "colonnes" => [["champs" => ["email"], "width" => 6], ["champs" => ["telephone"], "width" => 6]]
            ],
            // Ligne 6: "exonere" (full width)
            [
                // "couleur_fond" => "white", 
                "colonnes" => [["champs" => ["exonere"]]]
            ],
            // Ligne 7: "numimpot", "rccm", "idnat" (1/3 each) - Conditional
            [
                // "couleur_fond" => "white", 
                "colonnes" => [
                    ["champs" => [array_merge(['field_code' => 'numimpot'], $visibilityConditionForLegalFields)], "width" => 4],
                    ["champs" => [array_merge(['field_code' => 'rccm'], $visibilityConditionForLegalFields)], "width" => 4],
                    ["champs" => [array_merge(['field_code' => 'idnat'], $visibilityConditionForLegalFields)], "width" => 4]
                ]
            ],
            // Ligne 8: "partenaires"
            [
                // "couleur_fond" => "white", 
                "colonnes" => [["champs" => ["partenaires"]]]
            ],
        ];

        $collections = [
            // Ligne 9: "Contacts"
            ['fieldName' => 'contacts', 'entityRouteName' => 'contact', 'formTitle' => 'Contact', 'parentFieldName' => 'client'],
            // Ligne 9: "Documents"
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'client'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);
        return $layout;
    }
}