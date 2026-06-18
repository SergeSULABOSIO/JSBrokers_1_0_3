<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Invite;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class InviteFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Invite::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Invite $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouvelle Invitation",
            "titre_modification" => "Modification de l'Invitation #%id%",
            "endpoint_submit_url" => "/admin/invite/api/submit",
            "endpoint_delete_url" => "/admin/invite/api/delete",
            "endpoint_form_url" => "/admin/invite/api/get-form",
            "isCreationMode" => $isParentNew,
            // Active le rendu « façon page de connexion » (libellés marqués, champs hauts,
            // icône en préfixe, panneaux de section). Drapeau opt-in lu par
            // templates/components/dialog/_form_content.html.twig : seul ce dialogue est
            // concerné, les autres dialogues d'entités gardent le rendu d'origine.
            "form_style" => "auth",
            // Icône d'illustration en préfixe par code de champ (mêmes noms que la page
            // d'inscription). Seuls les champs textuels simples sont décorés.
            "field_icons" => [
                "nom" => "utilisateur",
                "email" => "mdi:email-outline",
            ],
            // Action spécifique de la rubrique (barre d'outils + menu contextuel), sur le
            // modèle des actions du Bordereau. Renvoie l'email d'invitation à l'invité à
            // tout moment, indépendamment de la création. Aucune condition : l'action est
            // toujours disponible sur une sélection unique, car Invite::getEmail() fournit
            // un destinataire valable que l'invitation soit en attente ou déjà rattachée.
            "attribute_actions" => [
                [
                    "label" => "Renvoyer l'invitation",
                    "icon"  => "action:resend-invitation",
                    "event" => "ui:invite.resend-request",
                    "url"   => "/admin/invite/api/resend-invitation/%id%",
                ],
            ],
        ];
        $layout = $this->buildInviteLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildInviteLayout(Invite $object, bool $isParentNew): array
    {
        // Ligne 1 : informations de base de l'invité (nom + email côte à côte).
        // L'email est désormais présent en création ET en édition : on le place
        // explicitement dans le layout pour qu'il soit rendu en haut, et non rejeté
        // en bas du formulaire par form_end(render_rest:true).
        $layout = [
            [
                "couleur_fond" => "white",
                "group_title" => "Invité",
                "group_icon" => "utilisateur",
                "colonnes" => [
                    ["champs" => ["nom"]],
                    ["champs" => ["email"]],
                ]
            ],
            [
                "couleur_fond" => "white",
                "group_title" => "Collaboration",
                "group_icon" => "mdi:account-multiple-outline",
                "colonnes" => [
                    ["champs" => ["assistants"]]
                ]
            ]
        ];

        // Collections de rôles, ajoutées en dernier → elles se placent en bas du
        // formulaire. En édition elles sont actives ; en création elles sont masquées
        // par le trait (d-none, cf. addCollectionWidgetsToLayout) car inutilisables sans
        // ID parent. On les ajoute dans les deux modes pour que leurs champs soient bien
        // rendus (sinon form_end(render_rest) produirait des accordéons bruts en bas).
        $collectionsConfig = [
            [
                'fieldName' => 'rolesEnFinance', 'entityRouteName' => 'rolesenfinance',
                'formTitle' => 'Rôle en Finance', 'parentFieldName' => 'invite'
            ],
            [
                'fieldName' => 'rolesEnMarketing', 'entityRouteName' => 'rolesenmarketing',
                'formTitle' => 'Rôle en Marketing', 'parentFieldName' => 'invite'
            ],
            [
                'fieldName' => 'rolesEnProduction', 'entityRouteName' => 'rolesenproduction',
                'formTitle' => 'Rôle en Production', 'parentFieldName' => 'invite'
            ],
            [
                'fieldName' => 'rolesEnSinistre', 'entityRouteName' => 'rolesensinistre',
                'formTitle' => 'Rôle en Sinistre', 'parentFieldName' => 'invite'
            ],
            [
                'fieldName' => 'rolesEnAdministration', 'entityRouteName' => 'rolesenadministration',
                'formTitle' => 'Rôle en Administration', 'parentFieldName' => 'invite'
            ],
        ];

        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collectionsConfig);

        return $layout;
    }
}