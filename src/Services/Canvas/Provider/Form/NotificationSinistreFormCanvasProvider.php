<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\NotificationSinistre;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class NotificationSinistreFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === NotificationSinistre::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var NotificationSinistre $object */
        $isParentNew = ($object->getId() === null);
        $notificationId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouvelle Notification de Sinistre",
            "titre_modification" => "Modification de la Notification #%id%",
            "endpoint_submit_url" => "/admin/notificationsinistre/api/submit",
            "endpoint_delete_url" => "/admin/notificationsinistre/api/delete",
            "endpoint_form_url" => "/admin/notificationsinistre/api/get-form",
            "isCreationMode" => $isParentNew,
            // Colonne gauche du dialogue EN CRÉATION : ce que cet objet apporte,
            // et ce qu'il engage en aval. Le premier paragraphe se suffit à
            // lui-même — c'est lui qui répond à qui ouvre le dialogue sans savoir.
            "description_creation" => [
                "Une notification ouvre le dossier de sinistre : c'est l'acte par lequel le cabinet prend acte du malheur de son client et enclenche l'indemnisation.",
                "Elle nomme l'assuré, l'assureur et le risque touché, relate les faits, les date et évalue les dommages.",
                "Tout ce qui suivra s'y rattache : les pièces réclamées, l'offre d'indemnisation, les tâches de relance.",
                "C'est au sinistre que le courtier prouve sa valeur. Un dossier ouvert vite et bien se règle vite et bien.",
            ],
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Notification de sinistre",
                "description" => "Vous déclarez un sinistre : l'assuré et l'assureur concernés, le risque touché, les faits, les dates et l'évaluation des dommages. Cette notification ouvre le dossier de sinistre et sert de référence pour les pièces, offres d'indemnisation et tâches qui suivront.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "documents" => "document",
                "assure"                      => "client",
                "assureur"                    => "assureur",
                "risque"                      => "risque",
                "referencePolice"             => "action:edit",
                "referenceSinistre"           => "action:edit",
                "descriptionDeFait"           => "action:description",
                "occuredAt"                   => "action:calendar",
                "notifiedAt"                  => "action:calendar",
                "lieu"                        => "contact",
                "descriptionVictimes"         => "action:description",
                "dommage"                     => "action:count",
                "evaluationChiffree"          => "action:count",
                "contacts"                    => "contact",
                "pieces"                      => "piece-sinistre",
                "offreIndemnisationSinistres" => "offre",
                "taches"                      => "tache",
            ],
        ];
        $layout = $this->buildNotificationSinistreLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildNotificationSinistreLayout(NotificationSinistre $object, bool $isParentNew): array
    {
        $notificationId = $object->getId() ?? 0;
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["assure"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["assureur"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["risque"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["referencePolice"]], ["champs" => ["referenceSinistre"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["descriptionDeFait"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["occuredAt"]], ["champs" => ["notifiedAt"]], ["champs" => ["lieu"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["descriptionVictimes"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["dommage"]], ["champs" => ["evaluationChiffree"]]]]
        ];

        $collections = [
            ['fieldName' => 'contacts', 'entityRouteName' => 'contact', 'formTitle' => 'Contact', 'parentFieldName' => 'notificationSinistre'],
            ['fieldName' => 'pieces', 'entityRouteName' => 'piecesinistre', 'formTitle' => 'Pièce Sinistre', 'parentFieldName' => 'notificationSinistre'],
            ['fieldName' => 'offreIndemnisationSinistres', 'entityRouteName' => 'offreindemnisationsinistre', 'formTitle' => "Offre d'indemnisation", 'parentFieldName' => 'notificationSinistre', 'totalizableField' => 'montantPayableCalcule'],
            ['fieldName' => 'taches', 'entityRouteName' => 'tache', 'formTitle' => 'Tâche', 'parentFieldName' => 'notificationSinistre'],
        ];

        // Pièces jointes de cette fiche.
        $collections[] = ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'notificationSinistre'];
        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);

        return $layout;
    }
}