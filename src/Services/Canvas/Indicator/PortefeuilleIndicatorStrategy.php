<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Client;
use App\Entity\Portefeuille;

/**
 * Indicateurs agrégés d'un portefeuille : MÊME jeu d'attributs calculés que l'entité
 * Client (cf. ClientIndicatorStrategy), mais consolidé sur l'ensemble des clients
 * rattachés. Le périmètre est appliqué en une requête via l'option `portefeuilleCible`
 * du moteur de calcul (comme `groupeCible` pour un groupe).
 */
class PortefeuilleIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private IndicatorCalculationHelper $calculationHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Portefeuille::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Portefeuille $entity */
        if ($entity->getEntreprise() === null) {
            return ['nombreClients' => $entity->getClients()->count()];
        }

        $stats = $this->calculationHelper->getIndicateursGlobaux(
            $entity->getEntreprise(),
            false,
            ['portefeuilleCible' => $entity]
        );

        // Commission encaissée / solde.
        $commissionPayee = round($stats['commission_totale_encaissee'], 2);
        $commissionSolde = round($stats['commission_totale'] - $commissionPayee, 2);

        // Prime réglée : non persistée en BD, déduite du taux de règlement de la commission
        // (même logique que la fiche Client, pour une cohérence parfaite portefeuille ↔ client).
        $tauxReglement = $stats['commission_totale'] > 0
            ? min(1.0, max(0.0, $commissionPayee / $stats['commission_totale']))
            : 0.0;
        $primePayee = round($stats['prime_totale'] * $tauxReglement, 2);
        $primeSolde = round($stats['prime_totale'] - $primePayee, 2);
        $indiceSolvabilite = round($tauxReglement * 100, 2);

        return [
            // Activité
            'nombreClients'   => $entity->getClients()->count(),
            'nombrePistes'    => $this->countPistes($entity),
            'nombrePolices'   => $this->countPolices($entity),
            'nombreSinistres' => $this->countSinistres($entity),

            // Prime brutte
            'primeTotale'   => round($stats['prime_totale'], 2),
            'primePayee'    => $primePayee,
            'primeSoldeDue' => $primeSolde,

            // Revenu Brut
            'tauxCommission' => round($stats['taux_de_commission'], 2),
            'montantHT'      => round($stats['commission_nette'], 2),
            'montantTTC'     => round($stats['commission_totale'], 2),
            'detailCalcul'   => "Agrégation du portefeuille",

            // Taxes sur Commission
            'taxeCourtierMontant' => round($stats['taxe_courtier'], 2),
            'taxeAssureurMontant' => round($stats['taxe_assureur'], 2),

            // Facturation & Paiements
            'montant_du'        => round($stats['commission_totale'], 2),
            'montant_paye'      => $commissionPayee,
            'solde_restant_du'  => $commissionSolde,
            'taxeCourtierPayee' => round($stats['taxe_courtier_payee'], 2),
            'taxeCourtierSolde' => round($stats['taxe_courtier'] - $stats['taxe_courtier_payee'], 2),
            'taxeAssureurPayee' => round($stats['taxe_assureur_payee'], 2),
            'taxeAssureurSolde' => round($stats['taxe_assureur'] - $stats['taxe_assureur_payee'], 2),

            // Partage Partenaire
            'montantPur'              => round($stats['commission_pure'], 2),
            'retroCommission'         => round($stats['retro_commission_partenaire'], 2),
            'retroCommissionReversee' => round($stats['retro_commission_partenaire_payee'], 2),
            'retroCommissionSolde'    => round($stats['retro_commission_partenaire_solde'], 2),

            // Résultat Final
            'reserve' => round($stats['reserve'], 2),

            // Sinistralité
            'indemnisationDue'     => round($stats['sinistre_payable'], 2),
            'indemnisationVersee'  => round($stats['sinistre_paye'], 2),
            'indemnisationSolde'   => round($stats['sinistre_solde'], 2),
            'tauxSP'               => round($stats['taux_sinistralite'], 2),
            'tauxSPInterpretation' => $this->calculationHelper->getInterpretationTauxSP($stats['taux_sinistralite']),

            // Solvabilité
            'indiceSolvabilite'               => $indiceSolvabilite,
            'indiceSolvabiliteInterpretation' => $this->calculationHelper->getInterpretationIndiceSolvabilite($indiceSolvabilite),
        ];
    }

    private function countPistes(Portefeuille $portefeuille): int
    {
        $count = 0;
        foreach ($portefeuille->getClients() as $client) {
            $count += $client->getPistes()->count();
        }
        return $count;
    }

    private function countPolices(Portefeuille $portefeuille): int
    {
        $count = 0;
        foreach ($portefeuille->getClients() as $client) {
            $count += $this->countClientPolices($client);
        }
        return $count;
    }

    private function countSinistres(Portefeuille $portefeuille): int
    {
        $count = 0;
        foreach ($portefeuille->getClients() as $client) {
            $count += $client->getNotificationSinistres()->count();
        }
        return $count;
    }

    private function countClientPolices(Client $client): int
    {
        $count = 0;
        foreach ($client->getPistes() as $piste) {
            foreach ($piste->getCotations() as $cotation) {
                if (!$cotation->getAvenants()->isEmpty()) {
                    $count++;
                }
            }
        }
        return $count;
    }
}
