<?php

namespace App\Repository;

use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<Utilisateur>
 */
class UtilisateurRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(
        ManagerRegistry $registry,
        private PaginatorInterface $paginator,
    ) {
        parent::__construct($registry, Utilisateur::class);
    }

    /**
     * Agents JS Brokers : tout compte portant ROLE_ADMIN (couvre aussi
     * ROLE_SUPER_ADMIN, stocké comme tel dans la colonne roles). Destinataires
     * des notifications internes.
     *
     * @return Utilisateur[]
     */
    public function findAgents(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.roles LIKE :admin OR u.roles LIKE :super')
            ->setParameter('admin', '%ROLE_ADMIN%')
            ->setParameter('super', '%ROLE_SUPER_ADMIN%')
            ->orderBy('u.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Liste paginée des utilisateurs « classiques » (hors agents JS Brokers). */
    public function paginateRegularUsers(int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder('u')
                ->where('u.roles NOT LIKE :admin AND u.roles NOT LIKE :super')
                ->setParameter('admin', '%ROLE_ADMIN%')
                ->setParameter('super', '%ROLE_SUPER_ADMIN%')
                ->orderBy('u.id', 'DESC'),
            $page,
            20,
        );
    }

    /** Nombre total d'utilisateurs « classiques » (hors agents). */
    public function countRegularUsers(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.roles NOT LIKE :admin AND u.roles NOT LIKE :super')
            ->setParameter('admin', '%ROLE_ADMIN%')
            ->setParameter('super', '%ROLE_SUPER_ADMIN%')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof Utilisateur) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    //    /**
    //     * @return Utilisateur[] Returns an array of Utilisateur objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('u.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Utilisateur
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
