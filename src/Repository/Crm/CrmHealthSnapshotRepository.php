<?php

namespace App\Repository\Crm;

use App\Entity\Crm\CrmHealthSnapshot;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CrmHealthSnapshot>
 */
class CrmHealthSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CrmHealthSnapshot::class);
    }

    /**
     * Derniers instantanés d'un client (tendance), du plus ancien au plus récent.
     *
     * @return CrmHealthSnapshot[]
     */
    public function trendForClient(Utilisateur $client, int $limit = 30): array
    {
        $rows = $this->createQueryBuilder('s')
            ->where('s.utilisateur = :u')
            ->setParameter('u', $client)
            ->orderBy('s.capturedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_reverse($rows);
    }

    /** Vrai si un instantané a déjà été pris aujourd'hui pour ce client (anti-doublon). */
    public function hasToday(Utilisateur $client): bool
    {
        $debut = new \DateTimeImmutable('today 00:00:00');

        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.utilisateur = :u')
            ->andWhere('s.capturedAt >= :debut')
            ->setParameter('u', $client)
            ->setParameter('debut', $debut)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
