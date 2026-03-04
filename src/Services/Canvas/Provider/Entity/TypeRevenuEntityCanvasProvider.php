<?php

namespace App\Services\Canvas\Provider\Entity;

use App\Entity\Chargement;
use App\Entity\TypeRevenu;
use App\Services\ServiceMonnaies;

class TypeRevenuEntityCanvasProvider implements EntityCanvasProviderInterface
{
    public function __construct(
        private ServiceMonnaies $serviceMonnaies
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === TypeRevenu::class;
    }

    public function getCanvas(): array
    {
        return [
            "parametres" => [
                "description" => "Type de Revenu",
                "icone" => "type-revenu",
                'background_image' => '/images/fitures/default.jpg',
                'description_template' => [
                    "Type de revenu [[*nom]].",
                    " Mode de calcul: [[descriptionModeCalcul]].",
                    " Redevable: [[redevableString]]."
                ]
            ],
            "liste" => array_merge(
                $this->getStandardAttributes(),
                $this->getCalculatedIndicators()
            )
        ];
    }

    private function getStandardAttributes(): array
    {
        $monnaie = $this->serviceMonnaies->getCodeMonnaieAffichage();
        return [
            ["code" => "id", "intitule" => "ID", "type" => "Entier"],
            ["code" => "nom", "intitule" => "Nom", "type" => "Texte"],
            ["code" => "typeChargement", "intitule" => "Chargement Cible", "type" => "Relation", "targetEntity" => Chargement::class, "displayField" => "nom"],
            ["code" => "pourcentage", "intitule" => "Pourcentage", "type" => "Nombre", "unite" => "%"],
            ["code" => "montantflat", "intitule" => "Montant Fixe", "type" => "Nombre", "format" => "Monetaire", "unite" => $monnaie],
            ["code" => "multipayments", "intitule" => "Paiements multiples", "type" => "Booleen"],
            ["code" => "appliquerPourcentageDuRisque", "intitule" => "Appliquer % du risque", "type" => "Booleen"],
        ];
    }

    private function getCalculatedIndicators(): array
    {
        return [
            ["group" => "Configuration", "code" => "descriptionModeCalcul", "intitule" => "Mode de Calcul", "type" => "Calcul", "format" => "Texte", "description" => "Définit si le revenu est un pourcentage d'un chargement ou un montant fixe."],
            ["group" => "Partage & Facturation", "code" => "redevableString", "intitule" => "Redevable", "type" => "Calcul", "format" => "Texte", "description" => "Définit qui est le débiteur de ce revenu (Client, Assureur, etc.)."],
            ["group" => "Partage & Facturation", "code" => "sharedString", "intitule" => "Partageable", "type" => "Calcul", "format" => "Texte", "description" => "Indique si ce revenu est partageable avec un partenaire."],
            ["group" => "Statistiques", "code" => "nombreUtilisations", "intitule" => "Nombre d'utilisations", "type" => "Calcul", "format" => "Nombre", "description" => "Indique dans combien de revenus pour courtier ce type est utilisé."],
        ];
    }
}