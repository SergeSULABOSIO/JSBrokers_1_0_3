<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Chargement;
use App\Entity\ChargementPourPrime;
use App\Entity\TypeRevenu;
use App\Services\ServiceMonnaies;

class ChargementEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Chargement::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Type de chargement",
                "icone" => "chargement",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Type de chargement : [[*nom]].",
                    " Description : <em>« [[description]] »</em>."
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "description", "intitule" => "Description", "type" => "Texte"],
                ["code" => "fonction_string", "intitule" => "Fonction", "type" => "Calcul", "format" => "Texte", "description" => "Fonction du chargement."],
                ["code" => "chargementPourPrimes", "intitule" => "Utilisations (Primes)", "type" => "Collection", "targetEntity" => ChargementPourPrime::class, "displayField" => "nom"],
                ["code" => "typeRevenus", "intitule" => "Utilisations (Revenus)", "type" => "Collection", "targetEntity" => TypeRevenu::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators()) // Note: This collection is not directly related to global indicators.
        ];
    }

    private function getSpecificIndicators(): array
    {
        $monnaie = $this->serviceMonnaies->getCodeMonnaieAffichage();
        return [
            ["group" => "Impact Financier", "code" => "montantTotalApplique", "intitule" => "Volume Total Appliqué", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Somme de tous les montants appliqués pour ce type de chargement à travers toutes les polices."],
            ["group" => "Statistiques d'Utilisation", "code" => "nombreUtilisations", "intitule" => "Nombre d'Utilisations", "type" => "Calcul", "format" => "Nombre", "description" => "Nombre de fois où ce type de chargement a été utilisé dans des cotations."],
            ["group" => "Statistiques d'Utilisation", "code" => "poidsMoyenSurPrime", "intitule" => "Poids Moyen sur Prime", "type" => "Calcul", "format" => "Pourcentage", "description" => "Pourcentage moyen que ce chargement représente sur la prime totale des polices où il est appliqué."],
        ];
    }
}