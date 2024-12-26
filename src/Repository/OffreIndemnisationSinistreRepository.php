<?php

namespace App\Repository;

use Doctrine\Persistence\ManagerRegistry;
use App\Entity\OffreIndemnisationSinistre;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<OffreIndemnisationSinistre>
 */
class OffreIndemnisationSinistreRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private PaginatorInterface $paginator,
        private Security $security
    )
    {
        parent::__construct($registry, OffreIndemnisationSinistre::class);
    }

    //    /**
    //     * @return OffreIndemnisationSinistre[] Returns an array of OffreIndemnisationSinistre objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('o')
    //            ->andWhere('o.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('o.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?OffreIndemnisationSinistre
    //    {
    //        return $this->createQueryBuilder('o')
    //            ->andWhere('o.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }

    public function paginateForEntreprise(int $idEntreprise, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("o")
                ->leftJoin("o.notificationSinistre", "n")
                ->leftJoin("n.invite", "i")
                ->where("i.entreprise = :entrepriseId")
                ->setParameter('entrepriseId', '' . $idEntreprise . '')
                ->orderBy('o.id', 'DESC'),
            $page,
            20,
        );
    }

    public function paginateForInvite(int $idInvite, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("o")
                ->leftJoin("o.notificationSinistre", "n")
                ->where("n.invite = :inviteId")
                ->setParameter('inviteId', '' . $idInvite . '')
                ->orderBy('o.id', 'DESC'),
            $page,
            20,
        );
    }
}
