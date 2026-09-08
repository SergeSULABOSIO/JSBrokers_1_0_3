<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\ChargementPourPrime;

class ChargementPourPrimeFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ChargementPourPrime::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var ChargementPourPrime $object */
        $isParentNew = ($object->getId() === null);
        $chargementId = $object->getId() ?? 0;

        $parametres = [
            "titre_creation" => "Nouveau Chargement sur Prime",
            "titre_modification" => "Modification du Chargement #%id%",
            "endpoint_submit_url" => "/admin/chargementpourprime/api/submit",
            "endpoint_delete_url" => "/admin/chargementpourprime/api/delete",
            "endpoint_form_url" => "/admin/chargementpourprime/api/get-form",
            "isCreationMode" => $isParentNew,
            // Colonne gauche du dialogue EN CRÉATION : ce que cet objet apporte,
            // et ce qu'il engage en aval. Le premier paragraphe se suffit à
            // lui-même — c'est lui qui répond à qui ouvre le dialogue sans savoir.
            "description_creation" => [
                "Un chargement compose la prime : c'est en les empilant qu'on obtient le montant que le client devra payer.",
                "Chacun s'appuie sur un type de chargement — prime nette, fronting, frais accessoires, taxes — et peut porter un montant exceptionnel propre à cette affaire.",
                "Le découpage n'est pas cosmétique : la commission se calcule sur une assiette précise, et changer un chargement change ce que vous facturez.",
            ],
            // Entête contextuel du volet de saisie (pastille + description).
            "form_intro" => [
                "titre" => "Chargement sur prime",
                "description" => "Vous précisez une composante du montant de la prime d'une cotation : son type et, le cas échéant, son montant exceptionnel. Ces éléments déterminent le calcul de la prime totale due par le client.",
            ],
            // Mini-pastille par carte de champ : icône illustrant le champ (alias IconCanvasProvider).
            "field_icons" => [
                "documents" => "document",
                "nom"                    => "action:edit",
                "type"                   => "chargement",
                "montantFlatExceptionel" => "action:count",
            ],
        ];
        $layout = $this->buildChargementPourPrimeLayout($chargementId, $isParentNew);

        // Pièces jointes de cette fiche.
        $collections = [
            ['fieldName' => 'documents', 'entityRouteName' => 'document', 'formTitle' => 'Document', 'parentFieldName' => 'chargementPourPrime'],
        ];
        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildChargementPourPrimeLayout(int $chargementId, bool $isParentNew): array
    {
        $layout = [
            // Ligne 1 : Le nom du chargement sur toute la largeur.
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["nom"], 'width' => 12]]],
            // Ligne 2 : Le type et le montant se partagent l'espace.
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["type"]], ["champs" => ["montantFlatExceptionel"]]]],
            // Le champ 'cotation' est le lien parent, il est géré automatiquement et n'a pas besoin d'être dans le layout visible.
        ];
        return $layout;
    }
}