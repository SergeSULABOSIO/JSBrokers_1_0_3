<?php

namespace App\Repository;

use App\Entity\Charge;
use App\Entity\Depense;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Knp\Component\Pager\PaginatorInterface;

/**
 * @extends ServiceEntityRepository<Depense>
 */
class DepenseRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private PaginatorInterface $paginator,
    ) {
        parent::__construct($registry, Depense::class);
    }

    /**
     * Applique les filtres communs (période, charge, statut, destination,
     * recherche) à un QueryBuilder. Réutilisé par la liste paginée ET par les
     * agrégats pour garantir des totaux cohérents avec la liste affichée (DRY).
     *
     * @param array{from?:?string, to?:?string, charge?:?string, statut?:?string, destination?:?string, q?:?string} $filtres
     */
    private function appliquerFiltres(QueryBuilder $qb, array $filtres): QueryBuilder
    {
        if (!empty($filtres['from'])) {
            $qb->andWhere('d.dateDepense >= :from')
                ->setParameter('from', new \DateTimeImmutable($filtres['from'] . ' 00:00:00'));
        }
        if (!empty($filtres['to'])) {
            $qb->andWhere('d.dateDepense <= :to')
                ->setParameter('to', new \DateTimeImmutable($filtres['to'] . ' 23:59:59'));
        }
        if (!empty($filtres['charge'])) {
            $qb->andWhere('d.charge = :charge')->setParameter('charge', $filtres['charge']);
        }
        if (!empty($filtres['statut'])) {
            $qb->andWhere('d.statut = :statut')->setParameter('statut', $filtres['statut']);
        }
        if (!empty($filtres['destination'])) {
            $qb->join('d.charge', 'cf')
                ->andWhere('cf.destination = :destination')
                ->setParameter('destination', $filtres['destination']);
        }
        if (!empty($filtres['q'])) {
            $qb->leftJoin('d.charge', 'cq')
                ->andWhere('d.beneficiaire LIKE :q OR d.reference LIKE :q OR d.description LIKE :q OR cq.libelle LIKE :q')
                ->setParameter('q', '%' . $filtres['q'] . '%');
        }

        return $qb;
    }

    /** Liste paginée des dépenses filtrées, plus récentes d'abord. */
    public function paginateFiltered(array $filtres, int $page): PaginationInterface
    {
        $qb = $this->appliquerFiltres($this->createQueryBuilder('d'), $filtres)
            ->orderBy('d.dateDepense', 'DESC')
            ->addOrderBy('d.id', 'DESC');

        return $this->paginator->paginate($qb, $page, 20);
    }

    /**
     * Totaux des dépenses filtrées : nombre, montant total (hors annulées),
     * montant payé (décaissé).
     *
     * @return array{count:int, montant:float, montantPaye:float}
     */
    public function totals(array $filtres = []): array
    {
        $qb = $this->appliquerFiltres($this->createQueryBuilder('d'), $filtres)
            ->select(
                'COUNT(d.id) AS nb',
                'COALESCE(SUM(CASE WHEN d.statut != :annulee THEN d.montant ELSE 0 END), 0) AS montant',
                'COALESCE(SUM(CASE WHEN d.statut = :payee THEN d.montant ELSE 0 END), 0) AS montantPaye',
            )
            ->setParameter('annulee', Depense::STATUT_ANNULEE)
            ->setParameter('payee', Depense::STATUT_PAYEE);

        $row = $qb->getQuery()->getSingleResult();

        return [
            'count'       => (int) $row['nb'],
            'montant'     => (float) $row['montant'],
            'montantPaye' => (float) $row['montantPaye'],
        ];
    }

    /**
     * Dépenses regroupées par charge (montant engagé hors annulées + nombre),
     * les plus coûteuses d'abord.
     *
     * @return array<int, array{charge:string, code:string, nb:int, montant:float}>
     */
    public function groupByCharge(array $filtres = []): array
    {
        $qb = $this->appliquerFiltres($this->createQueryBuilder('d'), $filtres)
            ->join('d.charge', 'c')
            ->select('c.libelle AS charge, c.code AS code, COUNT(d.id) AS nb, COALESCE(SUM(CASE WHEN d.statut != :annulee THEN d.montant ELSE 0 END), 0) AS montant')
            ->setParameter('annulee', Depense::STATUT_ANNULEE)
            ->groupBy('c.id')
            ->orderBy('montant', 'DESC');

        return array_map(static fn (array $r) => [
            'charge'  => (string) $r['charge'],
            'code'    => (string) $r['code'],
            'nb'      => (int) $r['nb'],
            'montant' => (float) $r['montant'],
        ], $qb->getQuery()->getArrayResult());
    }

    /**
     * Total des charges (résultat) sur une période : toutes les dépenses non
     * annulées, qu'elles soient engagées ou payées (comptabilité d'engagement).
     */
    public function totalCharges(?string $from = null, ?string $to = null): float
    {
        return $this->somme(['from' => $from, 'to' => $to], static function (QueryBuilder $qb): void {
            $qb->andWhere('d.statut != :annulee')->setParameter('annulee', Depense::STATUT_ANNULEE);
        });
    }

    /**
     * Total décaissé (trésorerie) : dépenses payées. Sans bornes de date, renvoie
     * le cumul depuis l'origine (position de trésorerie).
     */
    public function totalPaye(?string $from = null, ?string $to = null): float
    {
        return $this->somme(['from' => $from, 'to' => $to], static function (QueryBuilder $qb): void {
            $qb->andWhere('d.statut = :payee')->setParameter('payee', Depense::STATUT_PAYEE);
        });
    }

    /**
     * Total des charges non annulées d'un axe analytique donné (cf. Charge::DEST_*)
     * sur une période. Alimente CAC (acquisition) et marge brute (coût direct).
     */
    public function totalByDestination(string $destination, ?string $from = null, ?string $to = null): float
    {
        return $this->somme(['from' => $from, 'to' => $to], static function (QueryBuilder $qb) use ($destination): void {
            $qb->join('d.charge', 'c')
                ->andWhere('d.statut != :annulee')
                ->andWhere('c.destination = :destination')
                ->setParameter('annulee', Depense::STATUT_ANNULEE)
                ->setParameter('destination', $destination);
        });
    }

    /**
     * Somme d'un montant de dépense sur une fenêtre [from, to] optionnelle, avec
     * un prédicat additionnel (statut/destination). Factorise les agrégats KPI (DRY).
     *
     * @param array{from?:?string, to?:?string} $bornes
     */
    private function somme(array $bornes, callable $predicat): float
    {
        $qb = $this->createQueryBuilder('d')->select('COALESCE(SUM(d.montant), 0) AS total');
        $this->appliquerFiltres($qb, ['from' => $bornes['from'] ?? null, 'to' => $bornes['to'] ?? null]);
        $predicat($qb);

        return (float) $qb->getQuery()->getSingleScalarResult();
    }
}
