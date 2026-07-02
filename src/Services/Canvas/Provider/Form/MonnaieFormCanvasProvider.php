<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Monnaie;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class MonnaieFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Monnaie::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Monnaie $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouvelle Monnaie",
            "titre_modification" => "Modification de la Monnaie #%id%",
            "endpoint_submit_url" => "/admin/monnaie/api/submit",
            "endpoint_delete_url" => "/admin/monnaie/api/delete",
            "endpoint_form_url" => "/admin/monnaie/api/get-form",
            "isCreationMode" => $isParentNew,
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Monnaie",
                "description" => "Vous configurez une devise utilisée par l'entreprise : code, taux de change vers l'USD, format local et fonction. Elle conditionne l'affichage et la conversion des montants dans toute la gestion financière.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "nom"      => "action:edit",
                "code"     => "action:edit",
                "tauxusd"  => "action:count",
                "locale"   => "action:options",
                "fonction" => "action:options",
            ],
        ];
        $layout = $this->buildMonnaieLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildMonnaieLayout(Monnaie $object, bool $isParentNew): array
    {
        return [
            [
                "couleur_fond" => "white",
                "colonnes" => [
                    ["width" => 8, "champs" => ["nom"]],
                    ["width" => 2, "champs" => ["code"]],
                    ["width" => 2, "champs" => ["tauxusd"]],
                ]
            ],
            [
                "couleur_fond" => "white",
                "colonnes" => [
                    ["width" => 6, "champs" => ["locale"]],
                    ["width" => 6, "champs" => ["fonction"]],
                ]
            ],
        ];
    }
}