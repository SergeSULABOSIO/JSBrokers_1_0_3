<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Article;
use App\Entity\Cotation;
use App\Entity\RevenuPourCourtier;
use App\Entity\TypeRevenu;
use App\Services\Canvas\CanvasHelper;
use App\Services\ServiceMonnaies;

class RevenuPourCourtierEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies,
        private CanvasHelper $canvasHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RevenuPourCourtier::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Revenu pour Courtier",
                "icone" => "revenu",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Revenu [[*nom]] sur la cotation [[cotation]].",
                    " Type: [[typeRevenu]].", // Texte principal
                    " Montant: [[montantCalculeTTC]]." // Texte secondaire, affiche maintenant le montant final
                ]
            ],
            "liste" => array_merge([
                ["code" => "id", "intitule" => "ID", "type" => "Entier"],
                ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
                ["code" => "typeRevenu", "intitule" => "Type de Revenu", "type" => "Relation", "targetEntity" => TypeRevenu::class, "displayField" => "nom"],
                ["code" => "cotation", "intitule" => "Cotation", "type" => "Relation", "targetEntity" => Cotation::class, "displayField" => "nom"],
                ["code" => "createdAt", "intitule" => "Créé le", "type" => "Date"],
                ["code" => "articles", "intitule" => "Articles de note", "type" => "Collection", "targetEntity" => Article::class, "displayField" => "nom"],
            ], $this->getSpecificIndicators())
        ];
    }

    private function getSpecificIndicators(): array
    {
        return [
            ["code" => "montantCalculeHT", "intitule" => "Montant HT", "type" => "Calcul", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "Le montant de base du revenu, calculé avant l'application de toute taxe."],
            ["code" => "montantCalculeTTC", "intitule" => "Montant", "type" => "Calcul", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "Le montant final du revenu après ajout de toutes les taxes applicables (ex: TVA). C'est ce montant qui est généralement facturé et affiché dans les listes."],
            ["code" => "descriptionCalcul", "intitule" => "Détail du Calcul", "type" => "Calcul", "format" => "Texte", "description" => "Explique comment le montant HT a été obtenu (ex: 'Taux exceptionnel de 10%' ou 'Montant fixe par défaut')."],
            ["code" => "montant_du", "intitule" => "Montant Dû", "type" => "Calcul", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "Le montant total TTC qui a été facturé au redevable (assureur ou client) pour ce revenu."],
            ["code" => "montant_paye", "intitule" => "Montant Payé", "type" => "Calcul", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "La somme de tous les paiements déjà reçus par le courtier pour les factures émises pour ce revenu."],
            ["code" => "solde_restant_du", "intitule" => "Solde Restant Dû", "type" => "Calcul", "format" => "Monetaire", "unite" => $this->serviceMonnaies->getCodeMonnaieAffichage(), "description" => "La différence entre le montant dû (facturé) et le montant déjà payé. Indique ce qu'il reste à encaisser."],
        ];
    }
}