<?php

namespace App\Repository;

use App\Entity\Monnaie;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<Monnaie>
 *
 * @method Monnaie|null find($id, $lockMode = null, $lockVersion = null)
 * @method Monnaie|null findOneBy(array $criteria, array $orderBy = null)
 * @method Monnaie[]    findAll()
 * @method Monnaie[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class MonnaieRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private PaginatorInterface $paginator,
        private Security $security
    ) {
        parent::__construct($registry, Monnaie::class);
    }

    public function save(Monnaie $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Monnaie $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return Monnaie[] Returns an array of Monnaie objects
     */
    public function findByMotCle($criteres): array
    {
        //dd($criteres);
        $query = $this->createQueryBuilder('c')
            ->where('c.nom like :valMotCle')
            ->orWhere('c.code like :valMotCle')
            ->setParameter('valMotCle', '%' . $criteres['motcle'] . '%')
            ->orderBy('c.id', 'DESC');

        if ($criteres['islocale'] !== null) {
            $query = $query
                ->andWhere('c.islocale like :valIsSlocale')
                ->setParameter('valIsSlocale', $criteres['islocale']);
        }

        $query = $query
            ->getQuery()
            ->getResult();

        return $query;
    }


    public function stat_get_nombres_enregistrements()
    {
        return $this->createQueryBuilder('a')
            ->select('count(a.id) as nombre')
            //    ->select('a.exampleField = :val')
            //    ->setParameter('val', $value)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }


    public function paginateMonnaie(int $idEntreprise, int $page): PaginationInterface
    {
        /** @var Utilisateur $user */
        $user = $this->security->getUser();

        return $this->paginator->paginate(
            $this->createQueryBuilder("m")
                // ->leftJoin("e.invites", "i")
                ->where('m.entreprise = :entrepriseId')
                // ->orWhere("i.email = :userEmail")
                ->setParameter('entrepriseId', '' . $idEntreprise . '')
                // ->setParameter('userEmail', '' . $user->getEmail() . '')
                ->orderBy('m.id', 'DESC'),
            $page,
            3,
        );
    }


    //    public function findOneBySomeField($value): ?Monnaie
    //    {
    //        return $this->createQueryBuilder('m')
    //            ->andWhere('m.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
