<?php

namespace App\Repository\Crm;

use App\Entity\Crm\CrmTache;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CrmTache>
 */
class CrmTacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CrmTache::class);
    }

    /**
     * Tâches d'un client (toutes), plus urgentes d'abord.
     *
     * @return CrmTache[]
     */
    public function findForClient(Utilisateur $client): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.client = :client')
            ->setParameter('client', $client)
            ->orderBy('t.statut', 'ASC')
            ->addOrderBy('t.dueAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Tâches ouvertes (à faire) assignées à un agent (ou toutes si null),
     * échéance croissante. Alimente le tableau de bord commercial.
     *
     * @return CrmTache[]
     */
    public function findOuvertes(?Utilisateur $agent = null, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.client', 'c')->addSelect('c')
            ->where('t.statut = :statut')
            ->setParameter('statut', CrmTache::STATUT_A_FAIRE)
            ->orderBy('t.dueAt', 'ASC')
            ->setMaxResults($limit);

        if ($agent !== null) {
            $qb->andWhere('t.assigneA = :agent')->setParameter('agent', $agent);
        }

        return $qb->getQuery()->getResult();
    }

    /** Vrai si une tâche automatique portant cette clé existe déjà (idempotence). */
    public function existsByCleAuto(string $cleAuto): bool
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.cleAuto = :cle')
            ->setParameter('cle', $cleAuto)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
