<?php

namespace App\Services\Canvas;

use App\Services\ServiceMonnaies;

class CanvasHelper
{
    public function __construct(private ServiceMonnaies $serviceMonnaies)
    {
    }

    public function getGlobalIndicatorsCanvas(string $entityName): array
    {
        $indicators = [
            ['code' => 'prime_totale', 'intitule' => 'Prime Totale', 'description' => "Montant total de la prime brute due, toutes taxes et frais de chargement inclus, avant toute déduction.", 'is_percentage' => false],
            ['code' => 'prime_totale_payee', 'intitule' => 'Prime Payée', 'description' => "Cumul des paiements de prime déjà effectués par le client. Reflète l'état des encaissements.", 'is_percentage' => false],
            ['code' => 'prime_totale_solde', 'intitule' => 'Solde Prime', 'description' => "Montant de la prime totale qui reste à payer par le client. Indicateur clé du recouvrement.", 'is_percentage' => false],
            ['code' => 'commission_totale', 'intitule' => 'Commission Totale', 'description' => "Montant total de la commission TTC due au courtier, incluant toutes les taxes applicables sur la commission.", 'is_percentage' => false],
            ['code' => 'commission_totale_encaissee', 'intitule' => 'Commission Encaissée', 'description' => "Montant total de la commission que le courtier a effectivement déjà perçu, que ce soit de l'assureur ou du client.", 'is_percentage' => false],
            ['code' => 'commission_totale_solde', 'intitule' => 'Solde Commission', 'description' => "Montant de la commission totale qui reste à encaisser par le courtier. Essentiel pour la trésorerie.", 'is_percentage' => false],
            ['code' => 'commission_nette', 'intitule' => 'Commission Nette', 'description' => "Montant de la commission avant l'application des taxes (HT). C'est la base de calcul pour les impôts.", 'is_percentage' => false],
            ['code' => 'commission_pure', 'intitule' => 'Commission Pure', 'description' => "Commission nette après déduction des taxes à la charge du courtier. Représente le revenu brut du courtier.", 'is_percentage' => false],
            ['code' => 'commission_partageable', 'intitule' => 'Assiette Partageable', 'description' => "Part de la commission pure (sur revenus partageables) qui sert de base au calcul de la rétrocession due aux partenaires d'affaires.", 'is_percentage' => false],
            ['code' => 'prime_nette', 'intitule' => 'Prime Nette', 'description' => "Base de la prime utilisée pour le calcul des commissions. Exclut généralement les taxes et certains frais.", 'is_percentage' => false],
            ['code' => 'reserve', 'intitule' => 'Réserve Courtier', 'description' => "Bénéfice final revenant au courtier après paiement des taxes et des rétrocessions aux partenaires.", 'is_percentage' => false],
            ['code' => 'retro_commission_partenaire', 'intitule' => 'Rétrocommission Partenaire', 'description' => "Montant total de la commission à reverser aux partenaires d'affaires, calculé sur l'assiette partageable.", 'is_percentage' => false],
            ['code' => 'retro_commission_partenaire_payee', 'intitule' => 'Rétrocommission Payée', 'description' => "Montant de la rétrocommission qui a déjà été effectivement payé aux partenaires.", 'is_percentage' => false],
            ['code' => 'retro_commission_partenaire_solde', 'intitule' => 'Solde Rétrocommission', 'description' => "Montant de la rétrocommission qui reste à payer aux partenaires. Suivi des dettes envers les apporteurs.", 'is_percentage' => false],
            ['code' => 'taxe_courtier', 'intitule' => 'Taxe Courtier', 'description' => "Montant total des taxes dues par le courtier sur les commissions perçues. Une charge fiscale directe.", 'is_percentage' => false],
            ['code' => 'taxe_courtier_payee', 'intitule' => 'Taxe Courtier Payée', 'description' => "Montant des taxes sur commission que le courtier a déjà versées à l'autorité fiscale.", 'is_percentage' => false],
            ['code' => 'taxe_courtier_solde', 'intitule' => 'Solde Taxe Courtier', 'description' => "Montant des taxes sur commission restant à payer par le courtier à l'autorité fiscale.", 'is_percentage' => false],
            ['code' => 'taxe_assureur', 'intitule' => 'Taxe Assureur', 'description' => "Montant total des taxes dues par l'assureur sur les commissions. Le courtier agit souvent comme collecteur.", 'is_percentage' => false],
            ['code' => 'taxe_assureur_payee', 'intitule' => 'Taxe Assureur Payée', 'description' => "Montant des taxes sur commission que le courtier a déjà reversées à l'assureur (ou payées pour son compte).", 'is_percentage' => false],
            ['code' => 'taxe_assureur_solde', 'intitule' => 'Solde Taxe Assureur', 'description' => "Montant des taxes sur commission collectées par le courtier et restant à reverser à l'assureur.", 'is_percentage' => false],
            ['code' => 'sinistre_payable', 'intitule' => 'Sinistre Payable', 'description' => "Montant total des indemnisations convenues pour les sinistres survenus, avant tout paiement.", 'is_percentage' => false],
            ['code' => 'sinistre_paye', 'intitule' => 'Sinistre Payé', 'description' => "Montant total des indemnisations déjà versées aux assurés ou bénéficiaires pour les sinistres.", 'is_percentage' => false],
            ['code' => 'sinistre_solde', 'intitule' => 'Solde Sinistre', 'description' => "Montant des indemnisations qui reste à payer pour solder entièrement les dossiers sinistres.", 'is_percentage' => false],
            ['code' => 'taux_sinistralite', 'intitule' => 'Taux de Sinistralité', 'description' => "Rapport sinistres/primes (S/P). Évalue la qualité technique d'un risque ou d'un portefeuille.", 'is_percentage' => true],
            ['code' => 'taux_de_commission', 'intitule' => 'Taux de Commission', 'description' => "Rapport entre la commission nette et la prime nette. Mesure la rentabilité brute d'une affaire.", 'is_percentage' => true],
            ['code' => 'taux_de_retrocommission_effectif', 'intitule' => 'Taux Rétro. Effectif', 'description' => "Rapport entre la rétrocommission et l'assiette partageable. Mesure le coût réel du partenariat.", 'is_percentage' => true],
            ['code' => 'taux_de_paiement_prime', 'intitule' => 'Taux Paiement Prime', 'description' => "Pourcentage de la prime totale qui a été effectivement payé par le client. Indicateur de recouvrement.", 'is_percentage' => true],
            ['code' => 'taux_de_paiement_commission', 'intitule' => 'Taux Encaissement Comm.', 'description' => "Pourcentage de la commission totale qui a été effectivement encaissée par le courtier.", 'is_percentage' => true],
            ['code' => 'taux_de_paiement_retro_commission', 'intitule' => 'Taux Paiement Rétro.', 'description' => "Pourcentage de la rétrocommission due qui a été effectivement payée aux partenaires.", 'is_percentage' => true],
            ['code' => 'taux_de_paiement_taxe_courtier', 'intitule' => 'Taux Paiement Taxe Courtier', 'description' => "Pourcentage de la taxe courtier due qui a été effectivement payée à l'autorité fiscale.", 'is_percentage' => true],
            ['code' => 'taux_de_paiement_taxe_assureur', 'intitule' => 'Taux Paiement Taxe Assureur', 'description' => "Pourcentage de la taxe assureur due qui a été effectivement payée.", 'is_percentage' => true],
            ['code' => 'taux_de_paiement_sinistre', 'intitule' => 'Taux Paiement Sinistre', 'description' => "Pourcentage de l'indemnisation totale payable qui a déjà été versée aux sinistrés.", 'is_percentage' => true],
        ];

        $canvas = [];
        foreach ($indicators as $indicator) {
            $isPercentage = $indicator['is_percentage'];
            $camelCaseCode = str_replace('_', '', ucwords($indicator['code'], '_'));
            $canvas[] = [
                "code" => $indicator['code'],
                "intitule" => $indicator['intitule'],
                "type" => "Calcul",
                "format" => "Nombre",
                "unite" => $isPercentage ? "%" : $this->serviceMonnaies->getCodeMonnaieAffichage(),
                "fonction" => $entityName . '_get' . $camelCaseCode,
                "description" => $indicator['description']
            ];
        }
        return $canvas;
    }
}