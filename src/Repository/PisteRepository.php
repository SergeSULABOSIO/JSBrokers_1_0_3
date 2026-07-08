<?php

namespace App\Repository;

use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Piste;
use App\Entity\Risque;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<Piste>
 */
class PisteRepository extends ServiceEntityRepository
{
    public function __construct(
        private ManagerRegistry $registry,
        private PaginatorInterface $paginator,
        private Security $security
    )
    {
        parent::__construct($registry, Piste::class);
    }

    //    /**
    //     * @return Piste[] Returns an array of Piste objects
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

    //    public function findOneBySomeField($value): ?Piste
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }

    public function paginateForInvite(int $idInvite, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("p")
                ->where("p.invite = :inviteId")
                ->setParameter('inviteId', '' . $idInvite . '')
                ->orderBy('p.id', 'DESC'),
            $page,
            20,
        );
    }

    /**
     * Retourne la piste d'exercice antérieur la plus récente pour un même couple
     * client + risque au sein d'une entreprise. Sert à reconduire le partage
     * partenaire lors de la création d'une piste d'exercice via import bordereau.
     */
    public function findLatestPrevious(Client $client, ?Risque $risque, Entreprise $entreprise, int $exercice): ?Piste
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.client = :client')
            ->andWhere('p.entreprise = :entreprise')
            ->andWhere('p.exercice < :exercice')
            ->setParameter('client', $client)
            ->setParameter('entreprise', $entreprise)
            ->setParameter('exercice', $exercice)
            ->orderBy('p.exercice', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults(1);

        if ($risque !== null) {
            $qb->andWhere('p.risque = :risque')->setParameter('risque', $risque);
        } else {
            $qb->andWhere('p.risque IS NULL');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function paginateForEntreprise(int $idEntreprise, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("p")
                ->leftJoin("p.invite", "i")
                ->where("i.entreprise = :entrepriseId")
                ->setParameter('entrepriseId', '' . $idEntreprise . '')
                ->orderBy('p.id', 'DESC'),
            $page,
            20,
        );
    }
}