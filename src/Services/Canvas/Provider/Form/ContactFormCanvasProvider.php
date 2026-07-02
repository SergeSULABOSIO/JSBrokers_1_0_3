<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Contact;

class ContactFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Contact::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Contact $object */
        $isParentNew = ($object->getId() === null);
        $contactId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouveau contact",
            "titre_modification" => "Modification du contact #%id%",
            "endpoint_submit_url" => "/admin/contact/api/submit",
            "endpoint_delete_url" => "/admin/contact/api/delete",
            "endpoint_form_url" => "/admin/contact/api/get-form",
            "isCreationMode" => $isParentNew,
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Fiche contact",
                "description" => "Vous identifiez un interlocuteur chez le client : nom, coordonnées et fonction. Un carnet de contacts à jour accélère les échanges lors des cotations, des renouvellements et des sinistres.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "nom"       => "action:edit",
                "email"     => "contact",
                "telephone" => "contact",
                "fonction"  => "role",
                "type"      => "action:options",
            ],
        ];
        $layout = $this->buildContactLayout($contactId, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildContactLayout(int $contactId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["email"]], ["champs" => ["telephone"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["fonction"]], ["champs" => ["type"]]]],
        ];

        return $layout;
    }
}
