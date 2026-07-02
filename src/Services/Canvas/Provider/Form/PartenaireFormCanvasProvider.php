<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Partenaire;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class PartenaireFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Partenaire::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Partenaire $object */
        $isParentNew = ($object->getId() === null);
        $partenaireId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouveau Partenaire",
            "titre_modification" => "Modification du Partenaire #%id%",
            "endpoint_submit_url" => "/admin/partenaire/api/submit",
            "endpoint_delete_url" => "/admin/partenaire/api/delete",
            "endpoint_form_url" => "/admin/partenaire/api/get-form",
            "isCreationMode" => $isParentNew,
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Fiche partenaire",
                "description" => "Vous enregistrez un apporteur d'affaires : coordonnées, part de rétrocession par défaut et conditions de partage particulières. Ces éléments déterminent la répartition des commissions sur les affaires apportées.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "nom"               => "action:edit",
                "email"             => "contact",
                "telephone"         => "contact",
                "part"              => "action:count",
                "conditionPartages" => "condition",
                "documents"         => "document",
            ],
        ];
        $layout = $this->buildPartenaireLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildPartenaireLayout(Partenaire $object, bool $isParentNew): array
    {
        $partenaireId = $object->getId() ?? 0;
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["email"]], ["champs" => ["telephone"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["part"]]]],
        ];
        $collections = [
            ['fieldName' => 'conditionPartages', 'entityRouteName' => 'conditionpartage', 'formTitle' => 'Condition de partage', 'parentFieldName' => 'partenaire'],
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'partenaire'],
        ];
        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);
        return $layout;
    }
}