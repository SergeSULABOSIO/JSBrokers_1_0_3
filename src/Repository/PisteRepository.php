<?php

namespace App\Repository;

use App\Entity\Piste;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<Piste>
 */
class PisteRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private PaginatorInterface $paginator,
        private Security $security
    )
    {
        parent::__construct($registry, Piste::class);
    }

    //    /**
    //     * @return Piste[] Returns an array of Piste objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('p.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Piste
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
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
