<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Client;
use App\Entity\Note;
use App\Entity\Taxe;
use App\Repository\AvenantRepository;
use App\Repository\BordereauRepository;
use App\Repository\TaxeRepository;

class ClientIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private IndicatorCalculationHelper $calculationHelper,
        private TaxeRepository $taxeRepository,
        private AvenantRepository $avenantRepository,
        private BordereauRepository $bordereauRepository,
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Client::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Client $entity */
        if ($entity->getEntreprise() === null) return [];
        $stats = $this->calculationHelper->getIndicateursGlobaux($entity->getEntreprise(), false, ['clientCible' => $entity]);

        $bp = $this->getPayeeViaBordereaux($entity);

        $commissionPayee   = round($stats['commission_totale_encaissee'] + $bp['total'], 2);
        $commissionSolde   = round($stats['commission_totale'] - $commissionPayee, 2);
        $taxeAssPayee      = round($stats['taxe_assureur_payee'] + $bp['taxeAssureur'], 2);
        $taxeAssSolde      = round($stats['taxe_assureur'] - $taxeAssPayee, 2);
        $taxeCourtierPayee = round($stats['taxe_courtier_payee'] + $bp['taxeCourtier'], 2);
        $taxeCourtierSolde = round($stats['taxe_courtier'] - $taxeCourtierPayee, 2);

        // Primes réglées : non persistées en BD, déduites du taux de règlement de la commission.
        // L'assureur encaisse la prime TTC puis reverse la commission au prorata des encaissements
        // (bordereaux ou articles) : commission payée à X % ⇒ prime réglée à X %.
        $tauxReglement = $stats['commission_totale'] > 0
            ? min(1.0, max(0.0, $commissionPayee / $stats['commission_totale']))
            : 0.0;
        $primePayee = round($stats['prime_totale'] * $tauxReglement, 2);
        $primeSolde = round($stats['prime_totale'] - $primePayee, 2);

        // Indice de solvabilité : part des primes émises effectivement réglée par le client
        $indiceSolvabilite = round($tauxReglement * 100, 2);

        return [
            'civiliteString' => $this->getClientCiviliteString($entity),
            'groupeNom' => $entity->getGroupe() ? $entity->getGroupe()->getNom() : 'Aucun groupe',
            'nombrePistes' => $entity->getPistes()->count(),
            'nombreSinistres' => $entity->getNotificationSinistres()->count(),
            'nombrePolices' => $this->countClientPolices($entity),

            // Mapping des stats globales vers les attributs de l'entité
            'primeTotale' => round($stats['prime_totale'], 2),
            'primePayee' => $primePayee,
            'primeSoldeDue' => $primeSolde,
            'tauxCommission' => round($stats['taux_de_commission'], 2),
            'montantHT' => round($stats['commission_nette'], 2),
            'montantTTC' => round($stats['commission_totale'], 2),
            'detailCalcul' => "Agrégation portefeuille",

            'taxeCourtierMontant' => round($stats['taxe_courtier'], 2),
            'taxeAssureurMontant' => round($stats['taxe_assureur'], 2),
            'taxeCourtierCode' => $this->getTaxeCode($entity, Taxe::REDEVABLE_COURTIER),
            'taxeCourtierTaux' => $this->getTaxeTaux($entity, Taxe::REDEVABLE_COURTIER),
            'taxeAssureurCode' => $this->getTaxeCode($entity, Taxe::REDEVABLE_ASSUREUR),
            'taxeAssureurTaux' => $this->getTaxeTaux($entity, Taxe::REDEVABLE_ASSUREUR),

            'montant_du' => round($stats['commission_totale'], 2),
            'montant_paye' => $commissionPayee,
            'solde_restant_du' => $commissionSolde,

            'taxeCourtierPayee' => $taxeCourtierPayee,
            'taxeCourtierSolde' => $taxeCourtierSolde,
            'taxeAssureurPayee' => $taxeAssPayee,
            'taxeAssureurSolde' => $taxeAssSolde,

            'montantPur' => round($stats['commission_pure'], 2),
            'retroCommission' => round($stats['retro_commission_partenaire'], 2),
            'retroCommissionReversee' => round($stats['retro_commission_partenaire_payee'], 2),
            'retroCommissionSolde' => round($stats['retro_commission_partenaire_solde'], 2),
            'reserve' => round($stats['reserve'], 2),

            // Sinistralité
            'indemnisationDue' => round($stats['sinistre_payable'], 2),
            'indemnisationVersee' => round($stats['sinistre_paye'], 2),
            'indemnisationSolde' => round($stats['sinistre_solde'], 2),
            'tauxSP' => round($stats['taux_sinistralite'], 2),
            'tauxSPInterpretation' => $this->calculationHelper->getInterpretationTauxSP($stats['taux_sinistralite']),

            // Solvabilité
            'indiceSolvabilite' => $indiceSolvabilite,
            'indiceSolvabiliteInterpretation' => $this->calculationHelper->getInterpretationIndiceSolvabilite($indiceSolvabilite),
        ];
    }

    private function getTaxeCode(Client $entity, int $redevable): string
    {
        $taxe = $this->taxeRepository->findOneBy([
            'redevable'  => $redevable,
            'entreprise' => $entity->getEntreprise(),
        ]);
        return $taxe?->getCode() ?? ($redevable === Taxe::REDEVABLE_COURTIER ? 'Taxe courtier' : 'Taxe assureur');
    }

    private function getTaxeTaux(Client $entity, int $redevable): float
    {
        $taxe = $this->taxeRepository->findOneBy([
            'redevable'  => $redevable,
            'entreprise' => $entity->getEntreprise(),
        ]);
        return (float) ($taxe?->getTauxIARD() ?? 0.0);
    }

    private function getPayeeViaBordereaux(Client $entity): array
    {
        $zero = ['total' => 0.0, 'taxeAssureur' => 0.0, 'taxeCourtier' => 0.0];

        if ($entity->getEntreprise() === null) return $zero;

        // Collect all avenant IDs belonging to this client
        $clientAvenantIds = [];
        foreach ($entity->getPistes() as $piste) {
            foreach ($piste->getCotations() as $cotation) {
                foreach ($cotation->getAvenants() as $avenant) {
                    $clientAvenantIds[] = $avenant->getId();
                }
            }
        }
        if (empty($clientAvenantIds)) return $zero;

        $bordereaux = $this->bordereauRepository->findBy(['entreprise' => $entity->getEntreprise()]);

        $totalCommission   = 0.0;
        $totalTaxeAss      = 0.0;
        $totalTaxeCourtier = 0.0;

        foreach ($bordereaux as $bordereau) {
            $results = $bordereau->getAnalysisResults() ?? [];
            if (empty($results)) continue;

            $allIds    = array_values(array_unique(array_filter(array_column($results, 'avenant_id'))));
            $clientIds = array_values(array_intersect($allIds, $clientAvenantIds));
            if (empty($clientIds)) continue;

            // Load all avenants in one query to avoid N+1
            $avenants   = $this->avenantRepository->findBy(['id' => $allIds]);
            $avenantMap = [];
            foreach ($avenants as $av) {
                $avenantMap[$av->getId()] = $av;
            }

            $totalHT    = 0.0;
            $totalTaxe  = 0.0;
            $clientHT   = 0.0;
            $clientTaxe = 0.0;

            foreach ($allIds as $aid) {
                $av  = $avenantMap[$aid] ?? null;
                $cot = $av?->getCotation();
                if (!$cot) continue;

                $ht   = $this->calculationHelper->getCotationMontantCommissionHt($cot, -1, false);
                $taxe = $this->calculationHelper->getCotationMontantTaxeAssureur($cot, false);

                $totalHT   += $ht;
                $totalTaxe += $taxe;

                if (in_array($aid, $clientIds, true)) {
                    $clientHT   += $ht;
                    $clientTaxe += $taxe;
                }
            }

            $totalBordereau = $totalHT + $totalTaxe;
            if ($totalBordereau <= 0.0) continue;

            // Categorize payments per note type — avoids inaccurate ratio-based splitting
            $bordCommPaid      = 0.0;
            $bordTaxeAssPaid   = 0.0;
            $bordTaxeCourtPaid = 0.0;

            foreach ($bordereau->getNotes() as $note) {
                $notePaid = $this->calculationHelper->getNoteMontantPaye($note);
                if ($notePaid <= 0.0) continue;

                $at = $note->getAddressedTo();
                if ($at === Note::TO_ASSUREUR || $at === Note::TO_CLIENT) {
                    $bordCommPaid += $notePaid;
                } elseif ($at === Note::TO_AUTORITE_FISCALE) {
                    $taxe = $note->getAutoritefiscale()?->getTaxe();
                    if ($taxe?->getRedevable() === Taxe::REDEVABLE_ASSUREUR) {
                        $bordTaxeAssPaid += $notePaid;
                    } elseif ($taxe?->getRedevable() === Taxe::REDEVABLE_COURTIER) {
                        $bordTaxeCourtPaid += $notePaid;
                    }
                }
            }

            $propHT   = $totalHT   > 0.0 ? $clientHT   / $totalHT   : 0.0;
            $propTaxe = $totalTaxe > 0.0 ? $clientTaxe / $totalTaxe : 0.0;

            $totalCommission   += $propHT   * $bordCommPaid;
            $totalTaxeAss      += $propTaxe * $bordTaxeAssPaid;
            $totalTaxeCourtier += $propHT   * $bordTaxeCourtPaid;
        }

        return [
            'total'        => round($totalCommission + $totalTaxeAss + $totalTaxeCourtier, 2),
            'taxeAssureur' => round($totalTaxeAss, 2),
            'taxeCourtier' => round($totalTaxeCourtier, 2),
        ];
    }

    private function getClientCiviliteString(?Client $client): ?string
    {
        if ($client === null || $client->getCivilite() === null) return null;

        return match ($client->getCivilite()) {
            Client::CIVILITE_Mr => "Monsieur",
            Client::CIVILITE_Mme => "Madame",
            Client::CIVILITE_ENTREPRISE => "Entreprise",
            Client::CIVILITE_ASBL => "ASBL",
            default => "Inconnue",
        };
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