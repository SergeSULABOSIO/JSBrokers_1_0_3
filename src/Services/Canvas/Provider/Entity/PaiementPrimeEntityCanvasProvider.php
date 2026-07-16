<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Document;
use App\Entity\PaiementPrime;
use App\Entity\Tranche;
use App\Services\ServiceMonnaies;

class PaiementPrimeEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === PaiementPrime::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Paiement de prime signalé",
                "icone" => "paiement",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Prime réglée le [[paidAt|date('d/m/Y')]] ([[*reference]]).",
                    " Montant : [[montant]] [[currency_symbol]].",
                    " Signalement déclaratif — encaissée par l'assureur, sans impact sur la trésorerie du cabinet."
                ]
            ],
            "liste" => [
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "reference", "intitule" => "Référence", "type" => "Texte"],
                ["code" => "montant", "intitule" => "Montant de prime réglé", "type" => "Nombre", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage()],
                ["code" => "paidAt", "intitule" => "Date de paiement", "type" => "Date"],
                ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                ["code" => "tranche", "intitule" => "Tranche", "type" => "Relation", "targetEntity" => Tranche::class, "displayField" => "nom"],
                ["code" => "preuves", "intitule" => "Preuves", "type" => "Collection", "targetEntity" => Document::class, "displayField" => "nom"],
            ],
        ];
    }
}
