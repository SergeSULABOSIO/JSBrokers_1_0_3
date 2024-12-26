<?php

namespace App\Repository;

use App\DTO\ElementListeInviteDTO;
use App\Entity\Invite;
use App\Entity\Utilisateur;
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

    public function getNBInvites(): int
    {
        /** @var Utilisateur $user */
        $user = $this->security->getUser();
        $userId = $user->getId();

        return count(
            $this->createQueryBuilder("i")
                // ->select("count(i.id)")
                ->where('i.utilisateur =:user')
                ->setParameter('user', '' . $userId . '')
                ->groupBy("i.id")
                ->orderBy('i.id', 'DESC')
                ->getQuery()
                ->getResult()
        );
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
                ->groupBy("i.id")
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


    /** 
     * @return ElementListeInviteDTO[]
     */
    // public function paginateInvitesWithCount(): array
    // {
    //     /** @var Utilisateur $user */
    //     $user = $this->security->getUser();

    //     return $this->createQueryBuilder("i")
    //         // ->select("i as invite", "COUNT(i.id) as total")
    //         ->select("NEW App\\DTO\\ElementListeInviteDTO(i.id, i.email, i.createdAt, COUNT(i.id))")
    //         ->where("i.utilisateur = :userId")
    //         ->setParameter('userId', $user->getId())
    //         ->leftJoin("i.entreprises", "e")
    //         ->groupBy("i.id")
    //         ->orderBy('i.id', 'DESC')
    //         ->getQuery()
    //         ->getResult();
    // }

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

    public function findOneByEmail($email): ?Invite
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    public function paginateForEntreprise(int $idEntreprise, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("m")
                ->where('m.entreprise = :entrepriseId')
                ->setParameter('entrepriseId', '' . $idEntreprise . '')
                ->orderBy('m.id', 'DESC'),
            $page,
            20,
        );
    }
}
