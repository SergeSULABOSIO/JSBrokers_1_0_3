<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Contact;

class ContactFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Contact::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Contact $object */
        $isParentNew = ($object->getId() === null);
        $contactId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouveau contact",
            "titre_modification" => "Modification du contact #%id%",
            "endpoint_submit_url" => "/admin/contact/api/submit",
            "endpoint_delete_url" => "/admin/contact/api/delete",
            "endpoint_form_url" => "/admin/contact/api/get-form",
            "isCreationMode" => $isParentNew,
            // Colonne gauche du dialogue EN CRÉATION : ce que cet objet apporte,
            // et ce qu'il engage en aval. Le premier paragraphe se suffit à
            // lui-même — c'est lui qui répond à qui ouvre le dialogue sans savoir.
            "description_creation" => [
                "Un contact est la personne à qui l'on parle chez le client : sans lui, chaque échange recommence par la recherche du bon interlocuteur.",
                "Vous y notez son nom, sa fonction et ses coordonnées. Un même client peut en compter plusieurs — le dirigeant, le responsable des assurances, le comptable — et chacun a son rôle au bon moment.",
                "Un carnet à jour fait gagner du temps là où il en manque le plus : à la cotation, au renouvellement et surtout au sinistre.",
            ],
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Fiche contact",
                "description" => "Vous identifiez un interlocuteur chez le client : nom, coordonnées et fonction. Un carnet de contacts à jour accélère les échanges lors des cotations, des renouvellements et des sinistres.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "documents" => "document",
                "nom"       => "action:edit",
                "email"     => "contact",
                "telephone" => "contact",
                "fonction"  => "role",
                "type"      => "action:options",
                "client"    => "client",
            ],
        ];
        $layout = $this->buildContactLayout($contactId, $isParentNew);

        // Pièces jointes de cette fiche.
        $collections = [
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'contact'],
        ];
        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildContactLayout(int $contactId, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["email"]], ["champs" => ["telephone"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["fonction"]], ["champs" => ["type"]]]],
            // Le client associé manquait à cette liste. Il ne disparaissait pas pour
            // autant : `form_end(render_rest: true)` le rendait en fin de formulaire, nu,
            // au milieu de champs en cartes illustrées. Il vient EN DERNIER parce qu'il
            // est facultatif — un contact peut relever d'un sinistre sans client direct.
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["client"]]]],
        ];

        return $layout;
    }
}
