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
            "isCreationMode" => $isParentNew
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
