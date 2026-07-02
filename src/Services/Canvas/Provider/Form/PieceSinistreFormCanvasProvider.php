<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\PieceSinistre;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class PieceSinistreFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === PieceSinistre::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var PieceSinistre $object */
        $isParentNew = ($object->getId() === null);
        $pieceId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouvelle pièce",
            "titre_modification" => "Modification de la pièce #%id%",
            "endpoint_submit_url" => "/admin/piecesinistre/api/submit",
            "endpoint_delete_url" => "/admin/piecesinistre/api/delete",
            "endpoint_form_url" => "/admin/piecesinistre/api/get-form",
            "isCreationMode" => $isParentNew,
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Pièce du dossier sinistre",
                "description" => "Vous consignez une pièce reçue pour l'instruction du sinistre : sa description, sa source, sa date de réception et sa nature (modèle de pièce). Un dossier complet accélère l'évaluation et l'indemnisation.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "description" => "action:description",
                "fourniPar"   => "contact",
                "receivedAt"  => "action:calendar",
                "type"        => "modele-piece",
                "documents"   => "document",
            ],
        ];
        $layout = $this->buildPieceSinistreLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildPieceSinistreLayout(PieceSinistre $object, bool $isParentNew): array
    {
        $pieceId = $object->getId() ?? 0;
        $layout = [
            [
                "couleur_fond" => "white",
                "colonnes" => [
                    ["width" => 12, "champs" => ["description"]],
                ]
            ],
            [
                "couleur_fond" => "white",
                "colonnes" => [
                    ["width" => 6, "champs" => ["fourniPar"]],
                    ["width" => 6, "champs" => ["receivedAt"]],
                ]
            ],
            [
                "couleur_fond" => "white",
                "colonnes" => [
                    ["width" => 12, "champs" => ["type"]],
                ]
            ],
        ];

        $collections = [
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'pieceSinistre'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);
        return $layout;
    }
}