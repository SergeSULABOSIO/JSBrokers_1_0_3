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
            // Colonne gauche du dialogue EN CRÉATION : ce que cet objet apporte,
            // et ce qu'il engage en aval. Le premier paragraphe se suffit à
            // lui-même — c'est lui qui répond à qui ouvre le dialogue sans savoir.
            "description_creation" => [
                "L'entreprise est votre cabinet de courtage : c'est l'espace de travail dans lequel vivent vos clients, vos polices et vos commissions.",
                "Sa dénomination et sa licence l'identifient, et figurent sur tous les documents que la plateforme produit en votre nom.",
                "Créer une entreprise, c'est ouvrir un espace entièrement cloisonné : rien n'y transite depuis un autre cabinet.",
            ],
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