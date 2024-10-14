<?php

namespace App\Repository;

use App\Entity\Invite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Knp\Component\Pager\PaginatorInterface;

/**
 * @extends ServiceEntityRepository<Invite>
 */
class InviteRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private PaginatorInterface $paginator
    ) {
        parent::__construct($registry, Invite::class);
    }

    public function paginateInvites(int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("i")
                ->orderBy('i.id', 'DESC'),
            $page,
            5,
            [
                'distinct' => false,
                'sortFieldAllowList' => [
                    'i.email',
                    'i.createdAt'
                ],
            ]
        );
    }

    //    /**
    //     * @return Invite[] Returns an array of Invite objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('i')
    //            ->andWhere('i.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('i.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Invite
    //    {
    //        return $this->createQueryBuilder('i')
    //            ->andWhere('i.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
