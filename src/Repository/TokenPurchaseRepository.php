<?php

namespace App\Repository;

use App\Entity\TokenPurchase;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Knp\Component\Pager\PaginatorInterface;

/**
 * @extends ServiceEntityRepository<TokenPurchase>
 */
class TokenPurchaseRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private PaginatorInterface $paginator,
    ) {
        parent::__construct($registry, TokenPurchase::class);
    }

    /**
     * Applique les filtres communs (période, paquet, statut, recherche acheteur)
     * à un QueryBuilder. Réutilisé par la liste paginée ET par les agrégats pour
     * garantir des totaux cohérents avec la liste affichée (DRY).
     *
     * @param array{from?:?string, to?:?string, pack?:?string, status?:?string, q?:?string} $filtres
     */
    private function appliquerFiltres(QueryBuilder $qb, array $filtres): QueryBuilder
    {
        if (!empty($filtres['from'])) {
            $qb->andWhere('p.createdAt >= :from')
                ->setParameter('from', new \DateTimeImmutable($filtres['from'] . ' 00:00:00'));
        }
        if (!empty($filtres['to'])) {
            $qb->andWhere('p.createdAt <= :to')
                ->setParameter('to', new \DateTimeImmutable($filtres['to'] . ' 23:59:59'));
        }
        if (!empty($filtres['pack'])) {
            $qb->andWhere('p.pack = :pack')->setParameter('pack', $filtres['pack']);
        }
        if (!empty($filtres['status'])) {
            $qb->andWhere('p.status = :status')->setParameter('status', $filtres['status']);
        }
        if (!empty($filtres['q'])) {
            $qb->join('p.utilisateur', 'u')
                ->andWhere('u.nom LIKE :q OR u.email LIKE :q OR p.reference LIKE :q')
                ->setParameter('q', '%' . $filtres['q'] . '%');
        }

        return $qb;
    }

    /** Liste paginée des ventes filtrées, plus récentes d'abord. */
    public function paginateFiltered(array $filtres, int $page): PaginationInterface
    {
        $qb = $this->appliquerFiltres($this->createQueryBuilder('p'), $filtres)
            ->orderBy('p.createdAt', 'DESC');

        return $this->paginator->paginate($qb, $page, 20);
    }

    /**
     * Totaux des ventes filtrées : nombre, tokens vendus, revenu USD, remises.
     *
     * @return array{count:int, tokens:int, revenue:float, remises:float}
     */
    public function totals(array $filtres = []): array
    {
        $qb = $this->appliquerFiltres($this->createQueryBuilder('p'), $filtres)
            ->select('COUNT(p.id) AS nb, COALESCE(SUM(p.tokens), 0) AS tokens, COALESCE(SUM(p.montantUsd), 0) AS revenue, COALESCE(SUM(p.remiseUsd), 0) AS remises');

        $row = $qb->getQuery()->getSingleResult();

        return [
            'count'   => (int) $row['nb'],
            'tokens'  => (int) $row['tokens'],
            'revenue' => (float) $row['revenue'],
            'remises' => (float) $row['remises'],
        ];
    }

    /**
     * Ventes regroupées par paquet (revenu + nombre).
     *
     * @return array<int, array{pack:string, nb:int, revenue:float, tokens:int}>
     */
    public function groupByPack(array $filtres = []): array
    {
        $qb = $this->appliquerFiltres($this->createQueryBuilder('p'), $filtres)
            ->select('p.pack AS pack, COUNT(p.id) AS nb, COALESCE(SUM(p.montantUsd), 0) AS revenue, COALESCE(SUM(p.tokens), 0) AS tokens')
            ->groupBy('p.pack')
            ->orderBy('revenue', 'DESC');

        return array_map(static fn (array $r) => [
            'pack'    => (string) $r['pack'],
            'nb'      => (int) $r['nb'],
            'revenue' => (float) $r['revenue'],
            'tokens'  => (int) $r['tokens'],
        ], $qb->getQuery()->getArrayResult());
    }

    /**
     * Série mensuelle (N derniers mois) du revenu et du volume de tokens vendus.
     * Le regroupement par mois est fait en PHP pour rester portable (SQLite en
     * test, MySQL/PostgreSQL en prod) sans fonction SQL spécifique au moteur.
     *
     * @return array{labels:string[], revenue:float[], tokens:int[]}
     */
    public function seriesParMois(int $mois = 12): array
    {
        $depuis = (new \DateTimeImmutable('first day of this month 00:00:00'))
            ->modify('-' . ($mois - 1) . ' months');

        $rows = $this->createQueryBuilder('p')
            ->select('p.createdAt AS createdAt, p.montantUsd AS montantUsd, p.tokens AS tokens')
            ->where('p.createdAt >= :depuis')
            ->setParameter('depuis', $depuis)
            ->getQuery()
            ->getArrayResult();

        // Initialise tous les mois de la fenêtre à zéro (clé AAAA-MM).
        $revenue = [];
        $tokens = [];
        $labels = [];
        for ($i = 0; $i < $mois; $i++) {
            $cle = $depuis->modify('+' . $i . ' months')->format('Y-m');
            $labels[] = $cle;
            $revenue[$cle] = 0.0;
            $tokens[$cle] = 0;
        }

        foreach ($rows as $r) {
            $cle = $r['createdAt'] instanceof \DateTimeInterface ? $r['createdAt']->format('Y-m') : null;
            if ($cle !== null && isset($revenue[$cle])) {
                $revenue[$cle] += (float) $r['montantUsd'];
                $tokens[$cle] += (int) $r['tokens'];
            }
        }

        return [
            'labels'  => $labels,
            'revenue' => array_values($revenue),
            'tokens'  => array_values($tokens),
        ];
    }

    /**
     * Série mensuelle du revenu/volume pour les 12 mois (janvier → décembre)
     * d'une année civile donnée. Regroupement en PHP (portable tous moteurs).
     *
     * @return array{labels:string[], revenue:float[], tokens:int[]}
     */
    public function seriesParMoisAnnee(int $annee): array
    {
        [$debut, $fin] = $this->bornesAnnee($annee);

        $rows = $this->createQueryBuilder('p')
            ->select('p.createdAt AS createdAt, p.montantUsd AS montantUsd, p.tokens AS tokens')
            ->where('p.createdAt >= :debut AND p.createdAt < :fin')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getArrayResult();

        // Libellés courts en français (« Jan 2026 » → « Déc 2026 ») ; les clés
        // internes restent au format « Y-m » pour le rapprochement des montants.
        $moisCourts = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];

        $labels = [];
        $revenue = [];
        $tokens = [];
        for ($m = 1; $m <= 12; $m++) {
            $cle = sprintf('%d-%02d', $annee, $m);
            $labels[] = sprintf('%s %d', $moisCourts[$m - 1], $annee);
            $revenue[$cle] = 0.0;
            $tokens[$cle] = 0;
        }

        foreach ($rows as $r) {
            $cle = $r['createdAt'] instanceof \DateTimeInterface ? $r['createdAt']->format('Y-m') : null;
            if ($cle !== null && isset($revenue[$cle])) {
                $revenue[$cle] += (float) $r['montantUsd'];
                $tokens[$cle] += (int) $r['tokens'];
            }
        }

        return [
            'labels'  => $labels,
            'revenue' => array_values($revenue),
            'tokens'  => array_values($tokens),
        ];
    }

    /**
     * Ventes d'une année civile (entités hydratées, acheteur joint) — sert à
     * dériver le pays via l'entreprise de l'acheteur.
     *
     * @return TokenPurchase[]
     */
    public function findAnnee(int $annee): array
    {
        [$debut, $fin] = $this->bornesAnnee($annee);

        return $this->createQueryBuilder('p')
            ->leftJoin('p.utilisateur', 'u')->addSelect('u')
            ->where('p.createdAt >= :debut AND p.createdAt < :fin')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Nombre de NOUVEAUX clients sur une fenêtre [from, to] : comptes dont le tout
     * premier achat (MIN(createdAt)) tombe dans la fenêtre. Sert au calcul du coût
     * d'acquisition client (CAC).
     */
    public function countNewClients(string $from, string $to): int
    {
        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.utilisateur) AS uid')
            ->where('p.utilisateur IS NOT NULL')
            ->groupBy('p.utilisateur')
            ->having('MIN(p.createdAt) >= :from AND MIN(p.createdAt) <= :to')
            ->setParameter('from', new \DateTimeImmutable($from . ' 00:00:00'))
            ->setParameter('to', new \DateTimeImmutable($to . ' 23:59:59'))
            ->getQuery()
            ->getArrayResult();

        return count($rows);
    }

    /**
     * Identifiants distincts des acheteurs ayant effectué au moins un achat sur la
     * fenêtre [debut, fin[. Sert au calcul du taux de rétention (ré-achat mensuel).
     *
     * @return int[]
     */
    public function buyerIdsForPeriod(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('DISTINCT IDENTITY(p.utilisateur) AS uid')
            ->where('p.utilisateur IS NOT NULL')
            ->andWhere('p.createdAt >= :debut AND p.createdAt < :fin')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r) => (int) $r['uid'], $rows);
    }

    /**
     * Bornes [début, fin[ d'une année civile (1ᵉʳ janvier inclus → 1ᵉʳ janvier
     * suivant exclu).
     *
     * @return array{0:\DateTimeImmutable, 1:\DateTimeImmutable}
     */
    private function bornesAnnee(int $annee): array
    {
        return [
            new \DateTimeImmutable(sprintf('%d-01-01 00:00:00', $annee)),
            new \DateTimeImmutable(sprintf('%d-01-01 00:00:00', $annee + 1)),
        ];
    }
}
