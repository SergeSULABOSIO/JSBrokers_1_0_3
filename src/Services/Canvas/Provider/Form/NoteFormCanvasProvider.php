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
            "isCreationMode" => $isParentNew
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
        $visibilityClient = ['visibility_conditions' => [['field' => 'addressedTo', 'operator' => 'in', 'value' => [Note::TO_CLIENT]]]];
        $visibilityAssureur = ['visibility_conditions' => [['field' => 'addressedTo', 'operator' => 'in', 'value' => [Note::TO_ASSUREUR]]]];
        $visibilityPartenaire = ['visibility_conditions' => [['field' => 'addressedTo', 'operator' => 'in', 'value' => [Note::TO_PARTENAIRE]]]];
        $visibilityAutorite = ['visibility_conditions' => [['field' => 'addressedTo', 'operator' => 'in', 'value' => [Note::TO_AUTORITE_FISCALE]]]];
        $visibilityComptes = ['visibility_conditions' => [['field' => 'type', 'operator' => 'in', 'value' => [Note::TYPE_NOTE_DE_DEBIT]]]];

        $layout = [
            // Ligne 1: le type
            ["colonnes" => [["champs" => ["type"]]]],
            // Ligne 2: le nom
            ["colonnes" => [["champs" => ["nom"]]]],
            // Ligne 3: la référence
            ["colonnes" => [["champs" => ["reference"]]]],
            // Ligne 3.1: La description détaillée (Editeur riche)
            ["colonnes" => [["champs" => ["description"]]]],
            // Ligne 4: A qui s'adresse la note (destinataire)
            ["colonnes" => [["champs" => ["addressedTo"]]]],

            // --- Lignes conditionnelles basées sur le destinataire ---
            // Ligne 5: Le client
            ["colonnes" => [["champs" => [array_merge(['field_code' => 'client'], $visibilityClient)]]]],
            // Ligne 6: L'assureur
            ["colonnes" => [["champs" => [array_merge(['field_code' => 'assureur'], $visibilityAssureur)]]]],
            // Ligne 7: L'intermédiaire (partenaire)
            ["colonnes" => [["champs" => [array_merge(['field_code' => 'partenaire'], $visibilityPartenaire)]]]],
            // Ligne 8: L'autorité fiscale
            ["colonnes" => [["champs" => [array_merge(['field_code' => 'autoritefiscale'], $visibilityAutorite)]]]],

            // Ligne 10: Comptes bancaires (conditionnel, visible si type = Débit)
            ["colonnes" => [["champs" => [array_merge(['field_code' => 'comptes'], $visibilityComptes)]]]],

            // Ligne 11: Signataire
            ["colonnes" => [["champs" => ["signedBy"]]]],
            
            // Ligne 12: Titre du signataire
            ["colonnes" => [["champs" => ["titleSignedBy"]]]],
            
            // Ligne 13: Date de soumission
            ["colonnes" => [["champs" => ["sentAt"]]]],
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
                // Désactive si le solde est explicitement 0 ou moins (Note soldée)
                'disabled' => ($object->solde !== null && (float)$object->solde <= 0)
            ],
        ];

        dump("[NoteProvider] Évaluation bouton Paiement - Solde: " . ($object->solde ?? 'NULL') . " - Désactivé: " . ($object->solde !== null && (float)$object->solde <= 0 ? 'OUI' : 'NON'));

        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);

        return $layout;
    }
}