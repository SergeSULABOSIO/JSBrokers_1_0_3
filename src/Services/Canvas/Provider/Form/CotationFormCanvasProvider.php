<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Cotation;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\Canvas\Provider\Form\FormCanvasProviderInterface;

class CotationFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Cotation::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Cotation $object */
        $isCreateMode = ($object->getId() === null);

        $parametres = [
            'titre_creation' => "Création d'une nouvelle cotation",
            'titre_modification' => "Modification de la cotation n°%id%",
            'endpoint_form_url' => '/admin/cotation/api/get-form',
            'endpoint_submit_url' => '/admin/cotation/api/submit',
            'endpoint_delete_url' => '/admin/cotation/api/delete',
            'isCreationMode' => $isCreateMode,
            // Picker de documents générique (client + piste parente + cotation + polices).
            "attribute_actions" => [
                // ── L'EFFORT COMMERCIAL D'UN AGENT INTERNE ────────────────────────────
                //
                // Le rattachement s'ÉCRIT toujours sur la PISTE : c'est la règle métier, et
                // elle ne bouge pas. Ce qui change, c'est qu'on peut l'ORDONNER d'ici —
                // parce que c'est d'ici qu'on travaille. Le serveur remonte l'arbre
                // (RattachementDuPartage::piste) et écrit au seul endroit légitime.
                //
                // `multi` sur le rattachement : on couvre une sélection entière d'un geste.
                // ⚠ La condition d'une action `multi` n'est évaluée que sur la PREMIÈRE
                // ligne (toolbar_controller) : le bouton peut donc s'afficher alors qu'une
                // ligne plus bas est déjà prise. Le contrôle « toutes libres » est SERVEUR,
                // et c'est là qu'il doit être — ce gating-ci reste cosmétique.
                [
                    "label"        => "Gérer le partage",
                    "icon"         => "partenaire",
                    "groupe"       => "Partage",
                    "groupe_icone" => "partenaire",
                    "event"        => "ui:partage.picker-request",
                    "url"          => "/admin/partage/cotation/conditions-picker",
                    "multi"        => true,
                    // AUCUNE CONDITION D'AFFICHAGE. Le picker rattache ce qui est libre et
                    // détache ce qui est posé : il n'y a plus d'état où l'action n'aurait
                    // rien à offrir, et lui-même sait dire qu'aucune condition n'existe —
                    // là où un bouton masqué n'apprend rien.
                ],
                [
                    "label" => "Voir les documents",
                    "icon"  => "classeur",
                    "event" => "ui:soa.docs-picker-request",
                    "url"   => "/admin/soa/api/documents/cotation/%id%",
                ],
            ],
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Fiche cotation",
                "description" => "Vous construisez la proposition tarifaire auprès d'un assureur : chargements, revenus du courtier, tranches de paiement et avenants. C'est la pièce maîtresse du placement, dont découlent la prime et la commission.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "nom"         => "action:edit",
                "duree"       => "action:calendar",
                "assureur"    => "assureur",
                "chargements" => "chargement",
                "revenus"     => "revenu",
                "tranches"    => "tranche",
                "documents"   => "document",
                "taches"      => "tache",
                "avenants"    => "avenant",
            ],
        ];

        $layout = $this->buildCotationLayout($object, $isCreateMode);

        return [
            'parametres' => $parametres,
            'form_layout' => $layout,
            'fields_map' => $this->buildFieldsMap($layout)
        ];
    }

    private function buildCotationLayout(Cotation $object, bool $isCreateMode): array
    {
        $cotationId = $object->getId() ?? 0;
        $layout = [
            [ // Ligne 1 : Nom (8/12) et Durée (4/12)
                'colonnes' => [
                    ['width' => 8, 'champs' => ['nom']],
                    ['width' => 4, 'champs' => ['duree']]
                ]
            ],
            [ // Ligne 2 : Assureur (pleine largeur)
                'colonnes' => [
                    ['width' => 12, 'champs' => ['assureur']]
                ]
            ]
        ];

        $collections = [
            ['fieldName' => 'chargements', 'entityRouteName' => 'chargementpourprime', 'formTitle' => 'Chargement', 'parentFieldName' => 'cotation', 'totalizableField' => 'montant_final'],
            ['fieldName' => 'revenus', 'entityRouteName' => 'revenupourcourtier', 'formTitle' => 'Revenu', 'parentFieldName' => 'cotation', 'totalizableField' => 'montantCalculeTTC'],
            ['fieldName' => 'tranches', 'entityRouteName' => 'tranche', 'formTitle' => 'Tranche', 'parentFieldName' => 'cotation', 'totalizableField' => 'primeTranche', 'watchIds' => ['collection-cotation_chargements', 'collection-cotation_revenus']],
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'cotation'],
            ['fieldName' => 'taches', 'entityRouteName' => 'tache', 'formTitle' => 'Tâche', 'parentFieldName' => 'cotation'],
            ['fieldName' => 'avenants', 'entityRouteName' => 'avenant', 'formTitle' => 'Avenant', 'parentFieldName' => 'cotation', 'totalizableField' => 'primeTotale'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $object, $isCreateMode, $collections);

        return $layout;
    }
}