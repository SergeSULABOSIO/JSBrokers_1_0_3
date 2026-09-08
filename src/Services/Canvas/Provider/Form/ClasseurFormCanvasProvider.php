<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Classeur;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class ClasseurFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Classeur::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Classeur $object */
        $isParentNew = ($object->getId() === null);
        $classeurId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouveau Classeur",
            "titre_modification" => "Modification du Classeur #%id%",
            "endpoint_submit_url" => "/admin/classeur/api/submit",
            "endpoint_delete_url" => "/admin/classeur/api/delete",
            "endpoint_form_url" => "/admin/classeur/api/get-form",
            "isCreationMode" => $isParentNew,
            // Colonne gauche du dialogue EN CRÉATION : ce que ce paramètre apporte
            // au cabinet. Le premier paragraphe sert aussi de « pourquoi » sur la
            // carte du guide de démarrage — un seul texte, deux surfaces.
            "description_creation" => [
                "Un classeur range les documents du cabinet. Sans lui, les pièces s'accumulent sans ordre et deviennent introuvables au moment où elles comptent.",
                "Chaque client reçoit automatiquement son propre classeur à sa création. Ceux que vous créez ici servent au reste : documents internes, contrats-cadres, agréments, correspondance avec les compagnies.",
                "Un document mal classé est un document perdu — et en assurance, une pièce absente lors d'un sinistre coûte cher.",
            ],
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Classeur de documents",
                "description" => "Vous organisez ici un classeur : son nom, sa description et les documents qu'il regroupe. Un classement rigoureux facilite la recherche des pièces administratives et contractuelles du cabinet.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "nom"         => "action:edit",
                "description" => "action:description",
                "documents"   => "document",
            ],
        ];
        $layout = $this->buildClasseurLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildClasseurLayout(Classeur $object, bool $isParentNew): array
    {
        $classeurId = $object->getId() ?? 0;
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
        ];
        $collections = [['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'classeur']];
        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);
        return $layout;
    }
}