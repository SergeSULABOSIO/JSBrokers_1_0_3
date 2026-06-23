<?php

namespace App\Repository;

use App\Entity\TaxeVente;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Knp\Component\Pager\PaginatorInterface;

/**
 * @extends ServiceEntityRepository<TaxeVente>
 */
class TaxeVenteRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private PaginatorInterface $paginator,
    ) {
        parent::__construct($registry, TaxeVente::class);
    }

    /** Liste paginée de toutes les taxes, plus récentes d'abord. */
    public function paginateAll(int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder('t')->orderBy('t.id', 'DESC'),
            $page,
            20,
        );
    }

    /**
     * Taxes actives (assise du calcul du revenu hors taxe et du bloc Fiscalité).
     *
     * @return TaxeVente[]
     */
    public function findActives(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.actif = true')
            ->orderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
