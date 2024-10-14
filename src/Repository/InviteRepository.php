<?php

namespace App\Repository;

use App\Entity\Invite;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<Invite>
 */
class InviteRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private PaginatorInterface $paginator,
        private Security $security
    ) {
        parent::__construct($registry, Invite::class);
    }

    public function paginateInvites(int $page): PaginationInterface
    {
        /** @var Utilisateur $user */
        $user = $this->security->getUser();
        $userId = $user->getId();

        return $this->paginator->paginate(
            $this->createQueryBuilder("i")
                ->where('i.utilisateur =:user')
                ->setParameter('user', '' . $userId . '')
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
