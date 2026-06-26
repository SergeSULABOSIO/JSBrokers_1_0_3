<?php

namespace App\Repository\Crm;

use App\Entity\Crm\CrmTicket;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Knp\Component\Pager\PaginatorInterface;

/**
 * @extends ServiceEntityRepository<CrmTicket>
 */
class CrmTicketRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private PaginatorInterface $paginator,
    ) {
        parent::__construct($registry, CrmTicket::class);
    }

    /** Liste paginée des tickets, filtrable par statut, plus récents d'abord. */
    public function paginateFiltered(?string $statut, int $page): PaginationInterface
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.client', 'c')->addSelect('c')
            ->orderBy('t.createdAt', 'DESC');

        if ($statut) {
            $qb->where('t.statut = :s')->setParameter('s', $statut);
        }

        return $this->paginator->paginate($qb, $page, 20);
    }

    /**
     * Tickets d'un client, plus récents d'abord.
     *
     * @return CrmTicket[]
     */
    public function findForClient(Utilisateur $client): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.client = :client')
            ->setParameter('client', $client)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Nombre de tickets ouverts (ouvert/en cours) d'un client — critère santé. */
    public function countOpenForClient(int $clientId): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.client = :c')
            ->andWhere('t.statut IN (:ouverts)')
            ->setParameter('c', $clientId)
            ->setParameter('ouverts', [CrmTicket::STATUT_OUVERT, CrmTicket::STATUT_EN_COURS])
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Tickets ouverts dont le SLA est dépassé (escalade / notification).
     *
     * @return CrmTicket[]
     */
    public function findSlaBreached(int $limit = 50): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.client', 'c')->addSelect('c')
            ->where('t.statut IN (:ouverts)')
            ->andWhere('t.slaDueAt IS NOT NULL AND t.slaDueAt < :now')
            ->setParameter('ouverts', [CrmTicket::STATUT_OUVERT, CrmTicket::STATUT_EN_COURS])
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('t.slaDueAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return array{ouvert:int, en_cours:int, resolu:int, clos:int} */
    public function countByStatut(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.statut AS s, COUNT(t.id) AS nb')
            ->groupBy('t.statut')
            ->getQuery()
            ->getArrayResult();

        $map = ['ouvert' => 0, 'en_cours' => 0, 'resolu' => 0, 'clos' => 0];
        foreach ($rows as $r) {
            $map[(string) $r['s']] = (int) $r['nb'];
        }

        return $map;
    }
}
