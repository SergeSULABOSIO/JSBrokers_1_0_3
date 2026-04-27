<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Note;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class NoteFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Note::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        // Debug : Vérifie si l'invité est bien présent sur la Note (injecté par l'initializer du Controller)
        // dd($object, $object->getInvite(), $idEntreprise); 

        /** @var Note $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouvelle Note",
            "titre_modification" => "Modification de la Note #%id%",
            "endpoint_submit_url" => "/admin/note/api/submit",
            "endpoint_delete_url" => "/admin/note/api/delete",
            "endpoint_form_url" => "/admin/note/api/get-form",
            "isCreationMode" => $isParentNew,
            // NOUVEAU : Définition de la barre d'outils pour le volet des attributs.
            // Cette barre ne sera affichée qu'en mode édition.
            "attribute_actions" => [
                [
                    "label" => "Visualiser la note",
                    "icon" => "action:view", // Alias pour l'icône de visualisation
                    "event" => "ui:note.preview-request", // Événement à envoyer au cerveau
                    // CORRECTION : On utilise un placeholder %id% que le JavaScript remplacera par l'ID de l'élément sélectionné.
                    "url" => "/admin/note/api/get-preview-url/%id%"
                ],
                // NOUVEAU : Action pour imprimer la note
                [
                    "label" => "Imprimer",
                    "icon" => "action:print",
                    "event" => "ui:note.preview-request", // On réutilise le même événement qui ouvre une nouvelle fenêtre
                    "url" => "/admin/note/api/get-preview-url/%id%?print=1" // On ajoute un paramètre pour déclencher l'impression
                ],
                // NOUVEAU : Action pour télécharger la note en PDF
                [
                    "label" => "Télécharger en PDF",
                    "icon" => "action:download",
                    "event" => "ui:note.preview-request", // On réutilise le même événement
                    "url" => "/admin/note/api/get-preview-url/%id%?download=1" // On ajoute un paramètre pour le téléchargement
                ]
            ]
        ];
        $layout = $this->buildNoteLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout),
        ];
    }

    private function buildNoteLayout(Note $object, bool $isParentNew): array
    {
        $noteId = $object->getId() ?? 0;

        // --- Définition des conditions de visibilité ---
        // Conditions pour les champs de sélection de destinataire (Client, Assureur, etc.)
        // NOUVEAU : Ajout d'une condition pour s'assurer que 'addressedTo' a une valeur.
        $visibilityClient = ['visibility_conditions' => [
            ['field' => 'addressedTo', 'operator' => 'not_empty'],
            ['field' => 'addressedTo', 'operator' => 'in', 'value' => [Note::TO_CLIENT]]
        ]];
        $visibilityAssureur = ['visibility_conditions' => [
            ['field' => 'addressedTo', 'operator' => 'not_empty'],
            ['field' => 'addressedTo', 'operator' => 'in', 'value' => [Note::TO_ASSUREUR]]
        ]];
        $visibilityPartenaire = ['visibility_conditions' => [
            ['field' => 'addressedTo', 'operator' => 'not_empty'],
            ['field' => 'addressedTo', 'operator' => 'in', 'value' => [Note::TO_PARTENAIRE]]
        ]];
        $visibilityAutorite = ['visibility_conditions' => [
            ['field' => 'addressedTo', 'operator' => 'not_empty'],
            ['field' => 'addressedTo', 'operator' => 'in', 'value' => [Note::TO_AUTORITE_FISCALE]]
        ]];
        
        // Condition pour le champ 'comptes'
        $visibilityComptes = ['visibility_conditions' => [['field' => 'type', 'operator' => 'in', 'value' => [Note::TYPE_NOTE_DE_DEBIT]]]];

        // NOUVEAU : Conditions pour les options de destinataire (les cases à cocher)
        $visibilityDebitOption = ['visibility_conditions' => [['field' => 'type', 'operator' => 'in', 'value' => [Note::TYPE_NOTE_DE_DEBIT]]]];
        $visibilityCreditOption = ['visibility_conditions' => [['field' => 'type', 'operator' => 'in', 'value' => [Note::TYPE_NOTE_DE_CREDIT]]]];


        $layout = [
            // Ligne 1: le type
            ["colonnes" => [["champs" => ["type"]]]],
            // Ligne 2: Le destinataire et les champs conditionnels associés
            ["colonnes" => [["champs" => ["addressedTo"]]]],
            ["colonnes" => [["champs" => [array_merge(['field_code' => 'client'], $visibilityClient)]]]],
            ["colonnes" => [["champs" => [array_merge(['field_code' => 'assureur'], $visibilityAssureur)]]]],
            ["colonnes" => [["champs" => [array_merge(['field_code' => 'partenaire'], $visibilityPartenaire)]]]],
            ["colonnes" => [["champs" => [array_merge(['field_code' => 'autoritefiscale'], $visibilityAutorite)]]]],

            // NOUVEAU : Ajout du champ Bordereau. Il est conditionné par la présence d'un assureur.
            ["colonnes" => [["champs" => [array_merge(['field_code' => 'bordereau'], $visibilityAssureur)]]]],

            // Ligne 3: le nom
            ["colonnes" => [["champs" => ["nom"]]]],

            // Ligne 4: la référence
            ["colonnes" => [["champs" => ["reference"]]]],

            // Ligne 5: La description détaillée (Editeur riche)
            ["colonnes" => [["champs" => ["description"]]]],

        ];

        $collections = [
            [
                'fieldName' => 'articles', 
                'entityRouteName' => 'article', 
                'formTitle' => 'Article', 
                'parentFieldName' => 'note', 
                'totalizableField' => 'montantArticle',
                'disabled' => false
            ],
            [
                'fieldName' => 'paiements',
                'entityRouteName' => 'paiement',
                'formTitle' => 'Paiement',
                'parentFieldName' => 'note',
                'totalizableField' => 'montantPaiement',
                'secondaryField' => 'paidAt', // On veut afficher la date
                'secondaryLabel' => ' * Date: le ', // Séparateur et préfixe
                // Désactive si le solde est explicitement 0 ou moins (Note soldée)
                'disabled' => ($object->solde !== null && (float)$object->solde <= 0)
            ],
        ];

        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);

        // On ajoute le reste des champs après les collections
        $remainingLayout = [
            // Ligne 10: Comptes bancaires (conditionnel, visible si type = Débit)
            ["colonnes" => [["champs" => [array_merge(['field_code' => 'comptes'], $visibilityComptes)]]]],

            // Ligne 11: Signataire
            ["colonnes" => [["champs" => ["signedBy"]]]],
            
            // Ligne 12: Titre du signataire
            ["colonnes" => [["champs" => ["titleSignedBy"]]]],
            
            // Ligne 13: Date de soumission
            ["colonnes" => [["champs" => ["sentAt"]]]],
        ];
        
        // Fusionne le layout de base avec les champs restants
        $layout = array_merge($layout, $remainingLayout);

        return $layout;
    }
}