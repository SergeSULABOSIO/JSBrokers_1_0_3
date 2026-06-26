<?php

namespace App\Repository\Crm;

use App\Entity\Crm\CrmNotification;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CrmNotification>
 */
class CrmNotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CrmNotification::class);
    }

    private function visibleQb(Utilisateur $agent): \Doctrine\ORM\QueryBuilder
    {
        // Notifications adressées à l'agent OU diffusées à tous (agent NULL).
        return $this->createQueryBuilder('n')
            ->where('n.agent = :agent OR n.agent IS NULL')
            ->setParameter('agent', $agent);
    }

    /**
     * Notifications visibles par un agent, plus récentes d'abord.
     *
     * @return CrmNotification[]
     */
    public function forAgent(Utilisateur $agent, int $limit = 50): array
    {
        return $this->visibleQb($agent)
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Nombre de notifications non lues visibles par un agent (badge). */
    public function countUnreadForAgent(Utilisateur $agent): int
    {
        return (int) $this->visibleQb($agent)
            ->select('COUNT(n.id)')
            ->andWhere('n.lu = false')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Marque comme lues toutes les notifications visibles par un agent. */
    public function markAllReadForAgent(Utilisateur $agent): void
    {
        $this->getEntityManager()->createQuery(
            'UPDATE App\Entity\Crm\CrmNotification n SET n.lu = true WHERE (n.agent = :agent OR n.agent IS NULL) AND n.lu = false',
        )->setParameter('agent', $agent)->execute();
    }
}
