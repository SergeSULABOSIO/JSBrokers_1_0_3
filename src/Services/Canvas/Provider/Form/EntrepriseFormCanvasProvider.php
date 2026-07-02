<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Entreprise;

class EntrepriseFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Entreprise::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Entreprise $object */
        $isParentNew = ($object->getId() === null);
        $entrepriseId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouvelle Entreprise",
            "titre_modification" => "Modification de l'Entreprise #%id%",
            "endpoint_submit_url" => "/admin/entreprise/api/submit",
            "endpoint_delete_url" => "/admin/entreprise/api/delete",
            "endpoint_form_url" => "/admin/entreprise/api/get-form",
            "isCreationMode" => $isParentNew,
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Entreprise de courtage",
                "description" => "Vous renseignez l'identité de l'entreprise : sa dénomination et son numéro de licence d'exploitation. Ces informations identifient l'espace de travail et figurent sur les documents produits par la plateforme.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "nom"     => "entreprise",
                "licence" => "action:premium",
            ],
        ];
        $layout = $this->buildEntrepriseLayout($entrepriseId, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildEntrepriseLayout(int $entrepriseId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["licence"]]]],
        ];
        return $layout;
    }
}