<?php

namespace App\Services\Canvas\Provider\Form;

use App\Entity\PaiementPrime;

class PaiementPrimeFormCanvasProvider implements FormCanvasProviderInterface
{
    use FormCanvasProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === PaiementPrime::class;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        /** @var PaiementPrime $object */
        $isParentNew = ($object->getId() === null);

        $parametres = [
            "titre_creation" => "Signaler un paiement de prime",
            "titre_modification" => "Modification du signalement #%id%",
            "endpoint_submit_url" => "/admin/paiementprime/api/submit",
            "endpoint_delete_url" => "/admin/paiementprime/api/delete",
            "endpoint_form_url" => "/admin/paiementprime/api/get-form",
            "isCreationMode" => $isParentNew,
            "form_intro" => [
                "titre" => "Paiement de prime",
                "description" => "Vous tracez le règlement de la prime par l'assuré, encaissé par l'ASSUREUR (information reçue du client ou de l'assureur). Ce signalement n'impacte jamais votre trésorerie : il sert au suivi et rend votre commission de courtage exigible.",
            ],
            "field_icons" => [
                "reference"   => "action:edit",
                "montant"     => "action:count",
                "paidAt"      => "action:calendar",
                "description" => "action:description",
                "preuves"     => "document",
            ],
        ];
        $layout = $this->buildPaiementPrimeLayout($object, $isParentNew);

        return [
            "parametres" => $parametres,
            "form_layout" => $layout,
            "fields_map" => $this->buildFieldsMap($layout)
        ];
    }

    private function buildPaiementPrimeLayout(PaiementPrime $object, bool $isParentNew): array
    {
        $layout = [
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["reference"]]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["montant"], "width" => 6], ["champs" => ["paidAt"], "width" => 6]]],
            ["couleur_fond" => "white", "colonnes" => [["champs" => ["description"]]]],
        ];

        $collections = [
            ['fieldName' => 'preuves', 'entityRouteName' => 'document', 'formTitle' => 'Preuve', 'parentFieldName' => 'paiementPrime'],
        ];

        $this->addCollectionWidgetsToLayout($layout, $object, $isParentNew, $collections);
        return $layout;
    }
}
