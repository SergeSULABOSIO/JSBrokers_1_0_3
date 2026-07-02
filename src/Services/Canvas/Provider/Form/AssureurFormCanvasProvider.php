<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Assureur;

class AssureurFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Assureur::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Assureur $object */
        $isParentNew = ($object->getId() === null);
        $assureurId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouvel Assureur",
            "titre_modification" => "Modification de l'Assureur #%id%",
            "endpoint_submit_url" => "/admin/assureur/api/submit",
            "endpoint_delete_url" => "/admin/assureur/api/delete",
            "endpoint_form_url" => "/admin/assureur/api/get-form",
            "isCreationMode" => $isParentNew,
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Fiche assureur",
                "description" => "Vous renseignez l'identité, les coordonnées et les références légales de la compagnie d'assurance. Ces informations servent de référence pour les cotations, les polices et la facturation liées à cet assureur.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "nom"             => "action:edit",
                "telephone"       => "contact",
                "adressePhysique" => "contact",
                "url"             => "action:open",
                "email"           => "contact",
                "numimpot"        => "taxe",
                "rccm"            => "action:edit",
                "idnat"           => "action:edit",
            ],
        ];
        $layout = $this->buildAssureurLayout($assureurId, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildAssureurLayout(int $assureurId, bool $isParentNew): array
    {
        $layout = [
            // Ligne 1: le nom
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            // Ligne 2: le téléphone
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["telephone"]]]],
            // Ligne 2: adressePhysique
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["adressePhysique"]]]],
            // Ligne 3: url (1/2) et email (1/2)
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["url"], "width" => 6],
                ["champs" => ["email"], "width" => 6]
            ]],
            // Ligne 4: numimpot (1/3), rccm (1/3) et idnat (1/3)
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["numimpot"], "width" => 4],
                ["champs" => ["rccm"], "width" => 4],
                ["champs" => ["idnat"], "width" => 4]
            ]],
        ];

        return $layout;
    }
}
