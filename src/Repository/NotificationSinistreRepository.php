<?php

namespace App\Repository;

use App\Entity\NotificationSinistre;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<NotificationSinistre>
 */
class NotificationSinistreRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private PaginatorInterface $paginator,
        private Security $security
    )
    {
        parent::__construct($registry, NotificationSinistre::class);
    }

    //    /**
    //     * @return NotificationSinistre[] Returns an array of NotificationSinistre objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('n')
    //            ->andWhere('n.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('n.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?NotificationSinistre
    //    {
    //        return $this->createQueryBuilder('n')
    //            ->andWhere('n.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }

    public function paginateForEntreprise(int $idEntreprise, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("notification")
                ->leftJoin("notification.invite", "invite")
                ->where("invite.entreprise = :entrepriseId")
                ->setParameter('entrepriseId', '' . $idEntreprise . '')
                ->orderBy('notification.id', 'DESC'),
            $page,
            20,
        );
    }

    public function paginateForInvite(int $idInvite, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("n")
                ->where("n.invite = :inviteId")
                ->setParameter('inviteId', '' . $idInvite . '')
                ->orderBy('n.id', 'DESC'),
            $page,
            20,
        );
    }

    public function paginateForClient(int $idClient, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("n")
                ->where("n.client = :clientId")
                ->setParameter('clientId', '' . $idClient . '')
                ->orderBy('n.id', 'DESC'),
            $page,
            20,
        );
    }

    public function paginateForReferencePolice(string $referencePolice, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("n")
                ->where("n.referencePolice like '%:policeReference%'")
                ->setParameter('policeReference', '' . $referencePolice . '')
                ->orderBy('n.id', 'DESC'),
            $page,
            20,
        );
    }

    public function paginateForReferenceSinistre(string $referenceSinistre, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("n")
                ->where("n.referenceSinistre like '%:claimReference%'")
                ->setParameter('claimReference', '' . $referenceSinistre . '')
                ->orderBy('n.id', 'DESC'),
            $page,
            20,
        );
    }
}
