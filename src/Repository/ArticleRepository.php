<?php

namespace App\Repository;

use App\Entity\Taxe;
use App\Entity\Article;
use App\Entity\RevenuPourCourtier;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<Article>
 */
class ArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Article::class);
    }

    //    /**
    //     * @return Article[] Returns an array of Article objects
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

    //    public function findOneBySomeField($value): ?Article
    //    {
    //        return $this->createQueryBuilder('a')
    //            ->andWhere('a.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }

    public function findAllArticlesWhereTaxeDue(?RevenuPourCourtier $revenu, ?Taxe $taxe): array
    {
        return $this->createQueryBuilder("article")
            //via invite
            ->leftJoin("article.tranche", "tranche")
            //condition
            ->where("article.idPoste = :idPoste")//Adressée à l'autorité fiscale
            ->andWhere("tranche.cotation = :idCotation")
            ->setParameter('idPoste', '' . $taxe->getId() . '')
            ->setParameter('idCotation', '' . $revenu->getCotation()->getId() . '')
            ->orderBy('article.id', 'DESC')
            ->getQuery()
            ->getResult()
        ;
    }
}
