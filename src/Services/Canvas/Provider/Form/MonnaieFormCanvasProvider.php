<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\Monnaie;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class MonnaieFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Monnaie::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var Monnaie $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Nouvelle Monnaie",
            "titre_modification" => "Modification de la Monnaie #%id%",
            "endpoint_submit_url" => "/admin/monnaie/api/submit",
            "endpoint_delete_url" => "/admin/monnaie/api/delete",
            "endpoint_form_url" => "/admin/monnaie/api/get-form",
            "isCreationMode" => $isParentNew,
            // Colonne gauche du dialogue EN CRÉATION : ce que ce paramètre apporte
            // au cabinet. Le premier paragraphe sert aussi de « pourquoi » sur la
            // carte du guide de démarrage — un seul texte, deux surfaces.
            "description_creation" => [
                "La monnaie locale de votre cabinet a été créée avec un taux de change provisoire de 1,00 : tant qu'il n'est pas ajusté, toute conversion est fausse en silence.",
                "Le taux exprime combien d'unités de cette monnaie valent un dollar. Il sert à convertir primes, commissions et taxes dès qu'une affaire n'est pas libellée dans la monnaie d'affichage.",
                "Corrigez-le maintenant, puis à chaque révision sensible : aucun écran ne signale un taux périmé, et un montant converti faux ne se voit qu'au moment de payer.",
            ],
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Monnaie",
                "description" => "Vous configurez une devise utilisée par l'entreprise : code, taux de change vers l'USD, format local et fonction. Elle conditionne l'affichage et la conversion des montants dans toute la gestion financière.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "documents" => "document",
                "nom"      => "action:edit",
                "code"     => "action:edit",
                "tauxusd"  => "action:count",
                "locale"   => "action:options",
                "fonction" => "action:options",
            ],
        ];
        $layout = $this->buildMonnaieLayout($object, $isParentNew);

        // Pièces jointes de cette fiche.
        $collections = [
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'monnaie'],
        ];
        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildMonnaieLayout(Monnaie $object, bool $isParentNew): array
    {
        return [
            [
                "couleur_fond" => "white",
                "colonnes" => [
                    ["width" => 8, "champs" => ["nom"]],
                    ["width" => 2, "champs" => ["code"]],
                    ["width" => 2, "champs" => ["tauxusd"]],
                ]
            ],
            [
                "couleur_fond" => "white",
                "colonnes" => [
                    ["width" => 6, "champs" => ["locale"]],
                    ["width" => 6, "champs" => ["fonction"]],
                ]
            ],
        ];
    }
}