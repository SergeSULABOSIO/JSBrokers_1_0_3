<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\OffreIndemnisationSinistre;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class OffreIndemnisationSinistreFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === OffreIndemnisationSinistre::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var OffreIndemnisationSinistre $object */
        $isParentNew = ($object->getId() === null);
        $offreId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouvelle offre d'indemnisation",
            "titre_modification" => "Modification de l'offre #%id%",
            "endpoint_submit_url" => "/admin/offreindemnisationsinistre/api/submit",
            "endpoint_delete_url" => "/admin/offreindemnisationsinistre/api/delete",
            "endpoint_form_url" => "/admin/offreindemnisationsinistre/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildOffreIndemnisationLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildOffreIndemnisationLayout(OffreIndemnisationSinistre $object, bool $isParentNew): array
    {
        $offreId = $object->getId() ?? 0;
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["beneficiaire"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["franchiseAppliquee"]], ["champs" => ["montantPayable"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["referenceBancaire"]]]],
        ];

        $collections = [
            ['fieldName' => 'taches', 'entityRouteName' => 'tache', 'formTitle' => 'Tâche', 'parentFieldName' => 'offreIndemnisationSinistre'],
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'offreIndemnisationSinistre'],
            ['fieldName' => 'paiements', 'entityRouteName' => 'paiement', 'formTitle' => 'Paiement', 'parentFieldName' => 'offreIndemnisationSinistre', 'defaultValueConfig' => ['source' => 'montantPayable', 'target' => 'montant']],
        ];

        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);
        return $layout;
    }
}