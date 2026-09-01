<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\ParametresConge;

/**
 * Le dialogue des réglages de congés.
 *
 * ── LA CRÉATION EST REFUSÉE, ET LE DIT ──────────────────────────────────────────────
 * Un cabinet n'a qu'un jeu de réglages. Deux jeux concurrents, ce serait un contrôle qui
 * s'applique ou non selon la ligne qu'on a lue — et le désaccord ne se verrait qu'au
 * moment où une demande passe alors qu'elle aurait dû être refusée. La ligne est créée
 * d'office par le contrôleur au premier affichage ; il n'y a donc rien à ajouter.
 */
class ParametresCongeFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ParametresConge::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var ParametresConge $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Paramètres des congés",
            "titre_modification" => "Paramètres des congés",
            "endpoint_submit_url" => "/admin/parametresconge/api/submit",
            "endpoint_delete_url" => "/admin/parametresconge/api/delete",
            "endpoint_form_url" => "/admin/parametresconge/api/get-form",
            "isCreationMode" => $isParentNew,
            "creation_interdite" => true,
            "creation_interdite_message" => "Un cabinet n'a qu'un seul jeu de réglages, déjà créé. Ouvrez-le pour le modifier.",
            "form_intro" => [
                "titre" => "Ce que le cabinet exige d'une demande",
                "description" => "Ces réglages gouvernent les contrôles de la soumission. Chacun se désactive franchement — un préavis à zéro, un plafond vide — parce qu'une règle qu'on ne veut pas finit toujours par être contournée. Un valideur, lui, peut passer outre le préavis, le plafond et les périodes de blocage : le contournement est alors consigné sur la demande et signalé dans l'e-mail.",
            ],
            "suppression_note" => "Supprimer les réglages fait retomber le cabinet sur les valeurs par défaut et efface ses périodes de blocage. Pour désactiver un contrôle, mettez-le plutôt à zéro.",
            "field_icons" => [
                "delaiPreavisJours"    => "action:calendar",
                "maxAbsentsSimultanes" => "invite",
                "relanceApresJours"    => "action:alert",
                "dotationAnnuelle"     => "action:count",
                "seuilAlerteReport"    => "action:alert",
                "periodesBlocage"      => "action:resiliation",
            ],
        ];

        $layout = $this->buildParametresCongeLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout),
        ];
    }

    private function buildParametresCongeLayout(ParametresConge $object, bool $isParentNew): array
    {
        $layout = [
            // Les contrôles de la SOUMISSION d'abord : ce sont ceux qu'un collaborateur
            // rencontre, et donc ceux qu'on vient régler.
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["delaiPreavisJours"], 'width' => 6],
                ["champs" => ["maxAbsentsSimultanes"], 'width' => 6],
            ]],
            // Puis ce qui gouverne les droits et les alertes, que le cabinet règle une fois.
            ["couleur_fond" => "white", "colonnes" => [
                ["champs" => ["dotationAnnuelle"], 'width' => 4],
                ["champs" => ["relanceApresJours"], 'width' => 4],
                ["champs" => ["seuilAlerteReport"], 'width' => 4],
            ]],
        ];

        // Les périodes de blocage vivent ICI, en collection, et non dans une rubrique à
        // elles : ce sont des réglages, pas des objets métier qu'on cherche.
        $collections = [
            ['fieldName' => 'periodesBlocage', 'entityRouteName' => 'periodeblocage', 'formTitle' => 'Période de blocage', 'parentFieldName' => 'parametres'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);

        return $layout;
    }
}
