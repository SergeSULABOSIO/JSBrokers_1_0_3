<?php

namespace App\Repository\Crm;

use App\Entity\Crm\CrmAutomationLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CrmAutomationLog>
 */
class CrmAutomationLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CrmAutomationLog::class);
    }

    /** Vrai si une règle a déjà été déclenchée pour une clé d'entité donnée. */
    public function hasFired(string $regle, string $cleEntite): bool
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.regle = :r AND l.cleEntite = :c')
            ->setParameter('r', $regle)
            ->setParameter('c', $cleEntite)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Enregistre un déclenchement (sans flush). Renvoie false si déjà présent
     * (idempotence applicative, doublée par la contrainte d'unicité en base).
     */
    public function record(string $regle, string $cleEntite): bool
    {
        if ($this->hasFired($regle, $cleEntite)) {
            return false;
        }

        $log = (new CrmAutomationLog())->setRegle($regle)->setCleEntite($cleEntite);
        $this->getEntityManager()->persist($log);

        return true;
    }
}
