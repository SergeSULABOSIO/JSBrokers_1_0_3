<?php

namespace App\Repository;

use App\Entity\Paiement;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<Paiement>
 */
class PaiementRepository extends ServiceEntityRepository
{
    public function __construct(
        private ManagerRegistry $registry,
        private PaginatorInterface $paginator,
        private Security $security
    )
    {
        parent::__construct($registry, Paiement::class);
    }

    //    /**
    //     * @return Paiement[] Returns an array of Paiement objects
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

    //    public function findOneBySomeField($value): ?Paiement
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }

    public function paginateForEntreprise(int $idEntreprise, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("paiement")
                //via offre d'indemnisation
                ->leftJoin("paiement.offreIndemnisationSinistre", "offre")
                ->leftJoin("offre.notificationSinistre", "notification")
                ->leftJoin("notification.invite", "invite")
                //via facture
                ->leftJoin("paiement.note", "note")
                ->leftJoin("note.invite", "inviteb")
                //condition
                ->where("invite.entreprise = :entrepriseId")
                ->orWhere("inviteb.entreprise = :entrepriseId")
                ->setParameter('entrepriseId', '' . $idEntreprise . '')
                ->orderBy('paiement.id', 'DESC'),
            $page,
            20,
        );
    }


    /**
     * Paiements de NOTES d'une entreprise (les paiements de sinistres, sans note,
     * sont exclus : ils n'impactent pas la trésorerie du courtier), chronologiques
     * par date de paiement — source du moteur d'écritures comptables du courtier
     * (CourtierEcritureComptableService). Fetch-join des articles, du bordereau et
     * de l'autorité fiscale pour éviter les N+1 lors de la ventilation HT/taxe.
     *
     * @return Paiement[]
     */
    public function findChronologiqueForEntreprise(int $idEntreprise): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.note', 'n')->addSelect('n')
            ->join('n.invite', 'i')
            ->leftJoin('n.articles', 'a')->addSelect('a')
            ->leftJoin('n.bordereau', 'b')->addSelect('b')
            ->leftJoin('n.autoritefiscale', 'af')->addSelect('af')
            ->leftJoin('af.taxe', 'tx')->addSelect('tx')
            ->where('i.entreprise = :entrepriseId')
            ->andWhere('p.offreIndemnisationSinistre IS NULL')
            ->andWhere('p.paidAt IS NOT NULL')
            ->setParameter('entrepriseId', $idEntreprise)
            ->orderBy('p.paidAt', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function paginateForInvite(int $idInvite, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("o")
                ->leftJoin("o.offreIndemnisationSinistre", "oi")
                ->leftJoin("oi.notificationSinistre", "ns")
                ->leftJoin("oi.factureCommission", "fc")
                ->where("ns.invite = :inviteId")
                ->Orwhere("fc.invite = :inviteId")
                ->setParameter('inviteId', '' . $idInvite . '')
                ->orderBy('o.id', 'DESC'),
            $page,
            20,
        );
    }
}
