<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Portefeuille;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class PortefeuilleFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Portefeuille::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Portefeuille $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouveau Portefeuille",
            "titre_modification" => "Modification du Portefeuille #%id%",
            "endpoint_submit_url" => "/admin/portefeuille/api/submit",
            "endpoint_delete_url" => "/admin/portefeuille/api/delete",
            "endpoint_form_url" => "/admin/portefeuille/api/get-form",
            "isCreationMode" => $isParentNew,
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Fiche portefeuille",
                "description" => "Vous constituez un portefeuille client et désignez son gestionnaire de compte (un invité de l'espace de travail). Rattachez-y les clients concernés : vous pourrez ensuite filtrer pistes, propositions et avenants par portefeuille pour suivre le volume d'activité de chaque collaborateur.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "nom"          => "action:edit",
                "gestionnaire" => "invite",
                "clients"      => "client",
            ],
        ];

        $layout = [
            [
                "couleur_fond" => "white",
                "colonnes" => [
                    ["width" => 12, "champs" => ["nom"]],
                ],
            ],
            [
                "couleur_fond" => "white",
                "colonnes" => [
                    ["width" => 12, "champs" => ["gestionnaire"]],
                ],
            ],
        ];

        // Clients rattachés : widget COLLECTION paginé (comme les collections d'une cotation),
        // adapté aux portefeuilles de plusieurs dizaines de clients. Le « retrait » d'un client
        // pointe vers l'action de détachement (client.portefeuille = null), et non vers la
        // suppression du client (qui est une entité partagée).
        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, [
            [
                'fieldName'       => 'clients',
                'entityRouteName' => 'client',
                'formTitle'       => 'Client',
                'parentFieldName' => 'portefeuille',
                'itemDeleteUrl'   => '/admin/portefeuille/api/%parentId%/detach-client',
            ],
        ]);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout),
        ];
    }
}
