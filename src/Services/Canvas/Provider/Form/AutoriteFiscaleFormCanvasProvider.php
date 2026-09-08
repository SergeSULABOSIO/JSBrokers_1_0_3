<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\AutoriteFiscale;

class AutoriteFiscaleFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === AutoriteFiscale::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var AutoriteFiscale $object */
        $isParentNew = ($object->getId() === null);
        $autoriteId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouvelle Autorité Fiscale",
            "titre_modification" => "Modification de l'Autorité Fiscale #%id%",
            "endpoint_submit_url" => "/admin/autoritefiscale/api/submit",
            "endpoint_delete_url" => "/admin/autoritefiscale/api/delete",
            "endpoint_form_url" => "/admin/autoritefiscale/api/get-form",
            "isCreationMode" => $isParentNew,
            // Colonne gauche du dialogue EN CRÉATION : ce que cet objet apporte,
            // et ce qu'il engage en aval. Le premier paragraphe se suffit à
            // lui-même — c'est lui qui répond à qui ouvre le dialogue sans savoir.
            "description_creation" => [
                "Une autorité fiscale est l'organisme public à qui les taxes collectées sont reversées : sans elle, une taxe se calcule sans qu'on sache à qui l'envoyer.",
                "Vous y notez son nom, son abréviation et la taxe qui lui revient.",
                "C'est à son nom que seront émises les notes de reversement, et c'est sur elle que se solde votre suivi fiscal.",
            ],
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Autorité fiscale",
                "description" => "Vous renseignez l'organisme public destinataire des taxes collectées (nom, abréviation) et la taxe qui lui est rattachée. Ces informations servent à l'émission des notes de reversement des taxes.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "documents" => "document",
                "nom"         => "action:edit",
                "abreviation" => "action:edit",
                "taxe"        => "taxe",
            ],
        ];
        $layout = $this->buildAutoriteFiscaleLayout($autoriteId, $isParentNew);

        // Pièces jointes de cette fiche.
        $collections = [
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'autoriteFiscale'],
        ];
        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildAutoriteFiscaleLayout(int $autoriteId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]], ["champs" => ["abreviation"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["taxe"]]]],
        ];
        return $layout;
    }
}