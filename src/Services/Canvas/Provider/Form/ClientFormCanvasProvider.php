<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Invite;
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
            // Colonne gauche du dialogue EN CRÉATION : ce que cet objet apporte,
            // et ce qu'il engage en aval. Le premier paragraphe se suffit à
            // lui-même — c'est lui qui répond à qui ouvre le dialogue sans savoir.
            "description_creation" => [
                "Un client est celui pour qui vous placez des risques : sans sa fiche, aucune piste ne peut être ouverte, aucune cotation adressée, aucune police émise.",
                "Elle porte son identité, ses coordonnées et ses références légales. Elle porte aussi ses rattachements — le groupe auquel il appartient, l'intermédiaire qui l'a apporté, ses interlocuteurs : c'est de là que découlent le partage des commissions et le bon destinataire de chaque envoi.",
                "Un classeur à son nom est créé en même temps que la fiche. Tous ses documents s'y rangent d'eux-mêmes, sans que vous ayez à y penser.",
                "Le relevé de compte que vous lui enverrez se construit à partir de cette fiche : ce qui y manque manquera aussi sur le relevé.",
            ],
            // ── LES ACTIONS SE RANGENT EN FAMILLES ──────────────────────────────────
            //
            // La barre d'outils n'affiche que QUATRE entrées en ligne ; au-delà, le
            // surplus tombe dans « Autres actions ». Un client rattaché à un portefeuille
            // et doté d'un lien SOA en présentait sept à plat : les dernières étaient
            // déjà enfouies, et toute action nouvelle le serait d'office.
            //
            // Deux familles ramènent la barre à trois entrées lisibles. Le regroupement
            // est la SOURCE UNIQUE partagée par la barre et le clic droit (voir
            // assets/controllers/actions-groupees.js) : une famille réduite à un seul
            // membre visible est remise à plat toute seule, rien à prévoir.
            "attribute_actions" => [
                [
                    "label" => "Voir le relevé de compte (SOA)",
                    "icon"  => "action:view",
                    "groupe" => "Relevé de compte",
                    "groupe_icone" => "action:view",
                    "event" => "ui:soa.view-request",
                    "url"   => "/admin/soa/client/%id%/workspace",
                ],
                // Copie le lien PUBLIC tokenisé (utilisable par l'assuré sans compte) :
                // le POST crée/prolonge le jeton et retourne l'URL à mettre au presse-papiers.
                [
                    "label" => "Copier le lien client (SOA)",
                    "icon"  => "action:copy",
                    "groupe" => "Relevé de compte",
                    "event" => "ui:soa.copy-link-request",
                    "url"   => "/admin/soa/api/client/%id%/lien-public",
                ],
                [
                    "label" => "Envoyer le SOA par e-mail",
                    "icon"  => "action:send-email",
                    "groupe" => "Relevé de compte",
                    "event" => "ui:soa.send-request",
                    "url"   => "/admin/soa/client/%id%/envoi-picker",
                ],
                // Picker de documents générique (tous les niveaux du dossier du client).
                [
                    "label" => "Voir les documents",
                    "icon"  => "classeur",
                    "groupe" => "Relevé de compte",
                    "event" => "ui:soa.docs-picker-request",
                    "url"   => "/admin/soa/api/documents/client/%id%",
                ],
                // Révocation du lien public : visible seulement quand un lien actif existe
                // (attribut calculé hasLienSoa, ClientIndicatorStrategy). Confirmation
                // générique non-delete côté cerveau, puis DELETE.
                [
                    "label"     => "Révoquer le lien du SOA",
                    "icon"      => "action:disable",
                    "groupe"    => "Relevé de compte",
                    "event"     => "ui:soa.revoke-request",
                    "url"       => "/admin/soa/api/client/%id%/revoquer-lien",
                    "droit" => ["entite" => "Client", "niveau" => Invite::ACCESS_MODIFICATION], // garde de l'endpoint
                    "condition" => ["field" => "hasLienSoa", "value" => true],
                ],
                // Actions « portefeuille » conditionnelles (pattern Invité→Portefeuille) :
                // condition évaluée côté front contre l'attribut calculé hasPortefeuille
                // (ClientIndicatorStrategy). Affecter et Transférer ouvrent le même picker
                // de portefeuilles ; le backend adapte le mode à l'état réel du client.
                [
                    "label"     => "Affecter à un portefeuille",
                    "icon"      => "portefeuille",
                    "groupe"    => "Portefeuille",
                    "groupe_icone" => "portefeuille",
                    "event"     => "ui:client.portefeuille-picker-request",
                    "url"       => "/admin/client/api/%id%/portefeuille-picker",
                    "droit" => ["entite" => "Client", "niveau" => Invite::ACCESS_MODIFICATION], // garde de l'endpoint
                    "condition" => ["field" => "hasPortefeuille", "value" => false],
                ],
                [
                    "label"     => "Transférer vers un autre portefeuille",
                    "icon"      => "action:transfer",
                    "groupe"    => "Portefeuille",
                    "event"     => "ui:client.portefeuille-picker-request",
                    "url"       => "/admin/client/api/%id%/portefeuille-picker",
                    "droit" => ["entite" => "Client", "niveau" => Invite::ACCESS_MODIFICATION], // garde de l'endpoint
                    "condition" => ["field" => "hasPortefeuille", "value" => true],
                ],
                [
                    "label"     => "Retirer du portefeuille",
                    "icon"      => "action:detach",
                    "groupe"    => "Portefeuille",
                    "event"     => "ui:client.retirer-portefeuille",
                    // Pas de %id% : l'id du client est transmis dans le payload et le
                    // cerveau fait DELETE {url}/{id} après confirmation.
                    "url"       => "/admin/client/api/retirer-portefeuille",
                    "droit" => ["entite" => "Client", "niveau" => Invite::ACCESS_MODIFICATION], // garde de l'endpoint
                    "condition" => ["field" => "hasPortefeuille", "value" => true],
                ],
                // ── OUVRIR UN DOSSIER AU CLIENT QU'ON A SOUS LES YEUX ───────────────
                //
                // Sans elles, décider d'ouvrir une piste ou de déclarer un sinistre
                // obligeait à changer de rubrique, à créer la fiche à blanc, puis à y
                // rechercher le client — celui-là même qu'on venait de quitter.
                //
                // ── « Créer… » EST LA FAMILLE D'ACCUEIL DE TOUTE CRÉATION ───────────
                //
                // Ces deux gestes étaient d'abord à plat, la piste étant seule de son
                // espèce. À deux, ils remplissaient la barre : quatre entrées, soit le
                // plafond exact au-delà duquel le surplus tombe dans « Autres actions ».
                // La famille leur rend cette place, et elle en fait une CONVENTION : toute
                // action de création future rejoint « Créer… », avec la même icône
                // `action:add` — celle que porte déjà le bouton « Ajouter » des
                // collections, donc le même signe pour le même geste partout.
                //
                // Rien n'est perdu quand il n'y a qu'un membre : actions-groupees.js remet
                // une famille d'un seul visible à plat tout seul.
                //
                // SANS CONDITION : on peut toujours ouvrir un dossier à un client, quel
                // que soit son état.
                [
                    "label"  => "Créer une piste",
                    "icon"   => "piste",
                    "groupe" => "Créer…",
                    "groupe_icone" => "action:add",
                    "event"  => "ui:client.creer-piste",
                    "url"    => "/admin/client/api/%id%/piste-context",
                    "droit" => ["entite" => "Client", "niveau" => Invite::ACCESS_MODIFICATION], // garde de l'endpoint
                ],
                [
                    "label"  => "Créer un sinistre",
                    "icon"   => "sinistre",
                    "groupe" => "Créer…",
                    "event"  => "ui:client.creer-sinistre",
                    "url"    => "/admin/client/api/%id%/sinistre-context",
                    "droit" => ["entite" => "Client", "niveau" => Invite::ACCESS_MODIFICATION], // garde de l'endpoint
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
                "pistes"      => "piste",
                "notificationSinistres" => "sinistre",
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
            // Ligne 1 : L'IDENTITÉ, D'UN SEUL TENANT — « civilité » et « nom ».
            //
            // Ces deux champs n'en font qu'un pour le lecteur : la civilité QUALIFIE le
            // nom, elle ne vaut rien seule. Les empiler sur deux rangées séparait ce qui
            // se lit d'un trait — « Monsieur Serge Test » — et faisait descendre le nom
            // sous la ligne de flottaison du bloc, loin de ce qu'il complète.
            //
            // Même mécanique que « groupe / portefeuille » ou « e-mail / téléphone » plus
            // bas : une rangée, deux colonnes, des largeurs. Le NOM prend le plus large —
            // c'est lui qu'on lit, et il peut être long ; la civilité, repliée en liste
            // déroulante, tient dans un quart de ligne. Lui en donner davantage la ferait
            // paraître plus importante que ce qu'elle qualifie.
            [
                "colonnes" => [
                    ["champs" => ["civilite"], "width" => 3],
                    ["champs" => ["nom"], "width" => 9],
                ]
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
            ['fieldName' => 'contacts', 'entityRouteName' => 'contact', 'formTitle' => 'Contact', 'ongletTitre' => 'Contacts', 'parentFieldName' => 'client'],

            // ── LES AFFAIRES DU CLIENT ──────────────────────────────────────────────
            // Le total additionne la prime des cotations SOUSCRITES de chaque piste
            // (indicateur calculé primeTotale) : c'est ce que ce client pèse en portefeuille.
            ['fieldName' => 'pistes', 'entityRouteName' => 'piste', 'formTitle' => 'Piste', 'ongletTitre' => 'Pistes', 'parentFieldName' => 'client', 'totalizableField' => 'primeTotale'],

            // ── LES SINISTRES DU CLIENT ─────────────────────────────────────────────
            //
            // 🔴 `parentRouteName` EST INDISPENSABLE ICI, et c'est la seule subtilité de
            // cet onglet. Le champ par lequel un sinistre désigne son client s'appelle
            // `assure`, pas `client` : sans l'échappatoire, getCollectionWidgetConfig()
            // fabriquerait listUrl = /admin/assure/api/... — une route qui n'existe pas,
            // et un onglet muet. `parentFieldName` doit RESTER 'assure' : c'est un nom de
            // CHAMP (le setter de l'enfant, la clé du FormData), pas un segment d'URL.
            //
            // PAS DE TAMPON, contrairement aux pistes. Un sinistre porte sur une police
            // déjà souscrite ; un client qu'on est en train de saisir n'en a aucune.
            // L'onglet reste VISIBLE — on voit qu'il existe et qu'il attend — mais sa
            // liste dit « Commencez par enregistrer » et le bouton d'ajout est retiré.
            // `hidden => false` est obligatoire : sans lui, le défaut
            // `$isParentNew && $isDisabled` ferait disparaître l'onglet au lieu de le
            // désarmer.
            ['fieldName' => 'notificationSinistres', 'entityRouteName' => 'notificationsinistre', 'formTitle' => 'Sinistre', 'ongletTitre' => 'Sinistres', 'parentFieldName' => 'assure', 'parentRouteName' => 'client', 'totalizableField' => 'evaluationChiffree', 'disabled' => $isParentNew, 'hidden' => false],

            // Ligne 9: "Documents"
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'ongletTitre' => 'Documents', 'parentFieldName' => 'client'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);
        return $layout;
    }
}