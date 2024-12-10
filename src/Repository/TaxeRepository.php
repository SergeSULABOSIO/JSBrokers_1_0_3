<?php

namespace App\Repository;


use App\Entity\Taxe;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<Taxe>
 *
 * @method Taxe|null find($id, $lockMode = null, $lockVersion = null)
 * @method Taxe|null findOneBy(array $criteria, array $orderBy = null)
 * @method Taxe[]    findAll()
 * @method Taxe[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TaxeRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private PaginatorInterface $paginator,
        private Security $security
    ) {
        parent::__construct($registry, Taxe::class);
    }

    public function save(Taxe $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Taxe $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return Taxe[] Returns an array of Taxe objects
     */
    public function findByMotCle($criteres): array
    {
        $query = $this->createQueryBuilder('t')
            ->where('t.code like :valMotCle')
            ->orWhere('t.description like :valMotCle')
            ->orWhere('t.organisation like :valMotCle')
            ->setParameter('valMotCle', '%' . $criteres['motcle'] . '%')
            ->orderBy('t.id', 'DESC');

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

    public function paginateTaxe(int $idEntreprise, int $page): PaginationInterface
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
            20,
        );
    }

    //    public function findOneBySomeField($value): ?Taxe
    //    {
    //        return $this->createQueryBuilder('t')
    //            ->andWhere('t.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
