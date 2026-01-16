<?php

namespace App\Services\Canvas;

use App\Entity\Avenant;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\TypeRevenu;
use App\Services\Canvas\Provider\Form\FormCanvasProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

class FormCanvasProvider
{
    /**
     * @var FormCanvasProviderInterface[]
     */
    private iterable $providers;

    public function __construct(
        #[TaggedIterator('app.form_canvas_provider')] iterable $providers
    ) {
        $this->providers = $providers;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        $entityClassName = get_class($object);

        foreach ($this->providers as $provider) {
            if ($provider->supports($entityClassName)) {
                return $provider->getCanvas($object, $idEntreprise);
            }
        }

        $isParentNew = ($object->getId() === null);
        $layout = [];
        $parametres = [];

        switch ($entityClassName) {
            case TypeRevenu::class:
                $typeRevenuId = $object->getId() ?? 0;
                $parametres = [
                    "titre_creation" => "Nouveau Type de Revenu",
                    "titre_modification" => "Modification du Type de Revenu #%id%",
                    "endpoint_submit_url" => "/admin/typerevenu/api/submit",
                    "endpoint_delete_url" => "/admin/typerevenu/api/delete",
                    "endpoint_form_url" => "/admin/typerevenu/api/get-form",
                    "isCreationMode" => $isParentNew
                ];
                $layout = $this->buildTypeRevenuLayout($typeRevenuId, $isParentNew);
                break;

            case Invite::class:
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
                break;

            default:
                return [];
        }

        // Si aucune configuration n'a été trouvée, on retourne un tableau vide.
        if (empty($parametres) && empty($layout)) {
            return [];
        }

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout) // Ajout de la carte des champs pour un accès optimisé
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