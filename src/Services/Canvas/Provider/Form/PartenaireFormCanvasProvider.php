<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Partenaire;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class PartenaireFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Partenaire::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Partenaire $object */
        $isParentNew = ($object->getId() === null);
        $partenaireId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouveau Partenaire",
            "titre_modification" => "Modification du Partenaire #%id%",
            "endpoint_submit_url" => "/admin/partenaire/api/submit",
            "endpoint_delete_url" => "/admin/partenaire/api/delete",
            "endpoint_form_url" => "/admin/partenaire/api/get-form",
            "isCreationMode" => $isParentNew,
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Fiche partenaire",
                "description" => "Vous enregistrez un apporteur d'affaires : coordonnées, part de rétrocession par défaut et conditions de partage particulières. Ces éléments déterminent la répartition des commissions sur les affaires apportées.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "nom"               => "action:edit",
                "email"             => "contact",
                "telephone"         => "contact",
                "part"              => "action:count",
                "conditionPartages" => "condition",
                "documents"         => "document",
            ],
            // LE RAPPORT DE PRODUCTION D'UN PARTENAIRE, et son règlement.
            //
            // Il n'en avait aucun : ses chiffres n'existaient qu'en agrégat sur cette fiche,
            // et seul l'assistant savait les détailler — alors que le socle sait rendre les
            // deux familles depuis son extraction. L'agent porte les mêmes deux actions,
            // pointées sur ses propres routes ; ici ce sont celles du partenaire.
            //
            // Sans condition d'exigibilité, contrairement à l'agent : la propriété
            // `hasRetroAgentExigible` est déclarée sur Invite et n'a pas d'équivalent ici.
            // Ouvrir un rapport vide n'est pas dommageable — verser sans exigible l'est, et
            // c'est le picker qui le refuse en ne proposant aucune échéance.
            "attribute_actions" => [
                [
                    // Voir le commentaire jumeau du canevas d'Invite : le rapport est
                    // devenu une rubrique, et ce clic l'ouvre pré-filtrée sur le partenaire.
                    "label" => "Voir la production",
                    "icon"  => "action:view",
                    "event" => "ui:production.rubrique-request",
                    "url"   => "/admin/productionintermediaire/ouvrir/partenaire/%id%",
                ],
                [
                    "label" => "Signaler un reversement de rétrocommission",
                    "icon"  => "depense",
                    "event" => "ui:retroagent.reversement-request",
                    "url"   => "/admin/retro-agent/partenaire/%id%/reversement-picker",
                ],
            ],
        ];
        $layout = $this->buildPartenaireLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildPartenaireLayout(Partenaire $object, bool $isParentNew): array
    {
        $partenaireId = $object->getId() ?? 0;
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["email"]], ["champs" => ["telephone"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["part"]]]],
        ];
        $collections = [
            ['fieldName' => 'conditionPartages', 'entityRouteName' => 'conditionpartage', 'formTitle' => 'Condition de partage', 'parentFieldName' => 'partenaire'],
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'partenaire'],
        ];
        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);
        return $layout;
    }
}