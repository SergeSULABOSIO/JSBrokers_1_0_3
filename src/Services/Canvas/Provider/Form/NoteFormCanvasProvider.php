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
        /** @var Note $object */
        $isParentNew = ($object->getId() === null);
        $noteId = $object->getId() ?? 0;

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
            "idEntreprise" => $idEntreprise,
            "idInvite" => $object->getInvite()?->getId(),
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

        // --- Configuration de la collection d'articles (Totalisable comme dans Cotation) ---
        $articlesExtra = ['totalizableField' => 'montantArticle'];
        if (!$isParentNew) {
            $total = 0;
            foreach ($object->getArticles() as $article) {
                $this->canvasBuilder->loadAllCalculatedValues($article);
                $total += $article->montantArticle ?? 0;
            }
            $articlesExtra['totalValue'] = $total;
        }

        $layout = [
            // Ligne 1: le type
            ["colonnes" => [["champs" => ["type"]]]],
            // Ligne 2: le nom
            ["colonnes" => [["champs" => ["nom"]]]],
            // Ligne 3: la référence
            ["colonnes" => [["champs" => ["reference"]]]],
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

            // Ligne 9: Collection d'articles
            [
                "colonnes" => [[
                    "champs" => [$this->getCollectionWidgetConfig('articles', 'article', $noteId, 'Article', 'note', null, $isParentNew, $articlesExtra)]
                ]]
            ],

            // Ligne 10: Comptes bancaires (conditionnel, visible si type = Débit)
            ["colonnes" => [["champs" => [array_merge(['field_code' => 'comptes'], $visibilityComptes)]]]],

            // Ligne 11: Signataire
            ["colonnes" => [["champs" => ["signedBy"]]]],
            
            // Ligne 12: Titre du signataire
            ["colonnes" => [["champs" => ["titleSignedBy"]]]],
            
            // Ligne 13: Date de soumission
            ["colonnes" => [["champs" => ["sentAt"]]]],

            // Ligne 14: Collection des paiements
            [
                "colonnes" => [[
                    "champs" => [$this->getCollectionWidgetConfig('paiements', 'paiement', $noteId, 'Paiement', 'note', null, $isParentNew)]
                ]]
            ],
        ];

        return $layout;
    }
}