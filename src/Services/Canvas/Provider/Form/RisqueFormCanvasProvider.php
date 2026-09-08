<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Risque;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class RisqueFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Risque::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Risque $object */
        $isParentNew = ($object->getId() === null);
        $risqueId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouveau Risque",
            "titre_modification" => "Modification du Risque #%id%",
            "endpoint_submit_url" => "/admin/risque/api/submit",
            "endpoint_delete_url" => "/admin/risque/api/delete",
            "endpoint_form_url" => "/admin/risque/api/get-form",
            "isCreationMode" => $isParentNew,
            // Colonne gauche du dialogue EN CRÉATION : ce que cet objet apporte,
            // et ce qu'il engage en aval. Le premier paragraphe se suffit à
            // lui-même — c'est lui qui répond à qui ouvre le dialogue sans savoir.
            "description_creation" => [
                "Un risque est un produit d'assurance de votre catalogue : c'est ce que vous placez, et l'unité dans laquelle se mesure la couverture d'un client.",
                "Son taux de commission spécifique gouverne ce que l'affaire vous rapporte, et son régime d'imposition ce qui s'y ajoute.",
                "Quarante-trois risques sont posés à la création du cabinet, du VIE à l'IARD. N'en ajoutez que pour un produit que le catalogue ignore.",
                "C'est aussi la mesure de la saturation : couvrir un client, c'est lui faire souscrire tous les risques qui le concernent.",
            ],
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Fiche risque",
                "description" => "Vous décrivez un produit d'assurance du catalogue : code, branche, taux de commission spécifique et régime d'imposition. Ce référentiel alimente les pistes et le calcul des revenus du courtier.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "documents" => "document",
                "nomComplet"                        => "action:edit",
                "code"                              => "action:edit",
                "pourcentageCommissionSpecifiqueHT" => "action:count",
                "description"                       => "action:description",
                "branche"                           => "action:options",
                "imposable"                         => "taxe",
                "pistes"                            => "piste",
                "notificationSinistres"             => "sinistre",
            ],
        ];
        $layout = $this->buildRisqueLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildRisqueLayout(Risque $object, bool $isParentNew): array
    {
        $risqueId = $object->getId() ?? 0;
        $layout = [
            [
                "couleur_fond" => "white",
                "colonnes" => [
                    ["width" => 12, "champs" => ["nomComplet"]],
                ]
            ],
            [
                "couleur_fond" => "white",
                "colonnes" => [
                    ["width" => 6, "champs" => ["code"]],
                    ["width" => 6, "champs" => ["pourcentageCommissionSpecifiqueHT"]]
                ]
            ],
            ["couleur_fond" => "white", "colonnes" => [["width" => 12, "champs" => ["description"]]]],
            [
                "couleur_fond" => "white",
                "colonnes" => [
                    ["width" => 6, "champs" => ["branche"]],
                    ["width" => 6, "champs" => ["imposable"]]
                ]
            ],
        ];
        $collections = [
            ['fieldName' => 'pistes', 'entityRouteName' => 'piste', 'formTitle' => 'Piste', 'parentFieldName' => 'risque'],
            ['fieldName' => 'notificationSinistres', 'entityRouteName' => 'notificationsinistre', 'formTitle' => 'Sinistre', 'parentFieldName' => 'risque'],
        ];
        // Pièces jointes de cette fiche.
        $collections[] = ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'risque'];
        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);
        return $layout;
    }
}
