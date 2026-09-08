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
            // Colonne gauche du dialogue EN CRÉATION : ce que cet objet apporte,
            // et ce qu'il engage en aval. Le premier paragraphe se suffit à
            // lui-même — c'est lui qui répond à qui ouvre le dialogue sans savoir.
            "description_creation" => [
                "Le paiement d'une prime est encaissé par l'ASSUREUR, pas par vous : ce signalement n'entre jamais dans votre trésorerie.",
                "Il sert à deux choses, et elles comptent : il éteint l'impayé du client, et il rend votre commission de courtage exigible auprès de la compagnie.",
                "L'information vous vient du client ou de l'assureur ; vous la consignez ici pour que le suivi cesse de réclamer une prime déjà réglée.",
                "Les rétrocommissions dues aux intermédiaires suivent au prorata de ce qui a été encaissé.",
            ],
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
