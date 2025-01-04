<?php

namespace App\Repository;

use App\Entity\Bordereau;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<Bordereau>
 */
class BordereauRepository extends ServiceEntityRepository
{
    public function __construct(
        private ManagerRegistry $registry,
        private PaginatorInterface $paginator,
        private Security $security
    )
    {
        parent::__construct($registry, Bordereau::class);
    }

    //    /**
    //     * @return Bordereau[] Returns an array of Bordereau objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('b')
    //            ->andWhere('b.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('b.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Bordereau
    //    {
    //        return $this->createQueryBuilder('b')
    //            ->andWhere('b.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }

    public function paginateForEntreprise(int $idEntreprise, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("bordereau")
                //via le client
                ->leftJoin("bordereau.invite", "invite")
                //condition
                ->where('invite.entreprise = :entrepriseId')
                //paramètres
                ->setParameter('entrepriseId', '' . $idEntreprise . '')
                //ordre de données
                ->orderBy('bordereau.id', 'DESC'),
            $page,
            20,
        );
    }
}
