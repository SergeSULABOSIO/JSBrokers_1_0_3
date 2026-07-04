<?php

namespace App\Repository;

use App\Entity\Portefeuille;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<Portefeuille>
 */
class PortefeuilleRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private PaginatorInterface $paginator,
        private Security $security
    )
    {
        parent::__construct($registry, Portefeuille::class);
    }

    public function paginateForEntreprise(int $idEntreprise, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("portefeuille")
                ->where('portefeuille.entreprise = :entrepriseId')
                ->setParameter('entrepriseId', '' . $idEntreprise . '')
                ->orderBy('portefeuille.id', 'DESC'),
            $page,
            20,
        );
    }
}
