<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Operation;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class OperationFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Operation::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Operation $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouvelle Opération",
            "titre_modification" => "Modification de l'Opération #%id%",
            "endpoint_submit_url" => "/admin/operation/api/submit",
            "endpoint_delete_url" => "/admin/operation/api/delete",
            "endpoint_form_url" => "/admin/operation/api/get-form",
            "isCreationMode" => $isParentNew,
            // Colonne gauche du dialogue EN CRÉATION : ce que cet objet apporte,
            // et ce qu'il engage en aval. Le premier paragraphe se suffit à
            // lui-même — c'est lui qui répond à qui ouvre le dialogue sans savoir.
            "description_creation" => [
                "Une opération est une ligne du bordereau : c'est le grain auquel se fait le rapprochement avec votre production.",
                "Elle nomme la police et l'avenant concernés, et porte les montants hors taxe et de taxe déclarés par l'assureur.",
                "C'est en comparant ces lignes à vos propres avenants qu'on voit ce que la compagnie a oublié de vous verser.",
            ],
            // Pas d'actions spécifiques pour les opérations pour l'instant
            "attribute_actions" => [],
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Opération",
                "description" => "Vous détaillez une ligne d'un bordereau : police concernée, numéro d'avenant et montants hors taxe et de taxe. Ces opérations alimentent l'analyse du bordereau et le rapprochement avec la production.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "documents" => "document",
                "referencePolice" => "action:edit",
                "numeroAvenant"   => "avenant",
                "montantHT"       => "action:count",
                "montantTaxe"     => "taxe",
            ],
        ];
        $layout = $this->buildOperationLayout($object, $isParentNew);

        // Pièces jointes de cette fiche.
        $collections = [
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'operation'],
        ];
        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout),
        ];
    }

    private function buildOperationLayout(Operation $object, bool $isParentNew): array
    {
        $layout = [
            ["colonnes" => [
                ["champs" => ["referencePolice"], "width" => 8],
                ["champs" => ["numeroAvenant"], "width" => 4]
            ]],
            ["colonnes" => [["champs" => ["montantHT"], "width" => 6], ["champs" => ["montantTaxe"], "width" => 6]]],
        ];

        return $layout;
    }
}