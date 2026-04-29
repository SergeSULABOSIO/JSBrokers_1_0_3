<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Bordereau;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class BordereauFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Bordereau::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Bordereau $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouveau Bordereau",
            "titre_modification" => "Modification du Bordereau #%id%",
            "endpoint_submit_url" => "/admin/bordereau/api/submit",
            "endpoint_delete_url" => "/admin/bordereau/api/delete",
            "endpoint_form_url" => "/admin/bordereau/api/get-form",
            "isCreationMode" => $isParentNew,
        ];
        $layout = $this->buildBordereauLayout($object, $isParentNew); // buildBordereauLayout ne prend plus idEntreprise

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildBordereauLayout(Bordereau $object, bool $isParentNew): array // Signature alignée sur NoteFormCanvasProvider
    {
        $bordereauId = $object->getId() ?? 0;
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["assureur"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["reference"], "width" => 6], ["champs" => ["type"], "width" => 6]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["periodeDebut"], "width" => 6], ["champs" => ["periodeFin"], "width" => 6]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["receivedAt"], "width" => 6], ["champs" => ["paidAt"], "width" => 6]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["montantCommissionHT"], "width" => 6], ["champs" => ["montantTaxe"], "width" => 6]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["statut"]]]],
        ];
        $collections = [
            [
                'fieldName' => 'operations', 
                'entityRouteName' => 'operation', 
                'formTitle' => 'Opération', 
                'parentFieldName' => 'bordereau',
                'totalizableField' => 'montantTTC', // Champ à totaliser
                'secondaryField' => 'referencePolice', // Champ secondaire à afficher
                'secondaryLabel' => 'Police: ' // Label pour le champ secondaire
            ],
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'bordereau']
        ];

        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);

        return $layout;
    }
}