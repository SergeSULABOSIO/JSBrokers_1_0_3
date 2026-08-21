<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

class InviteFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em,
        private Security $security,
        private WorkspaceAccessResolver $accessResolver
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
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Invitation d'un collaborateur",
                "description" => "Vous invitez un collaborateur à rejoindre l'espace de travail de l'entreprise et définissez son périmètre : assistants rattachés, délégation éventuelle et rôles par module. L'invité n'accède qu'aux données couvertes par les droits accordés ici.",
            ],
            // Titre de carte, pour les champs dont le WIDGET porte déjà le libellé : une case
            // à cocher l'affiche à côté d'elle, si bien que le bandeau de la carte restait
            // vide — une pastille sans titre, que rien n'expliquait.
            "field_titles" => [
                "gestionnaireInvites" => "Visibilité sur les invités et les rôles",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "documents" => "document",
                "conditionsPartageAgent" => "condition",
                "nom" => "utilisateur",
                "email" => "mdi:email-outline",
                "assistants" => "invite",
                "gestionnaireInvites" => "action:role",
                "rolesEnFinance" => "role",
                "rolesEnMarketing" => "role",
                "rolesEnProduction" => "role",
                "rolesEnSinistre" => "role",
                "rolesEnAdministration" => "role",
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
                // RÉTROCOMMISSION DE L'AGENT — visibles seulement si quelque chose est
                // EXIGIBLE, c'est-à-dire réclamable aujourd'hui : le cabinet a encaissé sa
                // commission, la dette envers l'agent est née. Un dû non encore encaissé
                // n'ouvre aucune de ces deux portes — proposer d'ouvrir un sélecteur de
                // reversement qui n'aurait aucune ligne à montrer serait une impasse.
                //
                // La condition porte sur `hasRetroAgentExigible`, une propriété DÉCLARÉE de
                // l'entité (groupe list:read) : c'est ce qui la fait voyager jusqu'à la ligne
                // de liste, donc jusqu'à la BARRE D'OUTILS et au MENU CONTEXTUEL, et pas
                // seulement jusqu'à la fiche ouverte.
                [
                    "label"     => "Voir le rapport de production",
                    "icon"      => "action:view",
                    "event"     => "ui:retroagent.rapport-request",
                    "url"       => "/admin/retro-agent/%id%/rapport",
                    "condition" => ["field" => "hasRetroAgentExigible", "value" => true],
                ],
                [
                    "label"     => "Signaler un reversement de rétrocommission",
                    "icon"      => "depense",
                    "event"     => "ui:retroagent.reversement-request",
                    "url"       => "/admin/retro-agent/%id%/reversement-picker",
                    "condition" => ["field" => "hasRetroAgentExigible", "value" => true],
                ],
                // Actions « portefeuille » conditionnelles, sur le modèle des actions
                // linked-note du Bordereau : la condition est évaluée côté front contre
                // l'attribut calculé hasPortefeuille (InviteIndicatorStrategy). Ajout et
                // Édition partagent le même endpoint : le backend répond selon l'état
                // réel du portefeuille (la condition n'est qu'un confort UI).
                [
                    "label"     => "Ajouter un portefeuille",
                    "icon"      => "portefeuille",
                    "event"     => "ui:invite.portefeuille-form-request",
                    "url"       => "/admin/invite/api/get-portefeuille-context/%id%",
                    "condition" => ["field" => "hasPortefeuille", "value" => false],
                ],
                [
                    "label"     => "Éditer le portefeuille",
                    "icon"      => "action:edit",
                    "event"     => "ui:invite.portefeuille-form-request",
                    "url"       => "/admin/invite/api/get-portefeuille-context/%id%",
                    "condition" => ["field" => "hasPortefeuille", "value" => true],
                ],
                [
                    "label"     => "Supprimer le portefeuille",
                    "icon"      => "action:delete",
                    "event"     => "ui:invite.delete-portefeuille",
                    // Pas de %id% : l'id de l'invité est transmis en `ids` par le flux
                    // générique app:api.delete-request après confirmation.
                    "url"       => "/admin/invite/api/delete-portefeuille",
                    "condition" => ["field" => "hasPortefeuille", "value" => true],
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
                "colonnes" => [
                    ["champs" => ["nom"]],
                    ["champs" => ["email"]],
                ]
            ],
            [
                "couleur_fond" => "white",
                "colonnes" => [
                    ["champs" => ["assistants"]]
                ]
            ]
        ];

        // Case « Gestionnaire des invités » : rendue uniquement pour le PROPRIÉTAIRE de
        // l'entreprise (le champ n'existe dans InviteType que pour lui — cohérence UI/form).
        $user = $this->security->getUser();
        if ($user instanceof Utilisateur && $this->accessResolver->isOwnerOfConnected($user)) {
            $layout[] = [
                "couleur_fond" => "white",
                "colonnes" => [
                    ["champs" => ["gestionnaireInvites"]]
                ]
            ];
        }

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

        // Rémunération de l'agent sur les affaires qu'il apporte. `parentFieldName` =
        // `agent` : le ManyToOne par lequel la condition désigne son bénéficiaire, donc
        // celui que le trait injecte à la création depuis cette fiche.
        $collectionsConfig[] = [
            'fieldName' => 'conditionsPartageAgent', 'entityRouteName' => 'conditionpartage',
            'formTitle' => 'Condition de partage', 'parentFieldName' => 'agent',
            'parentRouteName' => 'invite',
        ];

        // Pièces jointes de cette fiche.
        //
        // Le widget était ajouté à `$collections`, une variable qui n'existe nulle part
        // ailleurs, alors que la méthode reçoit `$collectionsConfig` : la collection
        // « Documents » de la fiche Invité était donc déclarée dans le FormType mais
        // jamais rendue à l'écran.
        $collectionsConfig[] = ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'inviteRattache', 'parentRouteName' => 'invite'];
        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collectionsConfig);

        return $layout;
    }
}