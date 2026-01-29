<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Invite;
use App\Entity\RolesEnFinance;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class RolesEnFinanceEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RolesEnFinance::class;
    }

    public function getCanvas(): array
    {
        $accessFields = [
            'accessMonnaie' => 'Monnaies',
            'accessCompteBancaire' => 'Comptes Bancaires',
            'accessTaxe' => 'Taxes',
            'accessTypeRevenu' => 'Types de Revenu',
            'accessTranche' => 'Tranches',
            'accessTypeChargement' => 'Types de Chargement',
            'accessNote' => 'Notes',
            'accessPaiement' => 'Paiements',
            'accessBordereau' => 'Bordereaux',
            'accessRevenu' => 'Revenus',
        ];

        $calculatedIndicators = [];
        foreach ($accessFields as $fieldCode => $label) {
            $calculatedIndicators[] = [
                "code" => $fieldCode . "String",
                "intitule" => "Accès " . $label,
                "type" => "Calcul",
                "format" => "Texte",
                "fonction" => "Role_getAccessString",
                "params" => [$fieldCode],
                "description" => "Permissions sur les " . strtolower($label) . "."
            ];
        }

        return [
            "parametres" => [
                "description" => "Rôle en Finance",
                "icone" => "action:role",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Rôle Finance: [[*nom]].",
                    " Assigné à: [[invite]]."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "invite", "intitule" => "Collaborateur", "type" => "Relation", "targetEntity" => Invite::class, "displayField" => "email"],
            ], $calculatedIndicators, $this->canvasHelper->getGlobalIndicatorsCanvas("RolesEnFinance"))
        ];
    }
}