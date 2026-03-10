<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Contact;

class ContactIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private IndicatorCalculationHelper $calculationHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Contact::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Contact $entity */
        $indicateurs = [
            'type_string' => $this->Contact_getTypeString($entity)
        ];

        if ($client = $entity->getClient()) {
            $stats = $this->calculationHelper->getIndicateursGlobaux($client->getEntreprise(), false, ['clientCible' => $client]);

            $indicateurs = array_merge($indicateurs, [
                'primeTotale' => round($stats['prime_totale'], 2),
                'primePayee' => round($stats['prime_totale_payee'], 2),
                'primeSoldeDue' => round($stats['prime_totale_solde'], 2),
                'tauxCommission' => round($stats['taux_de_commission'], 2),
                'montantHT' => round($stats['commission_nette'], 2),
                'montantTTC' => round($stats['commission_totale'], 2),
                'detailCalcul' => "Agrégation du portefeuille client",

                'taxeCourtierMontant' => round($stats['taxe_courtier'], 2),
                'taxeAssureurMontant' => round($stats['taxe_assureur'], 2),

                'montant_du' => round($stats['commission_totale'], 2),
                'montant_paye' => round($stats['commission_totale_encaissee'], 2),
                'solde_restant_du' => round($stats['commission_totale_solde'], 2),

                'montantPur' => round($stats['commission_pure'], 2),
                'reserve' => round($stats['reserve'], 2),

                'indemnisationDue' => round($stats['sinistre_payable'], 2),
                'indemnisationVersee' => round($stats['sinistre_paye'], 2),
                'indemnisationSolde' => round($stats['sinistre_solde'], 2),
                'tauxSP' => round($stats['taux_sinistralite'], 2),
                'tauxSPInterpretation' => $this->calculationHelper->getInterpretationTauxSP($stats['taux_sinistralite']),
            ]);
        }

        return $indicateurs;
    }

    private function Contact_getTypeString(?Contact $contact): string
    {
        if ($contact === null) return 'Non défini';

        return match ($contact->getType()) {
            Contact::TYPE_CONTACT_PRODUCTION => "Production",
            Contact::TYPE_CONTACT_SINISTRE => "Sinistre",
            Contact::TYPE_CONTACT_ADMINISTRATION => "Administration",
            Contact::TYPE_CONTACT_AUTRES => "Autres",
            default => "Non défini",
        };
    }
}