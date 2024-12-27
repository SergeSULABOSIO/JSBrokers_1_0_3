<?php

namespace App\Repository;

use App\Entity\Document;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<Document>
 */
class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private PaginatorInterface $paginator,
        private Security $security
    )
    {
        parent::__construct($registry, Document::class);
    }

    //    /**
    //     * @return Document[] Returns an array of Document objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('d')
    //            ->andWhere('d.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('d.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Document
    //    {
    //        return $this->createQueryBuilder('d')
    //            ->andWhere('d.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }


    public function paginateForInvite(int $idInvite, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("do")
                ->leftJoin("do.piste", "pi")
                ->where('pi.invite = :inviteId')
                ->setParameter('inviteId', '' . $idInvite . '')
                ->orderBy('do.id', 'DESC'),
            $page,
            20,
        );
    }

    public function paginateForEntreprise(int $idEntreprise, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("document")
                ->leftJoin("document.cotation", "cotation")
                ->leftJoin("cotation.piste", "piste")
                ->leftJoin("piste.invite", "invite")
                // ->leftJoin("invite.entreprise", "entreprise")
                // ->leftJoin('pi.invite', "in")
                ->where('invite.entreprise = :entrepriseId')
                ->setParameter('entrepriseId', '' . $idEntreprise . '')
                ->orderBy('document.id', 'DESC'),
            $page,
            20,
        );
    }
}
