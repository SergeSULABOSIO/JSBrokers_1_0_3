<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Client;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class ClientFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Client::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Client $object */
        $isParentNew = ($object->getId() === null);
        $clientId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouveau Client",
            "titre_modification" => "Modification du Client #%id%",
            "endpoint_submit_url" => "/admin/client/api/submit",
            "endpoint_delete_url" => "/admin/client/api/delete",
            "endpoint_form_url" => "/admin/client/api/get-form",
            "isCreationMode" => $isParentNew,
            "attribute_actions" => [
                [
                    "label" => "Voir le relevé de compte (SOA)",
                    "icon"  => "action:view",
                    "event" => "ui:soa.view-request",
                    "url"   => "/admin/soa/client/%id%/workspace",
                ],
                // Copie le lien PUBLIC tokenisé (utilisable par l'assuré sans compte) :
                // le POST crée/prolonge le jeton et retourne l'URL à mettre au presse-papiers.
                [
                    "label" => "Copier le lien client (SOA)",
                    "icon"  => "action:copy",
                    "event" => "ui:soa.copy-link-request",
                    "url"   => "/admin/soa/api/client/%id%/lien-public",
                ],
                [
                    "label" => "Envoyer le SOA par e-mail",
                    "icon"  => "action:send-email",
                    "event" => "ui:soa.send-request",
                    "url"   => "/admin/soa/client/%id%/envoi-picker",
                ],
                // Révocation du lien public : visible seulement quand un lien actif existe
                // (attribut calculé hasLienSoa, ClientIndicatorStrategy). Confirmation
                // générique non-delete côté cerveau, puis DELETE.
                [
                    "label"     => "Révoquer le lien du SOA",
                    "icon"      => "action:disable",
                    "event"     => "ui:soa.revoke-request",
                    "url"       => "/admin/soa/api/client/%id%/revoquer-lien",
                    "condition" => ["field" => "hasLienSoa", "value" => true],
                ],
                // Actions « portefeuille » conditionnelles (pattern Invité→Portefeuille) :
                // condition évaluée côté front contre l'attribut calculé hasPortefeuille
                // (ClientIndicatorStrategy). Affecter et Transférer ouvrent le même picker
                // de portefeuilles ; le backend adapte le mode à l'état réel du client.
                [
                    "label"     => "Affecter à un portefeuille",
                    "icon"      => "portefeuille",
                    "event"     => "ui:client.portefeuille-picker-request",
                    "url"       => "/admin/client/api/%id%/portefeuille-picker",
                    "condition" => ["field" => "hasPortefeuille", "value" => false],
                ],
                [
                    "label"     => "Transférer vers un autre portefeuille",
                    "icon"      => "action:transfer",
                    "event"     => "ui:client.portefeuille-picker-request",
                    "url"       => "/admin/client/api/%id%/portefeuille-picker",
                    "condition" => ["field" => "hasPortefeuille", "value" => true],
                ],
                [
                    "label"     => "Retirer du portefeuille",
                    "icon"      => "action:detach",
                    "event"     => "ui:client.retirer-portefeuille",
                    // Pas de %id% : l'id du client est transmis dans le payload et le
                    // cerveau fait DELETE {url}/{id} après confirmation.
                    "url"       => "/admin/client/api/retirer-portefeuille",
                    "condition" => ["field" => "hasPortefeuille", "value" => true],
                ],
            ],
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Fiche client",
                "description" => "Vous constituez le dossier d'identification du client : civilité, coordonnées, références légales et rattachements (groupe, apporteurs, contacts). Une fiche complète fiabilise les pistes, les cotations et le relevé de compte du client.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "civilite"    => "action:options",
                "nom"         => "action:edit",
                "groupe"      => "groupe",
                "portefeuille"=> "portefeuille",
                "adresse"     => "contact",
                "email"       => "contact",
                "telephone"   => "contact",
                "exonere"     => "taxe",
                "numimpot"    => "taxe",
                "rccm"        => "action:edit",
                "idnat"       => "action:edit",
                "partenaires" => "partenaire",
                "contacts"    => "contact",
                "documents"   => "document",
            ],
        ];
        $layout = $this->buildClientLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildClientLayout(Client $object, bool $isParentNew): array
    {
        $clientId = $object->getId() ?? 0;
        // NOUVEAU : On définit la condition de visibilité une seule fois pour la réutiliser.
        $visibilityConditionForLegalFields = [
            'visibility_conditions' => [
                [
                    'field' => 'civilite', // Le champ à écouter
                    'operator' => 'in',    // L'opérateur de comparaison
                    'value' => [Client::CIVILITE_ENTREPRISE, Client::CIVILITE_ASBL] // Les valeurs qui déclenchent la visibilité
                ]
            ]
        ];

        $layout = [
            // Ligne 1: "civilité" (full width)
            [
                // "couleur_fond" => "white", 
                "colonnes" => [["champs" => ["civilite"]]]
            ],
            // Ligne 2: "nom" (full width)
            [
                "colonnes" => [["champs" => ["nom"]]]
            ],
            // Ligne 3: "groupe", "portefeuille" (1/2 each)
            [
                // "couleur_fond" => "white",
                "colonnes" => [["champs" => ["groupe"], "width" => 6], ["champs" => ["portefeuille"], "width" => 6]]
            ],
            // Ligne 4: "adresse" (full width)
            [
                // "couleur_fond" => "white", 
                "colonnes" => [["champs" => ["adresse"]]]
            ],
            // Ligne 5: "email", "telephone" (1/2 each)
            [
                // "couleur_fond" => "white", 
                "colonnes" => [["champs" => ["email"], "width" => 6], ["champs" => ["telephone"], "width" => 6]]
            ],
            // Ligne 6: "exonere" (full width)
            [
                // "couleur_fond" => "white", 
                "colonnes" => [["champs" => ["exonere"]]]
            ],
            // Ligne 7: "numimpot", "rccm", "idnat" (1/3 each) - Conditional
            [
                // "couleur_fond" => "white", 
                "colonnes" => [
                    ["champs" => [array_merge(['field_code' => 'numimpot'], $visibilityConditionForLegalFields)], "width" => 4],
                    ["champs" => [array_merge(['field_code' => 'rccm'], $visibilityConditionForLegalFields)], "width" => 4],
                    ["champs" => [array_merge(['field_code' => 'idnat'], $visibilityConditionForLegalFields)], "width" => 4]
                ]
            ],
            // Ligne 8: "partenaires"
            [
                // "couleur_fond" => "white", 
                "colonnes" => [["champs" => ["partenaires"]]]
            ],
        ];

        $collections = [
            // Ligne 9: "Contacts"
            ['fieldName' => 'contacts', 'entityRouteName' => 'contact', 'formTitle' => 'Contact', 'parentFieldName' => 'client'],
            // Ligne 9: "Documents"
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'client'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);
        return $layout;
    }
}