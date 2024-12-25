<?php

namespace App\Repository;

use App\Entity\Tache;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<Tache>
 */
class TacheRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private PaginatorInterface $paginator,
        private Security $security
    )
    {
        parent::__construct($registry, Tache::class);
    }

    //    /**
    //     * @return Tache[] Returns an array of Tache objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('t')
    //            ->andWhere('t.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('t.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Tache
    //    {
    //        return $this->createQueryBuilder('t')
    //            ->andWhere('t.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }

    public function paginateForInvite(int $page): PaginationInterface
    {
        /** @var Utilisateur $user */
        $user = $this->security->getUser();

        return $this->paginator->paginate(
            $this->createQueryBuilder("t")
                //Jointures
                // ->leftJoin("t.invite", "i")
                // ->leftJoin("t.executor", "e")
                //Conditions
                // ->Where("i.email = :userEmail")
                // ->orWhere("e.email = :userEmail")
                //Paramètres
                // ->setParameter('userEmail', '' . $user->getEmail() . '')
                //Organisation
                ->orderBy('t.id', 'DESC'),
            $page,
            20,
        );
    }
    
}
