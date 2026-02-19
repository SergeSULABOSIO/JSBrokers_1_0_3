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
        $monnaie = $this->serviceMonnaies->getCodeMonnaieAffichage();
        return [
            ["group" => "Contexte Affaire", "code" => "clientDescription", "intitule" => "Client", "type" => "Calcul", "format" => "Texte", "description" => "Détails descriptifs du client."],
            ["group" => "Contexte Affaire", "code" => "risqueDescription", "intitule" => "Risque", "type" => "Calcul", "format" => "Texte", "description" => "Description de la couverture d'assurance."],

            // Groupe 1: Revenu Brut
            ["group" => "Revenu Brut", "code" => "montantCalculeHT", "intitule" => "Montant HT", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Le montant de base du revenu, calculé avant l'application de toute taxe."],
            ["group" => "Revenu Brut", "code" => "montantCalculeTTC", "intitule" => "Montant TTC", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Le montant final du revenu après ajout de toutes les taxes applicables (ex: TVA). C'est ce montant qui est généralement facturé et affiché dans les listes."],
            ["group" => "Revenu Brut", "code" => "descriptionCalcul", "intitule" => "Détail du Calcul", "type" => "Calcul", "format" => "Texte", "description" => "Explique comment le montant HT a été obtenu (ex: 'Taux exceptionnel de 10%' ou 'Montant fixe par défaut')."],

            // Groupe 2: Taxes
            ["group" => "Taxes sur Commission", "code" => "taxeCourtierMontant", "intitule" => "Taxe Courtier (ARCA)", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant de la taxe (ex: ARCA) due par le courtier sur sa commission."],
            ["group" => "Taxes sur Commission", "code" => "taxeCourtierTaux", "intitule" => "Taux Taxe Courtier", "type" => "Calcul", "format" => "Pourcentage", "description" => "Taux de la taxe appliquée pour le courtier."],
            ["group" => "Taxes sur Commission", "code" => "taxeAssureurMontant", "intitule" => "Taxe Assureur (TVA)", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Montant de la taxe (ex: TVA) applicable sur la commission, généralement refacturée à l'assureur."],
            ["group" => "Taxes sur Commission", "code" => "taxeAssureurTaux", "intitule" => "Taux Taxe Assureur", "type" => "Calcul", "format" => "Pourcentage", "description" => "Taux de la taxe (type TVA) appliquée."],

            // Groupe 3: Facturation
            ["group" => "Facturation & Paiements", "code" => "montant_du", "intitule" => "Montant Dû", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Le montant total TTC qui a été facturé au redevable (assureur ou client) pour ce revenu."],
            ["group" => "Facturation & Paiements", "code" => "montant_paye", "intitule" => "Montant Payé", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "La somme de tous les paiements déjà reçus par le courtier pour les factures émises pour ce revenu."],
            ["group" => "Facturation & Paiements", "code" => "solde_restant_du", "intitule" => "Solde Restant Dû", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "La différence entre le montant dû (facturé) et le montant déjà payé. Indique ce qu'il reste à encaisser."],
            ["group" => "Facturation & Paiements", "code" => "taxeCourtierPayee", "intitule" => "Taxe Courtier Payée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Le montant de la taxe courtier qui a déjà été versé à l'autorité fiscale."],
            ["group" => "Facturation & Paiements", "code" => "taxeCourtierSolde", "intitule" => "Solde Taxe Courtier", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Le montant de la taxe courtier restant à verser à l'autorité fiscale."],
            ["group" => "Facturation & Paiements", "code" => "taxeAssureurPayee", "intitule" => "Taxe Assureur Payée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Le montant de la taxe assureur (TVA) qui a déjà été versé à l'autorité fiscale."],
            ["group" => "Facturation & Paiements", "code" => "taxeAssureurSolde", "intitule" => "Solde Taxe Assureur", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Le montant de la taxe assureur (TVA) restant à verser à l'autorité fiscale."],

            // Groupe 4: Partage Partenaire
            ["group" => "Partage Partenaire", "code" => "estPartageable", "intitule" => "Revenu Partageable", "type" => "Calcul", "format" => "Texte", "description" => "Indique si ce revenu est configuré pour être partagé avec un partenaire d'affaires."],
            ["group" => "Partage Partenaire", "code" => "montantPur", "intitule" => "Montant Pur (Assiette Partage)", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Le montant du revenu HT après déduction de la taxe courtier. C'est la base de calcul pour la rétro-commission du partenaire."],
            ["group" => "Partage Partenaire", "code" => "partPartenaire", "intitule" => "Part du Partenaire", "type" => "Calcul", "format" => "Pourcentage", "description" => "Le pourcentage de la commission pure revenant au partenaire, selon les conditions de partage en vigueur."],
            ["group" => "Partage Partenaire", "code" => "retroCommission", "intitule" => "Rétro-commission Due", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Le montant de la commission à reverser au partenaire, calculé sur le montant pur."],
            ["group" => "Partage Partenaire", "code" => "retroCommissionReversee", "intitule" => "Rétro-commission Reversée", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "La somme des montants déjà versés au partenaire pour ce revenu."],
            ["group" => "Partage Partenaire", "code" => "retroCommissionSolde", "intitule" => "Rétro-commission Solde", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Le montant de la rétro-commission restant à verser au partenaire."],

            // Groupe 5: Résultat
            ["group" => "Résultat Final", "code" => "reserve", "intitule" => "Réserve Courtier", "type" => "Calcul", "format" => "Monetaire", "unite" => $monnaie, "description" => "Le revenu net final pour le courtier après déduction de la taxe courtier et de la rétro-commission du partenaire."],
        ];
    }
}