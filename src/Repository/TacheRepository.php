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

    public function paginateForInvite(int $idInvite, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("i")
                //Jointures
                ->leftJoin("i.piste", "p")
                //Conditions
                ->Where("p.invite = :inviteId")
                // ->orWhere("e.email = :userEmail")
                //Paramètres
                ->setParameter('inviteId', '' . $idInvite . '')
                //Organisation
                ->orderBy('i.id', 'DESC'),
            $page,
            20,
        );
    }

    public function paginateForEntreprise(int $idEntreprise, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("tache")
                //via la piste
                ->leftJoin("tache.piste", "piste")
                ->leftJoin("piste.invite", "invitePiste")
                //via la notification sinistre
                ->leftJoin("tache.notificationSinistre", "notification")
                ->leftJoin("notification.invite", "inviteClaim")
                //via l'offre d'indemnisation
                ->leftJoin("tache.offreIndemnisationSinistre", "tacheoffre")
                ->leftJoin("tacheoffre.notificationSinistre", "tacheoffrenotification")
                ->leftJoin("tacheoffrenotification.invite", "tacheoffrenotificationinvite")


                //Conditions
                ->Where("invitePiste.entreprise = :entrepriseId")
                ->orWhere("inviteClaim.entreprise = :entrepriseId")
                ->orWhere("tacheoffrenotificationinvite.entreprise = :entrepriseId")
                //Paramètres
                ->setParameter('entrepriseId', '' . $idEntreprise . '')
                //Organisation
                ->orderBy('tache.id', 'DESC'),
            $page,
            20,
        );
    }
    
}
