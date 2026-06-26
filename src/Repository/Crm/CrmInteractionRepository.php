<?php

namespace App\Repository\Crm;

use App\Entity\Crm\CrmInteraction;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CrmInteraction>
 */
class CrmInteractionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CrmInteraction::class);
    }

    /**
     * Interactions d'un client, plus récentes d'abord.
     *
     * @return CrmInteraction[]
     */
    public function findForClient(Utilisateur $client, int $limit = 100): array
    {
        return $this->createQueryBuilder('i')
            ->leftJoin('i.agent', 'a')->addSelect('a')
            ->where('i.client = :client')
            ->setParameter('client', $client)
            ->orderBy('i.occurredAt', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
