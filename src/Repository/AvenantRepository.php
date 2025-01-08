<?php

namespace App\Repository;

use App\Entity\Avenant;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<Avenant>
 */
class AvenantRepository extends ServiceEntityRepository
{
    public function __construct(
        private ManagerRegistry $registry,
        private PaginatorInterface $paginator,
        private Security $security
    )
    {
        parent::__construct($registry, Avenant::class);
    }

    //    /**
    //     * @return Avenant[] Returns an array of Avenant objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('a')
    //            ->andWhere('a.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('a.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Avenant
    //    {
    //        return $this->createQueryBuilder('a')
    //            ->andWhere('a.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
    public function paginateForEntreprise(int $idEntreprise, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder('avenant')
                ->leftJoin("avenant.cotation", "cotation")
                ->leftJoin("cotation.piste", "piste")
                ->leftJoin("piste.invite", "invite")
                ->where('invite.entreprise = :entrepriseId')
                ->setParameter('entrepriseId', ''.$idEntreprise.'')
                ->orderBy('avenant.id', 'DESC'),
            $page,
            20,
        );
    }

    public function paginateForInvite(int $idInvite, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder('a')
                ->leftJoin("a.cotation", "c")
                ->leftJoin("c.piste", "p")
                ->where('p.invite = :inviteId')
                ->setParameter('inviteId', ''.$idInvite.'')
                ->orderBy('a.id', 'DESC'),
            $page,
            20,
        );
    }
}
