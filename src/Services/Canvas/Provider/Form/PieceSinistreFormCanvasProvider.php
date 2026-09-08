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
            // Colonne gauche du dialogue EN CRÉATION : ce que cet objet apporte,
            // et ce qu'il engage en aval. Le premier paragraphe se suffit à
            // lui-même — c'est lui qui répond à qui ouvre le dialogue sans savoir.
            "description_creation" => [
                "Une pièce est un justificatif du dossier de sinistre : c'est sur elle que l'assureur fondera son offre, ou son refus.",
                "Vous notez sa nature, sa provenance et sa date de réception. Le type de pièce dit ce qu'elle est censée établir.",
                "Un dossier incomplet part quand même chez l'assureur, mais il revient. Chaque pièce manquante retarde l'indemnisation de votre client.",
            ],
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