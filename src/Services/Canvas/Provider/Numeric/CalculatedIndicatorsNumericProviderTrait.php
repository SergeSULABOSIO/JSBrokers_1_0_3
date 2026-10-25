<?php

namespace App\Services\Canvas\Provider\Numeric;

trait CalculatedIndicatorsNumericProviderTrait
{
    /**
     * LES INDICATEURS CALCULÉS QUE PARTAGENT LES RUBRIQUES (CalculatedIndicatorsTrait), pour la
     * barre des totaux : tous des MONTANTS, en centimes (GrandeurTotalisable). Seuls ceux que
     * l'entité porte (property_exists) sont proposés.
     *
     * Taux de sinistralité, indice de solvabilité, taux de commission n'y sont PAS : non
     * additifs — une somme de taux n'est pas un taux (chantier 4, décision 1 de Serge).
     *
     * @return array<string, array{description: string, value: int, nature: string}>
     */
    private function getCalculatedIndicatorsNumericAttributes(object $object): array
    {
        $indicateurs = [
            'primeTotale' => 'Prime Totale',
            'primePayee' => 'Prime Payée',
            'primeSoldeDue' => 'Solde Prime',
            'montantTTC' => 'Commission Totale',
            'montant_paye' => 'Commission Encaissée',
            'solde_restant_du' => 'Solde Commission',
            'montantHT' => 'Commission Nette',
            'montantPur' => 'Commission Pure',
            'commissionPartageable' => 'Assiette Partageable',
            'primeNette' => 'Prime Nette',
            'reserve' => 'Réserve Courtier',
            'retroCommission' => 'Rétrocommission Partenaire',
            'retroCommissionReversee' => 'Rétrocommission Payée',
            'retroCommissionSolde' => 'Solde Rétrocommission',
            // ⚠ « Rétro Exigible » — le libellé EXISTANT de TrancheNumericCanvasProvider,
            // et non un synonyme. Ce tableau est fusionné PAR-DESSUS celui des providers
            // (array_merge, trait en second) : une description différente pour le même
            // code renomme silencieusement l'entrée de la barre des totaux d'une rubrique
            // qu'on ne touchait pas. Ket dérive de plus son vocabulaire de ces libellés.
            'retroCommissionExigible' => 'Rétro Exigible',
            // Rétrocommission des AGENTS INTERNES — l'autre bénéficiaire du partage.
            // Les inscrire ici suffit à les rendre totalisables : la barre des totaux
            // construit son sélecteur depuis ce dictionnaire.
            'retroAgentDue' => 'Rétrocom. agent due',
            'retroAgentPayee' => 'Rétrocom. agent payée',
            'retroAgentSolde' => 'Rétrocom. agent solde',
            'retroAgentExigible' => 'Rétrocom. agent exigible',
            'taxeCourtierMontant' => 'Taxe Courtier',
            'taxeCourtierPayee' => 'Taxe Courtier Payée',
            'taxeCourtierSolde' => 'Solde Taxe Courtier',
            'taxeAssureurMontant' => 'Taxe Assureur',
            'taxeAssureurPayee' => 'Taxe Assureur Payée',
            'taxeAssureurSolde' => 'Solde Taxe Assureur',
            'indemnisationDue' => 'Sinistre Payable',
            'indemnisationVersee' => 'Sinistre Payé',
            'indemnisationSolde' => 'Solde Sinistre',
        ];

        $attributs = [];
        foreach ($indicateurs as $code => $libelle) {
            if (property_exists($object, $code)) {
                $attributs[$code] = GrandeurTotalisable::montant($libelle, $object->$code ?? 0);
            }
        }

        return $attributs;
    }
}
