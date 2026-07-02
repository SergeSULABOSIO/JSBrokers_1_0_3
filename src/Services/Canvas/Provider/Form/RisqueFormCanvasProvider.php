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
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Fiche risque",
                "description" => "Vous décrivez un produit d'assurance du catalogue : code, branche, taux de commission spécifique et régime d'imposition. Ce référentiel alimente les pistes et le calcul des revenus du courtier.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
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
        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);
        return $layout;
    }
}
