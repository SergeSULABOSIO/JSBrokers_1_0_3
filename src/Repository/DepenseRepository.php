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

    /**
     * Expression SQL-DQL du montant HORS TAXE d'une dépense : le TTC stocké dégrevé
     * de la TVA déductible (`montant / (1 + tauxTva/100)`). Centralise la formule
     * pour que tous les agrégats de charge (résultat) soient cohérents (DRY). Égal
     * au TTC quand `tauxTva = 0` → aucune régression sur les données existantes.
     */
    private const MONTANT_HT = 'd.montant / (1 + d.tauxTva / 100)';

    /** Liste paginée des dépenses filtrées, plus récentes d'abord. */
    public function paginateFiltered(array $filtres, int $page): PaginationInterface
    {
        $qb = $this->appliquerFiltres($this->createQueryBuilder('d'), $filtres)
            ->orderBy('d.dateDepense', 'DESC')
            ->addOrderBy('d.id', 'DESC');

        return $this->paginator->paginate($qb, $page, 20);
    }

    /**
     * Dépenses non annulées, ordonnées chronologiquement (charge jointe). Alimente
     * la génération des documents comptables (journal, grand livre, balance…).
     *
     * @return Depense[]
     */
    public function findChronologique(): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.charge', 'c')->addSelect('c')
            ->where('d.statut != :annulee')
            ->setParameter('annulee', Depense::STATUT_ANNULEE)
            ->orderBy('d.dateDepense', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Totaux des dépenses filtrées (vue opérationnelle, en TTC) : nombre, montant
     * total engagé (hors annulées) et montant payé (décaissé). Reste en TTC : c'est
     * la dépense réelle telle que saisie ; le résultat comptable HT est exposé
     * séparément (cf. totalCharges).
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
     * Comptabilisé en HT (la TVA déductible est récupérable, hors charge).
     */
    public function totalCharges(?string $from = null, ?string $to = null): float
    {
        return $this->somme(['from' => $from, 'to' => $to], self::MONTANT_HT, static function (QueryBuilder $qb): void {
            $qb->andWhere('d.statut != :annulee')->setParameter('annulee', Depense::STATUT_ANNULEE);
        });
    }

    /**
     * Total décaissé (trésorerie) : dépenses payées, en TTC (la trésorerie sort le
     * montant payé taxes comprises). Sans bornes de date, renvoie le cumul depuis
     * l'origine (position de trésorerie).
     */
    public function totalPaye(?string $from = null, ?string $to = null): float
    {
        return $this->somme(['from' => $from, 'to' => $to], 'd.montant', static function (QueryBuilder $qb): void {
            $qb->andWhere('d.statut = :payee')->setParameter('payee', Depense::STATUT_PAYEE);
        });
    }

    /**
     * Total HT des charges non annulées d'un axe analytique donné (cf. Charge::DEST_*)
     * sur une période. Alimente CAC (acquisition) et marge brute (coût direct).
     */
    public function totalByDestination(string $destination, ?string $from = null, ?string $to = null): float
    {
        return $this->somme(['from' => $from, 'to' => $to], self::MONTANT_HT, static function (QueryBuilder $qb) use ($destination): void {
            $qb->join('d.charge', 'c')
                ->andWhere('d.statut != :annulee')
                ->andWhere('c.destination = :destination')
                ->setParameter('annulee', Depense::STATUT_ANNULEE)
                ->setParameter('destination', $destination);
        });
    }

    /**
     * TVA déductible par mois pour un exercice : [mois(1-12) => montant], sur les
     * dépenses non annulées. TVA = TTC − HT = montant − montant/(1 + taux/100).
     * Regroupement en PHP pour rester portable (SQLite en test, MySQL en prod).
     *
     * @return array<int, float>
     */
    public function tvaDeductibleParMoisAnnee(int $annee): array
    {
        $debut = new \DateTimeImmutable(sprintf('%d-01-01 00:00:00', $annee));
        $fin   = new \DateTimeImmutable(sprintf('%d-01-01 00:00:00', $annee + 1));

        $rows = $this->createQueryBuilder('d')
            ->select('d.dateDepense AS dateDepense, d.montant AS montant, d.tauxTva AS tauxTva')
            ->where('d.statut != :annulee')
            ->andWhere('d.dateDepense >= :debut AND d.dateDepense < :fin')
            ->setParameter('annulee', Depense::STATUT_ANNULEE)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getArrayResult();

        $parMois = array_fill(1, 12, 0.0);
        foreach ($rows as $row) {
            if (!$row['dateDepense'] instanceof \DateTimeInterface) {
                continue;
            }
            $ttc  = (float) $row['montant'];
            $taux = (float) $row['tauxTva'];
            $tva  = $ttc - ($ttc / (1 + $taux / 100));
            $parMois[(int) $row['dateDepense']->format('n')] += $tva;
        }

        return $parMois;
    }

    /**
     * Somme d'une expression de montant (`$expr` : TTC `d.montant` ou HT) sur une
     * fenêtre [from, to] optionnelle, avec un prédicat additionnel (statut/
     * destination). Factorise les agrégats KPI (DRY).
     *
     * @param array{from?:?string, to?:?string} $bornes
     */
    private function somme(array $bornes, string $expr, callable $predicat): float
    {
        $qb = $this->createQueryBuilder('d')->select('COALESCE(SUM(' . $expr . '), 0) AS total');
        $this->appliquerFiltres($qb, ['from' => $bornes['from'] ?? null, 'to' => $bornes['to'] ?? null]);
        $predicat($qb);

        return (float) $qb->getQuery()->getSingleScalarResult();
    }
}
