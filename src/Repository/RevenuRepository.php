<?php

namespace App\Repository;

use App\Entity\Revenu;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<Revenu>
 */
class RevenuRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private PaginatorInterface $paginator,
        private Security $security
    ) {
        parent::__construct($registry, Revenu::class);
    }

    //    /**
    //     * @return Revenu[] Returns an array of Revenu objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('r')
    //            ->andWhere('r.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('r.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Revenu
    //    {
    //        return $this->createQueryBuilder('r')
    //            ->andWhere('r.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }

    public function paginate(int $idEntreprise, int $page): PaginationInterface
    {
        /** @var Utilisateur $user */
        // $user = $this->security->getUser();

        return $this->paginator->paginate(
            $this->createQueryBuilder("m")
                // ->leftJoin("e.invites", "i")
                ->where('m.entreprise = :entrepriseId')
                // ->orWhere("i.email = :userEmail")
                ->setParameter('entrepriseId', '' . $idEntreprise . '')
                // ->setParameter('userEmail', '' . $user->getEmail() . '')
                ->orderBy('m.id', 'DESC'),
            $page,
            20,
        );
    }
}