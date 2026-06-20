<?php

namespace App\Repository;

use App\Entity\TokenConsumption;
use App\Entity\Utilisateur;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<TokenConsumption>
 */
class TokenConsumptionRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private PaginatorInterface $paginator,
    ) {
        parent::__construct($registry, TokenConsumption::class);
    }

    /**
     * Historique de consommation d'un propriétaire (payeur), du plus récent au
     * plus ancien, paginé.
     */
    public function paginateForProprietaire(int $idProprietaire, int $page, int $perPage = 20): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder('c')
                ->where('c.proprietaire = :uid')
                ->setParameter('uid', $idProprietaire)
                ->orderBy('c.createdAt', 'DESC')
                ->addOrderBy('c.id', 'DESC'),
            $page,
            $perPage,
        );
    }

    /** Total de tokens consommés par un propriétaire (tous sens confondus). */
    public function totalConsommeForProprietaire(int $idProprietaire): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COALESCE(SUM(c.poidsTotal), 0)')
            ->where('c.proprietaire = :uid')
            ->setParameter('uid', $idProprietaire)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
