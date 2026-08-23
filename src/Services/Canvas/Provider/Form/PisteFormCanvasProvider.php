<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Piste;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class PisteFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Piste::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Piste $object */
        $isParentNew = ($object->getId() === null);
        $pisteId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouvelle Piste",
            "titre_modification" => "Modification de la Piste #%id%",
            "endpoint_submit_url" => "/admin/piste/api/submit",
            "endpoint_delete_url" => "/admin/piste/api/delete",
            "endpoint_form_url" => "/admin/piste/api/get-form",
            "isCreationMode" => $isParentNew,
            // Picker de documents générique (client + piste + cotations + polices).
            "attribute_actions" => [
                // ── L'EFFORT COMMERCIAL D'UN AGENT INTERNE ────────────────────────────
                //
                // Le rattachement s'ÉCRIT toujours sur la PISTE : c'est la règle métier, et
                // elle ne bouge pas. Ce qui change, c'est qu'on peut l'ORDONNER d'ici —
                // parce que c'est d'ici qu'on travaille. Le serveur remonte l'arbre
                // (EffortCommercialAgent::piste) et écrit au seul endroit légitime.
                //
                // `multi` sur le rattachement : on couvre une sélection entière d'un geste.
                // ⚠ La condition d'une action `multi` n'est évaluée que sur la PREMIÈRE
                // ligne (toolbar_controller) : le bouton peut donc s'afficher alors qu'une
                // ligne plus bas est déjà prise. Le contrôle « toutes libres » est SERVEUR,
                // et c'est là qu'il doit être — ce gating-ci reste cosmétique.
                [
                    "label"        => "Rattacher une condition de partage",
                    "icon"         => "partenaire",
                    "groupe"       => "Partage",
                    "groupe_icone" => "partenaire",
                    "event"        => "ui:partage.picker-request",
                    "url"          => "/admin/partage/piste/conditions-picker",
                    "multi"        => true,
                    "condition"    => ["field" => "effortCommercialAgent", "present" => false],
                ],
                [
                    "label"        => "Détacher la condition de partage",
                    "icon"         => "action:detach",
                    "groupe"       => "Partage",
                    "groupe_icone" => "partenaire",
                    "event"        => "ui:partage.detach-request",
                    "url"          => "/admin/partage/piste/%id%/detacher",
                    "condition"    => ["field" => "effortCommercialAgent", "present" => true],
                ],
                [
                    "label" => "Voir les documents",
                    "icon"  => "classeur",
                    "event" => "ui:soa.docs-picker-request",
                    "url"   => "/admin/soa/api/documents/piste/%id%",
                ],
            ],
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Fiche piste",
                "description" => "Vous qualifiez une opportunité commerciale : client, risque visé, potentiel de prime et de commission. Vous y désignez aussi qui se partage cette commission — l'intermédiaire qui a apporté l'affaire, les agents du cabinet rémunérés dessus, et les conditions qui remplacent leurs taux habituels pour cette affaire seulement.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "nom"                              => "action:edit",
                "descriptionDuRisque"              => "action:description",
                "client"                           => "client",
                "risque"                           => "risque",
                "typeAvenant"                      => "avenant",
                "renewalCondition"                 => "action:renew",
                "exercice"                         => "action:calendar",
                "primePotentielle"                 => "action:count",
                "commissionPotentielle"            => "action:count",
                "partenaire"                       => "partenaire",
                "conditionsPartageAgent"           => "utilisateur",
                "conditionsPartageExceptionnelles" => "condition",
                "cotations"                        => "cotation",
                "taches"                           => "tache",
                "documents"                        => "document",
            ],
        ];
        $layout = $this->buildPisteLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout),
            "idEntreprise" => $idEntreprise,
            "idInvite" => $object->getInvite()?->getId(),
        ];
    }

    private function buildPisteLayout(Piste $object, bool $isParentNew): array
    {
        $pisteId = $object->getId() ?? 0;
        $layout = [
            [
                'colonnes' => [
                    ['width' => 12, 'champs' => ['nom']]
                ]
            ],
            [
                'colonnes' => [
                    ['width' => 12, 'champs' => ['descriptionDuRisque']]
                ]
            ],
            // LE CLIENT NE SE REDEMANDE PAS EN MODIFICATION.
            //
            // Une affaire appartient à un client dès sa création, et on ne rouvre pas sa
            // fiche pour en changer : on y travaille les autres champs. Laisser le champ
            // occuper toute une rangée en tête — développé en carte, avec les chiffres du
            // client — c'est poser une question dont la réponse est déjà donnée, avant
            // celles qui comptent. Il reste rendu, donc porteur de sa valeur, mais masqué,
            // et le client est rappelé parmi les faits en tête du dialogue.
            [
                'hidden' => !$isParentNew,
                'colonnes' => [
                    ['width' => 12, 'champs' => ['client']]
                ]
            ],
            [
                'colonnes' => [
                    ['width' => 12, 'champs' => ['risque']]
                ]
            ],
            [
                'colonnes' => [
                    ['width' => 6, 'champs' => ['typeAvenant']],
                    ['width' => 6, 'champs' => ['renewalCondition']]
                ]
            ],
            [
                'colonnes' => [
                    ['width' => 3, 'champs' => ['exercice']],
                    ['width' => 5, 'champs' => ['primePotentielle']],
                    ['width' => 4, 'champs' => ['commissionPotentielle']]
                ]
            ],
            // LE PARTAGE DES REVENUS, D'UN SEUL TENANT.
            //
            // Trois blocs traitent du même sujet — qui se partage la commission — et se
            // suivaient sans que rien ne le dise. L'intertitre les rassemble, et les deux
            // familles de bénéficiaires se lisent côte à côte : l'externe et l'interne.
            // Les conditions propres à l'affaire, elles, restent en dessous : elles ne
            // désignent pas un bénéficiaire, elles modifient une règle.
            //
            // Les champs sont explicitement dans le layout — sans quoi ils tomberaient en
            // bas du formulaire via render_rest, là où personne ne les cherche.
            [
                'group_title' => "Partage des revenus de cette affaire",
                'colonnes' => [
                    ['width' => 6, 'champs' => ['partenaire']],
                    ['width' => 6, 'champs' => ['conditionsPartageAgent']],
                ]
            ],
        ];

        $collections = [
            [
                'fieldName' => 'conditionsPartageExceptionnelles',
                'entityRouteName' => 'conditionpartage',
                'formTitle' => 'Conditions de partage',
                'parentFieldName' => 'piste',
                // SANS BÉNÉFICIAIRE, CE BLOC N'A AUCUN OBJET.
                //
                // Une affaire sans intermédiaire ET sans agent rémunéré n'a personne à qui
                // rétrocéder : y proposer des conditions de partage demande à l'utilisateur
                // de comprendre seul pourquoi rien n'y produit d'effet. Le bloc reparaît dès
                // qu'un bénéficiaire est désigné, sans recharger la fiche.
                'visibility_conditions' => [[
                    'operator' => 'any',
                    'conditions' => [
                        ['field' => 'partenaire', 'operator' => 'not_empty'],
                        ['field' => 'conditionsPartageAgent', 'operator' => 'not_empty'],
                    ],
                ]],
            ],
            ['fieldName' => 'cotations', 'entityRouteName' => 'cotation', 'formTitle' => 'Cotation', 'parentFieldName' => 'piste', 'totalizableField' => 'primeTotale'],
            ['fieldName' => 'taches', 'entityRouteName' => 'tache', 'formTitle' => 'Tâches', 'parentFieldName' => 'piste'],
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'piste'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);
        return $layout;
    }
}
