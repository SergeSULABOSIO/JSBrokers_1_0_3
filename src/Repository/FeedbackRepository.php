<?php

namespace App\Repository;

use App\Entity\Feedback;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<Feedback>
 */
class FeedbackRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private PaginatorInterface $paginator,
        private Security $security
    )
    {
        parent::__construct($registry, Feedback::class);
    }

    //    /**
    //     * @return Feedback[] Returns an array of Feedback objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('f')
    //            ->andWhere('f.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('f.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Feedback
    //    {
    //        return $this->createQueryBuilder('f')
    //            ->andWhere('f.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }

    public function paginateForEntreprise(int $idEntreprise, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("feedback")
                //via la piste
                ->leftJoin("feedback.tache", "tache")
                ->leftJoin("tache.piste", "piste")
                ->leftJoin("piste.invite", "invite")
                //via cotation
                ->leftJoin("tache.cotation", "cotation")
                ->leftJoin("cotation.piste", "pisteb")
                ->leftJoin("pisteb.invite", "inviteb")
                //via notification sinistre
                ->leftJoin("tache.notificationSinistre", "notification")
                ->leftJoin("notification.invite", "invitec")
                //via offre d'indemnisation sinistre
                ->leftJoin("tache.offreIndemnisationSinistre", "offre")
                ->leftJoin("offre.notificationSinistre", "notificationb")
                ->leftJoin("notificationb.invite", "invited")
                
                //Conditions
                ->Where("invite.entreprise = :entrepriseId")
                ->orWhere("inviteb.entreprise = :entrepriseId")
                ->orWhere("invitec.entreprise = :entrepriseId")
                ->orWhere("invited.entreprise = :entrepriseId")
                //Paramètres
                ->setParameter('entrepriseId', '' . $idEntreprise . '')
                //Organisation
                ->orderBy('tache.id', 'DESC'),
            $page,
            20,
        );
    }
}
