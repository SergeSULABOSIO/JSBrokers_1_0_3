<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Invite;

class InviteFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Invite::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Invite $object */
        $isParentNew = ($object->getId() === null);
        $inviteId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouvelle Invitation",
            "titre_modification" => "Modification de l'Invitation #%id%",
            "endpoint_submit_url" => "/admin/invite/api/submit",
            "endpoint_delete_url" => "/admin/invite/api/delete",
            "endpoint_form_url" => "/admin/invite/api/get-form",
            "isCreationMode" => $isParentNew
        ];
        $layout = $this->buildInviteLayout($inviteId, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildInviteLayout(int $inviteId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]], ["champs" => ["email"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["isVerified"]]]],
        ];
        return $layout;
    }
}