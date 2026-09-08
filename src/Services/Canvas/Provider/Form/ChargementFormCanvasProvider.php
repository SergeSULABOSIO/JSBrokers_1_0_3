<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Chargement;

class ChargementFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Chargement::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Chargement $object */
        $isParentNew = ($object->getId() === null);
        $chargementId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouveau Type de Chargement",
            "titre_modification" => "Modification du Type de Chargement #%id%",
            "endpoint_submit_url" => "/admin/chargement/api/submit",
            "endpoint_delete_url" => "/admin/chargement/api/delete",
            "endpoint_form_url" => "/admin/chargement/api/get-form",
            "isCreationMode" => $isParentNew,
            // Colonne gauche du dialogue EN CRÉATION : ce que cet objet apporte,
            // et ce qu'il engage en aval. Le premier paragraphe se suffit à
            // lui-même — c'est lui qui répond à qui ouvre le dialogue sans savoir.
            "description_creation" => [
                "Un type de chargement nomme une composante de la prime : prime nette, fronting, frais accessoires, taxe. C'est le vocabulaire avec lequel toutes vos cotations seront décomposées.",
                "Sa fonction n'est pas décorative : elle dit au moteur comment traiter la ligne — assiette de commission, taxe à reverser, simple frais.",
                "Cinq types sont posés à la création du cabinet. N'en ajoutez que si votre pratique l'exige : chaque type ajouté est un choix de plus à faire sur chaque cotation.",
            ],
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Type de chargement",
                "description" => "Vous définissez une composante de la prime d'assurance : son nom, sa fonction et sa description. Ces types structurent la décomposition des primes sur les cotations et servent d'assiette au calcul de certains revenus.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "documents" => "document",
                "nom"         => "action:edit",
                "fonction"    => "action:options",
                "description" => "action:description",
            ],
        ];
        $layout = $this->buildChargementLayout($chargementId, $isParentNew);

        // Pièces jointes de cette fiche.
        $collections = [
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'chargement'],
        ];
        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildChargementLayout(int $chargementId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["fonction"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
        ];
        return $layout;
    }
}