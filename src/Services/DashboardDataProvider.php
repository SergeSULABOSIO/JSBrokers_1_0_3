<?php

namespace App\Services;

use App\Entity\Avenant;
use App\Entity\Bordereau;
use App\Entity\Entreprise;
use App\Entity\Tache;
use App\Entity\Taxe;
use App\Repository\TaxeRepository;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\Canvas\Indicator\AvenantIndicatorStrategy;
use App\Services\Canvas\Provider\Entity\AvenantEntityCanvasProvider;
use App\Services\CanvasBuilder;
use Doctrine\ORM\EntityManagerInterface;

class DashboardDataProvider
{
    public function __construct(
        private EntityManagerInterface $em,
        private CanvasBuilder $canvasBuilder,
        private TaxeRepository $taxeRepository,
        private AvenantEntityCanvasProvider $avenantCanvasProvider,
        private IndicatorCalculationHelper $calculationHelper,
    ) {}

    private array $cacheAvenantsActifs = [];

    private function getAvenantsActifsHydrates(Entreprise $entreprise): array
    {
        $key = $entreprise->getId();
        if (isset($this->cacheAvenantsActifs[$key])) {
            return $this->cacheAvenantsActifs[$key];
        }

        $avenants = $this->em->createQuery(
            'SELECT a, cot, ass, p, cl, r, par
             FROM App\Entity\Avenant a
             LEFT JOIN a.cotation cot
             LEFT JOIN cot.assureur ass
             LEFT JOIN cot.piste p
             LEFT JOIN p.client cl
             LEFT JOIN p.risque r
             LEFT JOIN p.partenaires par
             WHERE a.entreprise = :e AND a.renewalStatus = :status'
        )
        ->setParameter('e', $entreprise)
        ->setParameter('status', Avenant::RENEWAL_STATUS_RUNNING)
        ->getResult();

        foreach ($avenants as $avenant) {
            $this->canvasBuilder->loadAllCalculatedValues($avenant);
        }

        return $this->cacheAvenantsActifs[$key] = $avenants;
    }

    public function getPrimesTotales(Entreprise $entreprise): float
    {
        $total = 0.0;
        foreach ($this->getAvenantsActifsHydrates($entreprise) as $avenant) {
            $total += (float) ($avenant->primeTotale ?? 0);
        }
        return $total;
    }

    public function getRetrocommissionsTotales(Entreprise $entreprise): float
    {
        $total = 0.0;
        foreach ($this->getAvenantsActifsHydrates($entreprise) as $avenant) {
            $total += (float) ($avenant->retroCommission ?? 0);
        }
        return $total;
    }

    public function getTaxesTotales(Entreprise $entreprise): float
    {
        $total = 0.0;
        foreach ($this->getAvenantsActifsHydrates($entreprise) as $avenant) {
            $total += (float) ($avenant->taxeCourtierMontant ?? 0);
        }
        return $total;
    }

    public function getCommissionsTotales(Entreprise $entreprise): float
    {
        $total = 0.0;
        foreach ($this->getAvenantsActifsHydrates($entreprise) as $avenant) {
            $total += (float) ($avenant->montantTTC ?? 0);
        }
        return $total;
    }

    public function getPaiementsTotaux(Entreprise $entreprise, \DateTimeImmutable $debut, \DateTimeImmutable $fin): float
    {
        $result = $this->em->createQuery(
            'SELECT SUM(p.montant) FROM App\Entity\Paiement p
             WHERE p.entreprise = :e
               AND p.paidAt >= :debut AND p.paidAt <= :fin'
        )
        ->setParameter('e', $entreprise)
        ->setParameter('debut', $debut)
        ->setParameter('fin', $fin)
        ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }

    public function getPoliciesActives(Entreprise $entreprise): int
    {
        $result = $this->em->createQuery(
            'SELECT COUNT(a.id) FROM App\Entity\Avenant a
             WHERE a.entreprise = :e
               AND a.renewalStatus = :status'
        )
        ->setParameter('e', $entreprise)
        ->setParameter('status', Avenant::RENEWAL_STATUS_RUNNING)
        ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    public function getRenouvellements30j(Entreprise $entreprise): array
    {
        return $this->em->createQuery(
            'SELECT a, c, ass FROM App\Entity\Avenant a
             JOIN a.cotation c
             JOIN c.assureur ass
             WHERE a.entreprise = :e
               AND a.endingAt BETWEEN :debut AND :fin
             ORDER BY a.endingAt ASC'
        )
        ->setParameter('e', $entreprise)
        ->setParameter('debut', new \DateTimeImmutable('now'))
        ->setParameter('fin',   new \DateTimeImmutable('+30 days'))
        ->setMaxResults(3)
        ->getResult();
    }

    public function getAllRenouvellements(Entreprise $entreprise, int $maxDays = 365): array
    {
        $avenants = $this->em->createQuery(
            'SELECT a, c, ass, p, cl, r, pdr FROM App\Entity\Avenant a
             JOIN a.cotation c
             JOIN c.assureur ass
             LEFT JOIN c.piste p
             LEFT JOIN p.client cl
             LEFT JOIN p.risque r
             LEFT JOIN a.pisteDeRenouvellement pdr
             WHERE a.entreprise = :e
               AND a.endingAt BETWEEN :debut AND :fin
               AND (p.renewalCondition IN (0, 1) OR p IS NULL OR p.renewalCondition IS NULL)
               AND (pdr IS NULL OR NOT EXISTS (
                   SELECT 1 FROM App\Entity\Avenant a2
                   JOIN a2.cotation c2
                   JOIN c2.piste p2
                   WHERE p2 = pdr
                   AND a2.renewalStatus NOT IN (0, 6)
               ))
             ORDER BY a.endingAt ASC'
        )
        ->setParameter('e', $entreprise)
        ->setParameter('debut', new \DateTimeImmutable('now'))
        ->setParameter('fin',   new \DateTimeImmutable('+' . $maxDays . ' days'))
        ->getResult();

        foreach ($avenants as $avenant) {
            $this->canvasBuilder->loadAllCalculatedValues($avenant);
        }

        return $avenants;
    }

    public function getProductionParMois(Entreprise $entreprise, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $paiements = $this->em->createQuery(
            'SELECT p.montant, p.paidAt FROM App\Entity\Paiement p
             WHERE p.entreprise = :e
               AND p.paidAt >= :debut AND p.paidAt <= :fin'
        )
        ->setParameter('e', $entreprise)
        ->setParameter('debut', $debut)
        ->setParameter('fin', $fin)
        ->getResult();

        $labels = ['Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'];
        $data   = array_fill(0, 12, 0.0);
        foreach ($paiements as $p) {
            $mois = (int) $p['paidAt']->format('n') - 1;
            $data[$mois] += (float) $p['montant'];
        }

        return ['labels' => $labels, 'data' => $data];
    }

    private function getAvenantsParAssureur(Entreprise $entreprise): array
    {
        $grouped = [];
        foreach ($this->getAvenantsActifsHydrates($entreprise) as $avenant) {
            $assureur = $avenant->getCotation()?->getAssureur();
            if (!$assureur) continue;
            $assId = $assureur->getId();

            if (!isset($grouped[$assId])) {
                $grouped[$assId] = [
                    'id'              => $assId,
                    'nom'             => $assureur->getNom(),
                    'nbPolices'       => 0,
                    'clientIds'       => [],
                    'primesTotales'   => 0.0,
                    'commissionsTtc'  => 0.0,
                    'taxeAssureur'    => 0.0,
                    'taxeCourtier'    => 0.0,
                    'retrocommission' => 0.0,
                    'reserve'         => 0.0,
                ];
            }

            $grouped[$assId]['nbPolices']++;
            $clientId = $avenant->getCotation()->getPiste()?->getClient()?->getId();
            if ($clientId) $grouped[$assId]['clientIds'][$clientId] = true;
            $grouped[$assId]['primesTotales']   += (float) ($avenant->primeTotale ?? 0);
            $grouped[$assId]['commissionsTtc']  += (float) ($avenant->montantTTC ?? 0);
            $grouped[$assId]['taxeAssureur']     += (float) ($avenant->taxeAssureurMontant ?? 0);
            $grouped[$assId]['taxeCourtier']     += (float) ($avenant->taxeCourtierMontant ?? 0);
            $grouped[$assId]['retrocommission']  += (float) ($avenant->retroCommission ?? 0);
            $grouped[$assId]['reserve']          += (float) ($avenant->reserve ?? 0);
        }

        foreach ($grouped as &$row) {
            $row['nbClients'] = count($row['clientIds']);
            unset($row['clientIds']);
        }
        unset($row);

        return $grouped;
    }

    private function getSinistresParAssureur(Entreprise $entreprise): array
    {
        $rows = $this->em->createQuery(
            'SELECT ass.id as assId,
                    SUM(ois.montantPayable) as montantIndemnise
             FROM App\Entity\NotificationSinistre ns
             JOIN ns.assureur ass
             LEFT JOIN ns.offreIndemnisationSinistres ois
             WHERE ns.entreprise = :e
             GROUP BY ass.id'
        )
        ->setParameter('e', $entreprise)
        ->getResult();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int) $row['assId']] = (float) ($row['montantIndemnise'] ?? 0);
        }
        return $indexed;
    }

    public function getTopAssureursAvecIndicateurs(Entreprise $entreprise): array
    {
        $parAssureur = $this->getAvenantsParAssureur($entreprise);
        $sinistres   = $this->getSinistresParAssureur($entreprise);
        $totalPrimes = $this->getPrimesTotales($entreprise);

        foreach ($parAssureur as &$row) {
            $sin = $sinistres[$row['id']] ?? 0.0;
            $row['sinistresIndemnises'] = $sin;
            $row['ratioSP']   = $row['primesTotales'] > 0 ? round($sin / $row['primesTotales'] * 100, 1) : 0.0;
            $row['partMarche'] = $totalPrimes > 0 ? round($row['primesTotales'] / $totalPrimes * 100, 1) : 0.0;
        }
        unset($row);

        usort($parAssureur, fn($a, $b) => $b['primesTotales'] <=> $a['primesTotales']);

        return $this->sliceAvecRestes(array_values($parAssureur));
    }

    private function getAvenantsParClient(Entreprise $entreprise): array
    {
        $grouped = [];
        foreach ($this->getAvenantsActifsHydrates($entreprise) as $avenant) {
            $client = $avenant->getCotation()?->getPiste()?->getClient();
            if (!$client) continue;
            $clientId = $client->getId();

            if (!isset($grouped[$clientId])) {
                $grouped[$clientId] = [
                    'id'              => $clientId,
                    'nom'             => $client->getNom(),
                    'nbPolices'       => 0,
                    'primesTotales'   => 0.0,
                    'commissionsTtc'  => 0.0,
                    'taxeAssureur'    => 0.0,
                    'taxeCourtier'    => 0.0,
                    'retrocommission' => 0.0,
                    'reserve'         => 0.0,
                ];
            }

            $grouped[$clientId]['nbPolices']++;
            $grouped[$clientId]['primesTotales']   += (float) ($avenant->primeTotale ?? 0);
            $grouped[$clientId]['commissionsTtc']  += (float) ($avenant->montantTTC ?? 0);
            $grouped[$clientId]['taxeAssureur']     += (float) ($avenant->taxeAssureurMontant ?? 0);
            $grouped[$clientId]['taxeCourtier']     += (float) ($avenant->taxeCourtierMontant ?? 0);
            $grouped[$clientId]['retrocommission']  += (float) ($avenant->retroCommission ?? 0);
            $grouped[$clientId]['reserve']          += (float) ($avenant->reserve ?? 0);
        }

        return $grouped;
    }

    private function getSinistresParClient(Entreprise $entreprise): array
    {
        $rows = $this->em->createQuery(
            'SELECT cl.id as clientId,
                    SUM(ois.montantPayable) as montantIndemnise
             FROM App\Entity\NotificationSinistre ns
             JOIN ns.assure cl
             LEFT JOIN ns.offreIndemnisationSinistres ois
             WHERE ns.entreprise = :e
             GROUP BY cl.id'
        )
        ->setParameter('e', $entreprise)
        ->getResult();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int) $row['clientId']] = (float) ($row['montantIndemnise'] ?? 0);
        }
        return $indexed;
    }

    public function getTopAssuresAvecIndicateurs(Entreprise $entreprise): array
    {
        $parClient   = $this->getAvenantsParClient($entreprise);
        $sinistres   = $this->getSinistresParClient($entreprise);
        $totalPrimes = $this->getPrimesTotales($entreprise);

        foreach ($parClient as &$row) {
            $sin = $sinistres[$row['id']] ?? 0.0;
            $row['sinistresIndemnises'] = $sin;
            $row['ratioSP']   = $row['primesTotales'] > 0 ? round($sin / $row['primesTotales'] * 100, 1) : 0.0;
            $row['partMarche'] = $totalPrimes > 0 ? round($row['primesTotales'] / $totalPrimes * 100, 1) : 0.0;
        }
        unset($row);

        usort($parClient, fn($a, $b) => $b['primesTotales'] <=> $a['primesTotales']);

        return $this->sliceAvecRestes(array_values($parClient));
    }

    private function sliceAvecRestes(array $sorted, int $top = 9): array
    {
        if (count($sorted) <= $top) {
            return $sorted;
        }

        $topItems = array_slice($sorted, 0, $top);
        $reste    = array_slice($sorted, $top);

        $restes = [
            'id'                => 0,
            'nom'               => 'Les restes',
            'nbPolices'         => 0,
            'primesTotales'     => 0.0,
            'commissionsTtc'    => 0.0,
            'taxeAssureur'      => 0.0,
            'taxeCourtier'      => 0.0,
            'retrocommission'   => 0.0,
            'reserve'           => 0.0,
            'sinistresIndemnises' => 0.0,
        ];

        foreach ($reste as $r) {
            foreach (['nbPolices', 'primesTotales', 'commissionsTtc', 'taxeAssureur',
                      'taxeCourtier', 'retrocommission', 'reserve', 'sinistresIndemnises'] as $k) {
                $restes[$k] += $r[$k] ?? 0;
            }
            foreach (['nbClients', 'nbAssureurs', 'nbAssures'] as $extra) {
                if (isset($r[$extra])) {
                    $restes[$extra] = ($restes[$extra] ?? 0) + $r[$extra];
                }
            }
        }

        $restes['ratioSP']    = $restes['primesTotales'] > 0
            ? round($restes['sinistresIndemnises'] / $restes['primesTotales'] * 100, 1)
            : 0.0;
        $restes['partMarche'] = round(array_sum(array_column($reste, 'partMarche')), 1);

        return array_merge($topItems, [$restes]);
    }

    private function getAvenantsParRisque(Entreprise $entreprise): array
    {
        $grouped = [];
        foreach ($this->getAvenantsActifsHydrates($entreprise) as $avenant) {
            $risque = $avenant->getCotation()?->getPiste()?->getRisque();
            if (!$risque) continue;
            $risqueId = $risque->getId();

            if (!isset($grouped[$risqueId])) {
                $grouped[$risqueId] = [
                    'id'              => $risqueId,
                    'nom'             => $risque->getNomComplet(),
                    'nbPolices'       => 0,
                    'primesTotales'   => 0.0,
                    'commissionsTtc'  => 0.0,
                    'taxeAssureur'    => 0.0,
                    'taxeCourtier'    => 0.0,
                    'retrocommission' => 0.0,
                    'reserve'         => 0.0,
                ];
            }

            $grouped[$risqueId]['nbPolices']++;
            $grouped[$risqueId]['primesTotales']   += (float) ($avenant->primeTotale ?? 0);
            $grouped[$risqueId]['commissionsTtc']  += (float) ($avenant->montantTTC ?? 0);
            $grouped[$risqueId]['taxeAssureur']     += (float) ($avenant->taxeAssureurMontant ?? 0);
            $grouped[$risqueId]['taxeCourtier']     += (float) ($avenant->taxeCourtierMontant ?? 0);
            $grouped[$risqueId]['retrocommission']  += (float) ($avenant->retroCommission ?? 0);
            $grouped[$risqueId]['reserve']          += (float) ($avenant->reserve ?? 0);
        }

        return $grouped;
    }

    private function getSinistresParRisque(Entreprise $entreprise): array
    {
        $rows = $this->em->createQuery(
            'SELECT r.id as risqueId,
                    SUM(ois.montantPayable) as montantIndemnise
             FROM App\Entity\NotificationSinistre ns
             JOIN ns.risque r
             LEFT JOIN ns.offreIndemnisationSinistres ois
             WHERE ns.entreprise = :e
             GROUP BY r.id'
        )
        ->setParameter('e', $entreprise)
        ->getResult();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int) $row['risqueId']] = (float) ($row['montantIndemnise'] ?? 0);
        }
        return $indexed;
    }

    public function getTopRisquesAvecIndicateurs(Entreprise $entreprise): array
    {
        $parRisque   = $this->getAvenantsParRisque($entreprise);
        $sinistres   = $this->getSinistresParRisque($entreprise);
        $totalPrimes = $this->getPrimesTotales($entreprise);

        foreach ($parRisque as &$row) {
            $sin = $sinistres[$row['id']] ?? 0.0;
            $row['sinistresIndemnises'] = $sin;
            $row['ratioSP']   = $row['primesTotales'] > 0 ? round($sin / $row['primesTotales'] * 100, 1) : 0.0;
            $row['partMarche'] = $totalPrimes > 0 ? round($row['primesTotales'] / $totalPrimes * 100, 1) : 0.0;
        }
        unset($row);

        usort($parRisque, fn($a, $b) => $b['primesTotales'] <=> $a['primesTotales']);

        return $this->sliceAvecRestes(array_values($parRisque));
    }

    private function getAvenantsParPartenaire(Entreprise $entreprise): array
    {
        $grouped = [];
        foreach ($this->getAvenantsActifsHydrates($entreprise) as $avenant) {
            $piste = $avenant->getCotation()?->getPiste();
            if (!$piste) continue;
            foreach ($piste->getPartenaires() as $partenaire) {
                $parId = $partenaire->getId();
                if (!isset($grouped[$parId])) {
                    $grouped[$parId] = [
                        'id'              => $parId,
                        'nom'             => $partenaire->getNom(),
                        'nbPolices'       => 0,
                        'primesTotales'   => 0.0,
                        'commissionsTtc'  => 0.0,
                        'taxeAssureur'    => 0.0,
                        'taxeCourtier'    => 0.0,
                        'retrocommission' => 0.0,
                        'reserve'         => 0.0,
                    ];
                }
                $grouped[$parId]['nbPolices']++;
                $grouped[$parId]['primesTotales']   += (float) ($avenant->primeTotale ?? 0);
                $grouped[$parId]['commissionsTtc']  += (float) ($avenant->montantTTC ?? 0);
                $grouped[$parId]['taxeAssureur']     += (float) ($avenant->taxeAssureurMontant ?? 0);
                $grouped[$parId]['taxeCourtier']     += (float) ($avenant->taxeCourtierMontant ?? 0);
                $grouped[$parId]['retrocommission']  += (float) ($avenant->retroCommission ?? 0);
                $grouped[$parId]['reserve']          += (float) ($avenant->reserve ?? 0);
            }
        }

        return $grouped;
    }

    public function getTopIntermediairesAvecIndicateurs(Entreprise $entreprise): array
    {
        $parPartenaire = $this->getAvenantsParPartenaire($entreprise);
        $totalRetro    = $this->getRetrocommissionsTotales($entreprise);

        foreach ($parPartenaire as &$row) {
            $row['sinistresIndemnises'] = 0.0;
            $row['ratioSP']   = 0.0;
            $row['partMarche'] = $totalRetro > 0 ? round($row['retrocommission'] / $totalRetro * 100, 1) : 0.0;
        }
        unset($row);

        usort($parPartenaire, fn($a, $b) => $b['retrocommission'] <=> $a['retrocommission']);

        return $this->sliceAvecRestes(array_values($parPartenaire));
    }

    /**
     * Total des paiements reçus sur les notes de commission (adressées au client ou à l'assureur),
     * toutes années confondues — sert au calcul du solde restant dû.
     */
    private function getTotalEncaisseCommissions(Entreprise $entreprise): float
    {
        $result = $this->em->createQuery(
            'SELECT COALESCE(SUM(p.montant), 0)
             FROM App\Entity\Paiement p
             JOIN p.note n
             WHERE p.entreprise = :e
               AND n.addressedTo IN (0, 1)'
        )
        ->setParameter('e', $entreprise)
        ->getSingleScalarResult();

        return (float) $result;
    }

    public function getRevenusPercusBreakdown(Entreprise $entreprise): array
    {
        $ttc               = 0.0;
        $ht                = 0.0;
        $taxeCourtierTotal = 0.0;
        $taxeAssureurTotal = 0.0;
        $retrocom          = 0.0;
        $reserve           = 0.0;

        foreach ($this->getAvenantsActifsHydrates($entreprise) as $avenant) {
            $ttc               += (float) ($avenant->montantTTC          ?? 0);
            $ht                += (float) ($avenant->montantHT           ?? 0);
            $taxeCourtierTotal += (float) ($avenant->taxeCourtierMontant ?? 0);
            $taxeAssureurTotal += (float) ($avenant->taxeAssureurMontant ?? 0);
            $retrocom          += (float) ($avenant->retroCommission      ?? 0);
            $reserve           += (float) ($avenant->reserve             ?? 0);
        }

        $pur = $ht - $taxeCourtierTotal;

        $totalEncaisse = $this->getTotalEncaisseCommissions($entreprise);
        $solde = max(0.0, $ttc - $totalEncaisse);

        $taxeCourtierEntity = $this->taxeRepository->findOneBy([
            'redevable'  => Taxe::REDEVABLE_COURTIER,
            'entreprise' => $entreprise,
        ]);
        $taxeAssureurEntity = $this->taxeRepository->findOneBy([
            'redevable'  => Taxe::REDEVABLE_ASSUREUR,
            'entreprise' => $entreprise,
        ]);

        $desc = $this->getAvenantDescriptions();

        return [
            'ttc'          => round($ttc, 2),
            'ht'           => round($ht, 2),
            'taxeCourtier' => [
                'montant'     => round($taxeCourtierTotal, 2),
                'nom'         => $taxeCourtierEntity?->getCode() ?? 'Taxe courtier',
                'description' => strip_tags($taxeCourtierEntity?->getDescription() ?? ''),
                'taux'        => (float) ($taxeCourtierEntity?->getTauxIARD() ?? 0),
                'tip'         => $this->buildTaxTip($taxeCourtierEntity, "Taxe réglementaire à votre charge, calculée sur votre commission.\nRetenue sur le Revenu TTC et reversée à l'administration fiscale."),
            ],
            'taxeAssureur' => [
                'montant'     => round($taxeAssureurTotal, 2),
                'nom'         => $taxeAssureurEntity?->getCode() ?? 'Taxe assureur',
                'description' => strip_tags($taxeAssureurEntity?->getDescription() ?? ''),
                'taux'        => (float) ($taxeAssureurEntity?->getTauxIARD() ?? 0),
                'tip'         => $this->buildTaxTip($taxeAssureurEntity, "Taxe à la charge de l'assureur sur les primes, collectée par le courtier.\nReversée à l'administration fiscale."),
            ],
            'pur'         => round($pur, 2),
            'retrocom'    => round($retrocom, 2),
            'reserve'     => round($reserve, 2),
            'solde'       => round($solde, 2),
            'tipTTC'      => $desc['montantTTC']       ?? '',
            'tipHT'       => $desc['montantHT']        ?? '',
            'tipPur'      => $desc['montantPur']       ?? '',
            'tipRetrocom' => $desc['retroCommission']  ?? '',
            'tipReserve'  => $desc['reserve']          ?? '',
            'tipSolde'    => $desc['solde_restant_du'] ?? '',
        ];
    }

    private function buildTaxTip(?Taxe $taxe, string $fallback): string
    {
        if ($taxe === null) {
            return $fallback;
        }
        $lines = [strip_tags($taxe->getDescription() ?? '')];
        $taux = (float) ($taxe->getTauxIARD() ?? 0);
        if ($taux > 0) {
            $formatted = (fmod($taux, 1.0) === 0.0)
                ? number_format($taux, 0)
                : number_format($taux, 1, ',');
            $lines[] = 'Taux : ' . $formatted . ' %';
        }
        foreach ($taxe->getAutoriteFiscales() as $autorite) {
            $nom = $autorite->getNom() ?? '';
            $abr = $autorite->getAbreviation() ?? '';
            if ($nom) {
                $lines[] = 'Autorité : ' . $nom . ($abr ? ' (' . $abr . ')' : '');
            }
        }
        return implode("\n", array_filter($lines));
    }

    private function getAvenantDescriptions(): array
    {
        $map = [];
        foreach ($this->avenantCanvasProvider->getCanvas()['liste'] ?? [] as $item) {
            if (isset($item['code'], $item['description'])) {
                $map[$item['code']] = $item['description'];
            }
        }
        return $map;
    }

    public function getTachesNonCloses(Entreprise $entreprise, int $limit = 100): array
    {
        $taches = $this->em->createQuery(
            'SELECT t FROM App\Entity\Tache t
             WHERE t.entreprise = :e AND t.closed = false'
        )
        ->setParameter('e', $entreprise)
        ->setMaxResults($limit)
        ->getResult();

        usort($taches, function (Tache $a, Tache $b) {
            $aDate = $a->getToBeEndedAt();
            $bDate = $b->getToBeEndedAt();
            if ($aDate === null && $bDate === null) return 0;
            if ($aDate === null) return 1;
            if ($bDate === null) return -1;
            return $aDate <=> $bDate;
        });

        return $taches;
    }

    public function getDerniersFeedbacks(Entreprise $entreprise, int $limit = 20): array
    {
        return $this->em->createQuery(
            'SELECT f, t FROM App\Entity\Feedback f
             LEFT JOIN f.tache t
             WHERE f.entreprise = :e
             ORDER BY f.createdAt DESC'
        )
        ->setParameter('e', $entreprise)
        ->setMaxResults($limit)
        ->getResult();
    }

    public function getPistesEnCours(Entreprise $entreprise, int $limit = 60): array
    {
        return $this->em->createQuery(
            'SELECT p, cl, r, inv FROM App\Entity\Piste p
             LEFT JOIN p.client cl
             LEFT JOIN p.risque r
             LEFT JOIN p.invite inv
             WHERE p.entreprise = :e
               AND p.closed = false
               AND NOT EXISTS (
                   SELECT a.id FROM App\Entity\Avenant a
                   JOIN a.cotation c
                   WHERE c.piste = p
               )
             ORDER BY p.createdAt DESC'
        )
        ->setParameter('e', $entreprise)
        ->setMaxResults($limit)
        ->getResult();
    }

    public function getDerniersEncaissements(Entreprise $entreprise, int $limit = 20): array
    {
        $paiements = $this->em->createQuery(
            'SELECT p, n FROM App\Entity\Paiement p
             LEFT JOIN p.note n
             WHERE p.entreprise = :e
             ORDER BY p.paidAt DESC'
        )
        ->setParameter('e', $entreprise)
        ->setMaxResults($limit)
        ->getResult();

        foreach ($paiements as $paiement) {
            if ($note = $paiement->getNote()) {
                $this->canvasBuilder->loadAllCalculatedValues($note);
            }
        }

        return $paiements;
    }

    public function getDerniersBordereaux(Entreprise $entreprise, int $limit = 40): array
    {
        return $this->em->createQuery(
            'SELECT b, ass, inv FROM App\Entity\Bordereau b
             LEFT JOIN b.assureur ass
             LEFT JOIN b.invite inv
             WHERE b.entreprise = :e
             ORDER BY b.receivedAt DESC'
        )
        ->setParameter('e', $entreprise)
        ->setMaxResults($limit)
        ->getResult();
    }

    public function getDerniersNotes(Entreprise $entreprise, int $limit = 30): array
    {
        $notes = $this->em->createQuery(
            'SELECT n, b, inv, cli, ass, par FROM App\Entity\Note n
             LEFT JOIN n.bordereau b
             LEFT JOIN n.invite inv
             LEFT JOIN n.client cli
             LEFT JOIN n.assureur ass
             LEFT JOIN n.partenaire par
             WHERE n.entreprise = :e
             ORDER BY n.createdAt DESC'
        )
        ->setParameter('e', $entreprise)
        ->setMaxResults($limit)
        ->getResult();

        foreach ($notes as $note) {
            $this->canvasBuilder->loadAllCalculatedValues($note);
        }
        return $notes;
    }

    public function getNbRenouvellements30j(Entreprise $entreprise): int
    {
        $result = $this->em->createQuery(
            'SELECT COUNT(a.id) FROM App\Entity\Avenant a
             WHERE a.entreprise = :e
               AND a.endingAt BETWEEN :debut AND :fin'
        )
        ->setParameter('e', $entreprise)
        ->setParameter('debut', new \DateTimeImmutable('now'))
        ->setParameter('fin',   new \DateTimeImmutable('+30 days'))
        ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    public function getProductionTableData(Entreprise $entreprise): array
    {
        $year  = (int) date('Y');
        $debut = new \DateTimeImmutable($year . '-01-01 00:00:00');
        $fin   = new \DateTimeImmutable($year . '-12-31 23:59:59');

        $taxeCourtierEntity = $this->taxeRepository->findOneBy(['redevable' => Taxe::REDEVABLE_COURTIER, 'entreprise' => $entreprise]);
        $taxeAssureurEntity = $this->taxeRepository->findOneBy(['redevable' => Taxe::REDEVABLE_ASSUREUR, 'entreprise' => $entreprise]);
        $tauxAssureur = (float) ($taxeAssureurEntity?->getTauxIARD() ?? 0);
        $tauxCourtier = (float) ($taxeCourtierEntity?->getTauxIARD() ?? 0);

        // ── Étape 1 : Paiements de l'année sur notes de commission (TO_CLIENT=0, TO_ASSUREUR=1) ──
        $paiements = $this->em->createQuery(
            'SELECT p, n, ass
             FROM App\Entity\Paiement p
             JOIN p.note n
             JOIN n.assureur ass
             WHERE p.entreprise = :e
               AND p.paidAt >= :debut AND p.paidAt <= :fin
               AND n.addressedTo IN (0, 1)'
        )
        ->setParameter('e', $entreprise)
        ->setParameter('debut', $debut)
        ->setParameter('fin', $fin)
        ->getResult();

        // ── Étape 2 : Accumulation encaissements + collecte des notes uniques ──
        $rows      = [];
        $notesById = []; // [noteId => note]

        foreach ($paiements as $p) {
            $month  = (int) $p->getPaidAt()->format('n');
            $note   = $p->getNote();
            $noteId = $note->getId();
            $ass    = $note->getAssureur();
            $assId  = $ass?->getId() ?? 0;
            $assNom = $ass?->getNom() ?? '—';

            if (!isset($rows[$month][$assId])) {
                $rows[$month][$assId] = [
                    'nom'              => $assNom,
                    'primeTtc'         => 0.0,
                    'commissionPure'   => 0.0,
                    'retrocommission'  => 0.0,
                    'taxeAssureur'     => 0.0,
                    'taxeCourtier'     => 0.0,
                    'commissionTtc'    => 0.0,
                    'encaissements'    => 0.0,
                    'solde'            => 0.0,
                ];
            }
            $rows[$month][$assId]['encaissements'] += (float) $p->getMontant();
            $notesById[$noteId] = $note;
        }

        if (empty($notesById)) {
            return [
                'rows'             => [],
                'monthTotals'      => [],
                'grandTotals'      => ['primeTtc' => 0.0, 'commissionPure' => 0.0, 'retrocommission' => 0.0,
                                       'taxeAssureur' => 0.0, 'taxeCourtier' => 0.0, 'commissionTtc' => 0.0,
                                       'encaissements' => 0.0, 'solde' => 0.0],
                'taxeAssureurNom'  => $taxeAssureurEntity?->getCode() ?? 'Taxe assureur',
                'taxeAssureurTaux' => $tauxAssureur,
                'taxeCourtierNom'  => $taxeCourtierEntity?->getCode() ?? 'Taxe courtier',
                'taxeCourtierTaux' => $tauxCourtier,
                'year'             => $year,
            ];
        }

        // ── Étape 3 : Hydratation des Notes (fonctionne pour les 2 types : articles ET bordereau) ──
        foreach ($notesById as $note) {
            $this->canvasBuilder->loadAllCalculatedValues($note);
        }

        // Batch-load : articles → revenuFacture → cotation → avenants + piste → partenaires (pour retrocommission)
        $ids = array_keys($notesById);
        $this->em->createQuery(
            'SELECT n, arts, rpc, cot, avs, piste, pars, cli, cpars, bord
             FROM App\Entity\Note n
             LEFT JOIN n.articles arts
             LEFT JOIN arts.revenuFacture rpc
             LEFT JOIN rpc.cotation cot
             LEFT JOIN cot.avenants avs
             LEFT JOIN cot.piste piste
             LEFT JOIN piste.partenaires pars
             LEFT JOIN piste.client cli
             LEFT JOIN cli.partenaires cpars
             LEFT JOIN n.bordereau bord
             WHERE n.id IN (:ids)'
        )
        ->setParameter('ids', $ids)
        ->getResult();

        $seenAvenants = [];
        foreach ($notesById as $note) {
            foreach ($note->getArticles() as $article) {
                $cot = $article->getRevenuFacture()?->getCotation();
                if (!$cot) continue;
                foreach ($cot->getAvenants() as $av) {
                    $avId = $av->getId();
                    if (!isset($seenAvenants[$avId])) {
                        $this->canvasBuilder->loadAllCalculatedValues($av);
                        $seenAvenants[$avId] = true;
                    }
                }
            }
        }

        // Batch-load des avenants référencés dans analysisResults des bordereaux
        $bordereauAvenantIds = [];
        foreach ($notesById as $note) {
            $bordereau = $note->getBordereau();
            if (!$bordereau) continue;
            foreach (array_filter(array_column($bordereau->getAnalysisResults() ?? [], 'avenant_id')) as $avId) {
                $bordereauAvenantIds[(int) $avId] = true;
            }
        }
        if (!empty($bordereauAvenantIds)) {
            $this->em->createQuery(
                'SELECT av, cot FROM App\Entity\Avenant av
                 LEFT JOIN av.cotation cot
                 WHERE av.id IN (:avIds)'
            )->setParameter('avIds', array_keys($bordereauAvenantIds))->getResult();

            foreach (array_keys($bordereauAvenantIds) as $avId) {
                if (!isset($seenAvenants[$avId])) {
                    $av = $this->em->find(\App\Entity\Avenant::class, $avId);
                    if ($av) {
                        $this->canvasBuilder->loadAllCalculatedValues($av);
                        $seenAvenants[$avId] = true;
                    }
                }
            }
        }

        // ── Étape 4 : Attribution des métriques financières par note (déduplication par note×mois) ──
        $processedNoteMonth = [];
        $seenPrimeTtc       = []; // [noteId_avenantId] pour éviter le double-comptage

        foreach ($paiements as $p) {
            $month  = (int) $p->getPaidAt()->format('n');
            $note   = $p->getNote();
            $noteId = $note->getId();
            $ass    = $note->getAssureur();
            $assId  = $ass?->getId() ?? 0;

            // Chaque (note, mois) n'est traité qu'une seule fois
            $key = $noteId . '_' . $month;
            if (isset($processedNoteMonth[$key])) continue;
            $processedNoteMonth[$key] = true;

            // Métriques issues du canvas Note (fonctionnent pour articles ET bordereau)
            $noteMontantTotal = (float) ($note->montantTotal ?? 0);

            $metrics = AvenantIndicatorStrategy::computeProductionMetrics($noteMontantTotal, $tauxAssureur, $tauxCourtier);
            $rows[$month][$assId]['commissionTtc']  += $noteMontantTotal;
            $rows[$month][$assId]['commissionPure'] += $metrics['commissionPure'];
            $rows[$month][$assId]['taxeAssureur']   += $metrics['taxeAssureur'];
            $rows[$month][$assId]['taxeCourtier']   += $metrics['taxeCourtier'];

            // Rétrocommission : taux partenaire × assiette produite (commission pure)
            $tauxPart = 0.0;
            foreach ($note->getArticles() as $art) {
                $cot = $art->getRevenuFacture()?->getCotation();
                if ($cot) {
                    $partenaire = $this->calculationHelper->getCotationPartenaire($cot);
                    $tauxPart = $partenaire ? (float) ($partenaire->getPart() ?? 0) : 0.0;
                    break;
                }
            }
            $rows[$month][$assId]['retrocommission'] += AvenantIndicatorStrategy::computeRetrocommission($metrics['commissionPure'], $tauxPart);

            // Prime produite : navigation vers l'avenant (notes avec articles seulement)
            foreach ($note->getArticles() as $article) {
                if (!$article->getRevenuFacture()) continue;
                $cot = $article->getRevenuFacture()?->getCotation();
                if (!$cot) continue;
                foreach ($cot->getAvenants() as $avenant) {
                    $ptKey = $noteId . '_' . $avenant->getId();
                    if (isset($seenPrimeTtc[$ptKey])) continue;
                    $seenPrimeTtc[$ptKey] = true;
                    $rows[$month][$assId]['primeTtc'] += AvenantIndicatorStrategy::computePrimeProduite(
                        $noteMontantTotal,
                        (float) ($avenant->montantTTC ?? 0),
                        (float) ($avenant->primeTotale ?? 0)
                    );
                }
            }

            // Prime TTC via bordereau (notes sans articles — chemin via analysisResults)
            if ($note->getArticles()->isEmpty()) {
                $bordereau = $note->getBordereau();
                if ($bordereau) {
                    foreach (array_filter(array_column($bordereau->getAnalysisResults() ?? [], 'avenant_id')) as $avId) {
                        $ptKey = $noteId . '_' . (int) $avId;
                        if (isset($seenPrimeTtc[$ptKey])) continue;
                        $seenPrimeTtc[$ptKey] = true;
                        $av = $this->em->find(\App\Entity\Avenant::class, (int) $avId);
                        if ($av) {
                            $rows[$month][$assId]['primeTtc'] += (float) ($av->primeTotale ?? 0);
                        }
                    }
                }
            }
        }

        // ── Étape 5 : Solde = commissionTtc − encaissements ──
        foreach ($rows as $month => $assureurs) {
            foreach ($assureurs as $assId => &$data) {
                $data['solde'] = $data['commissionTtc'] - $data['encaissements'];
            }
            unset($data);
        }

        // ── Étape 6 : Tri, totaux par mois, grand total ──
        $zeroRow     = ['primeTtc' => 0.0, 'commissionPure' => 0.0, 'retrocommission' => 0.0,
                        'taxeAssureur' => 0.0, 'taxeCourtier' => 0.0, 'commissionTtc' => 0.0,
                        'encaissements' => 0.0, 'solde' => 0.0];
        $grandTotals = $zeroRow;
        $monthTotals = [];
        $fields      = array_keys($zeroRow);

        ksort($rows);
        foreach ($rows as $month => $assureurs) {
            uasort($assureurs, fn($a, $b) => strcmp($a['nom'], $b['nom']));
            $rows[$month] = $assureurs;
            $monthTotals[$month] = $zeroRow;
            foreach ($assureurs as $ass) {
                foreach ($fields as $f) {
                    $monthTotals[$month][$f] += $ass[$f];
                    $grandTotals[$f]         += $ass[$f];
                }
            }
        }

        return [
            'rows'             => $rows,
            'monthTotals'      => $monthTotals,
            'grandTotals'      => $grandTotals,
            'taxeAssureurNom'  => $taxeAssureurEntity?->getCode() ?? 'Taxe assureur',
            'taxeAssureurTaux' => $tauxAssureur,
            'taxeCourtierNom'  => $taxeCourtierEntity?->getCode() ?? 'Taxe courtier',
            'taxeCourtierTaux' => $tauxCourtier,
            'year'             => $year,
        ];
    }

    public function getProductionGroupData(Entreprise $entreprise): array
    {
        $tableData = $this->getProductionTableData($entreprise);

        $fields = ['primeTtc', 'commissionPure', 'retrocommission', 'taxeCourtier',
                   'taxeAssureur', 'commissionTtc', 'encaissements', 'solde'];

        $byAssureur        = [];
        $byAssureurMonthly = [];
        foreach ($tableData['rows'] as $month => $assureurs) {
            foreach ($assureurs as $id => $metrics) {
                if (!isset($byAssureur[$id])) {
                    $byAssureur[$id] = array_merge(['id' => $id, 'nom' => $metrics['nom']], array_fill_keys($fields, 0.0));
                }
                foreach ($fields as $f) {
                    $byAssureur[$id][$f] += $metrics[$f] ?? 0.0;
                }
                $byAssureurMonthly[$id][$month] = $metrics;
            }
        }
        uasort($byAssureur, fn($a, $b) => $b['encaissements'] <=> $a['encaissements']);

        return [
            'byAssureur'        => array_values($byAssureur),
            'byAssureurMonthly' => $byAssureurMonthly,
            'byPartenaire'      => $this->getProductionParPartenaire($entreprise),
            'taxeCourtierNom'   => $tableData['taxeCourtierNom'],
            'taxeCourtierTaux'  => $tableData['taxeCourtierTaux'],
            'taxeAssureurNom'   => $tableData['taxeAssureurNom'],
            'taxeAssureurTaux'  => $tableData['taxeAssureurTaux'],
        ];
    }

    private function getProductionParPartenaire(Entreprise $entreprise): array
    {
        $year  = (int) date('Y');
        $debut = new \DateTimeImmutable($year . '-01-01 00:00:00');
        $fin   = new \DateTimeImmutable($year . '-12-31 23:59:59');

        $paiements = $this->em->createQuery(
            'SELECT p, n
             FROM App\Entity\Paiement p
             JOIN p.note n
             WHERE p.entreprise = :e
               AND p.paidAt >= :debut AND p.paidAt <= :fin
               AND n.addressedTo IN (0, 1)'
        )
        ->setParameter('e', $entreprise)
        ->setParameter('debut', $debut)
        ->setParameter('fin', $fin)
        ->getResult();

        if (empty($paiements)) return [];

        $notesById   = [];
        $noteEncaiss = [];
        foreach ($paiements as $p) {
            $note   = $p->getNote();
            $noteId = $note->getId();
            $notesById[$noteId] = $note;
            $noteEncaiss[$noteId] = ($noteEncaiss[$noteId] ?? 0.0) + (float) $p->getMontant();
        }

        foreach ($notesById as $note) {
            $this->canvasBuilder->loadAllCalculatedValues($note);
        }

        $ids = array_keys($notesById);
        $this->em->createQuery(
            'SELECT n, arts, rpc, cot, piste, pars
             FROM App\Entity\Note n
             LEFT JOIN n.articles arts
             LEFT JOIN arts.revenuFacture rpc
             LEFT JOIN rpc.cotation cot
             LEFT JOIN cot.piste piste
             LEFT JOIN piste.partenaires pars
             WHERE n.id IN (:ids)'
        )
        ->setParameter('ids', $ids)
        ->getResult();

        $byPartenaire   = [];
        $processedNotes = [];
        foreach ($notesById as $noteId => $note) {
            if (isset($processedNotes[$noteId])) continue;
            $processedNotes[$noteId] = true;

            $encaissements = $noteEncaiss[$noteId] ?? 0.0;
            $montantTotal  = (float) ($note->montantTotal ?? 0);

            $partFound = [];
            foreach ($note->getArticles() as $art) {
                $piste = $art->getRevenuFacture()?->getCotation()?->getPiste();
                if (!$piste) continue;
                foreach ($piste->getPartenaires() as $par) {
                    $partFound[$par->getId()] = $par;
                }
            }

            $targets = empty($partFound) ? ['__none__' => null] : $partFound;
            foreach ($targets as $parId => $par) {
                $nom = $par ? $par->getNom() : 'Sans partenaire';
                if (!isset($byPartenaire[$parId])) {
                    $byPartenaire[$parId] = ['nom' => $nom, 'encaissements' => 0.0,
                                             'primeTtc' => 0.0, 'commissionTtc' => 0.0];
                }
                $byPartenaire[$parId]['encaissements'] += $encaissements;
                $byPartenaire[$parId]['commissionTtc'] += $montantTotal;
            }
        }

        uasort($byPartenaire, fn($a, $b) => $b['encaissements'] <=> $a['encaissements']);
        return array_values($byPartenaire);
    }

    public function getProductionMensuelle(Entreprise $entreprise): array
    {
        $year  = (int) date('Y');
        $debut = new \DateTimeImmutable($year . '-01-01 00:00:00');
        $fin   = new \DateTimeImmutable($year . '-12-31 23:59:59');

        $rows = $this->em->createQuery(
            'SELECT p.paidAt, p.montant FROM App\Entity\Paiement p
             WHERE p.entreprise = :e
               AND p.paidAt >= :debut AND p.paidAt <= :fin'
        )
        ->setParameter('e', $entreprise)
        ->setParameter('debut', $debut)
        ->setParameter('fin', $fin)
        ->getResult();

        $monthly = array_fill(1, 12, 0.0);
        foreach ($rows as $row) {
            $month = (int) $row['paidAt']->format('n');
            $monthly[$month] += (float) ($row['montant'] ?? 0);
        }
        return $monthly;
    }
}
