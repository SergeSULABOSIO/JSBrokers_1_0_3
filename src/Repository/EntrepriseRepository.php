<?php

namespace App\Repository;

use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @extends ServiceEntityRepository<Entreprise>
 *
 * @method Entreprise|null find($id, $lockMode = null, $lockVersion = null)
 * @method Entreprise|null findOneBy(array $criteria, array $orderBy = null)
 * @method Entreprise[]    findAll()
 * @method Entreprise[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EntrepriseRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private PaginatorInterface $paginator,
        private Security $security
    ) {
        parent::__construct($registry, Entreprise::class);
    }

    public function save(Entreprise $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Entreprise $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return Entreprise[] Returns an array of Entreprise objects
     */
    public function findByMotCle($criteres): array
    {
        $query = $this->createQueryBuilder('e')
            ->where('e.nom like :valMotCle')
            ->orWhere('e.adresse like :valMotCle')
            ->orWhere('e.telephone like :valMotCle')
            ->orWhere('e.rccm like :valMotCle')
            ->orWhere('e.idnat like :valMotCle')
            ->orWhere('e.numimpot like :valMotCle')
            ->setParameter('valMotCle', '%' . $criteres['motcle'] . '%')
            ->orderBy('e.id', 'DESC');

        $query = $query
            ->getQuery()
            ->getResult();

        return $query;
    }


    /**
     * Nombre d'entreprises visibles par l'utilisateur courant : celles qu'il possède
     * ET celles auxquelles il est invité (invitation rattachée à son compte).
     */
    public function getNBEntreprises(): int
    {
        /** @var Utilisateur $user */
        $user = $this->security->getUser();

        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(DISTINCT e.id)')
            ->where('e.utilisateur = :uid')
            ->orWhere('EXISTS (SELECT 1 FROM App\Entity\Invite i WHERE i.entreprise = e AND i.utilisateur = :uid)')
            ->setParameter('uid', $user->getId())
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getNBMyProperEntreprises(): int
    {
        /** @var Utilisateur $user */
        $user = $this->security->getUser();
        return count(
            $this->createQueryBuilder('e')
                ->where('e.utilisateur =:userId')
                ->setParameter('userId', $user->getId())
                ->orderBy('e.id', 'ASC')
                ->getQuery()
                ->getResult()
        );
    }

    /**
     * Liste paginée des entreprises visibles par l'utilisateur : celles qu'il possède
     * ET celles auxquelles il est invité (invitation rattachée à son compte). La
     * sous-requête EXISTS évite toute duplication de lignes due à une jointure.
     */
    public function paginateUtilisateur(int $idUtilisateur, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("e")
                ->where('e.utilisateur = :uid')
                ->orWhere('EXISTS (SELECT 1 FROM App\Entity\Invite i WHERE i.entreprise = e AND i.utilisateur = :uid)')
                ->setParameter('uid', $idUtilisateur)
                ->orderBy('e.id', 'DESC'),
            $page,
            3,
        );
    }

    //    public function findOneBySomeField($value): ?Entreprise
    //    {
    //        return $this->createQueryBuilder('e')
    //            ->andWhere('e.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
