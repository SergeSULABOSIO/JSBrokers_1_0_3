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

    /**
     * Requête de base sur les comptes « classiques » (hors agents JS Brokers).
     * Sert d'assise commune au partitionnement Clients (payants) / Utilisateurs
     * (gratuits) afin de ne pas dupliquer le filtre rôles.
     */
    private function regularUsersQb(): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('u')
            ->where('u.roles NOT LIKE :admin AND u.roles NOT LIKE :super')
            ->setParameter('admin', '%ROLE_ADMIN%')
            ->setParameter('super', '%ROLE_SUPER_ADMIN%');
    }

    /**
     * Liste paginée des utilisateurs « gratuits » : comptes classiques sans solde
     * prépayé (plan basic), par opposition aux clients (cf. paginateClients()).
     */
    public function paginateRegularUsers(int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->regularUsersQb()
                ->andWhere('u.paidTokens = 0')
                ->orderBy('u.id', 'DESC'),
            $page,
            20,
        );
    }

    /** Nombre d'utilisateurs « gratuits » (comptes classiques, plan basic, sans solde prépayé). */
    public function countRegularUsers(): int
    {
        return (int) $this->regularUsersQb()
            ->andWhere('u.paidTokens = 0')
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Liste paginée des « clients » : tout compte en mode payant, c.-à-d. disposant
     * encore d'un solde de jetons prépayés (paidTokens > 0). Le rôle n'entre PAS en
     * ligne de compte : un agent JS Brokers qui a acheté des jetons et en possède
     * encore est aussi un client.
     */
    public function paginateClients(int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder('u')
                ->where('u.paidTokens > 0')
                ->orderBy('u.id', 'DESC'),
            $page,
            20,
        );
    }

    /** Nombre de « clients » (tout compte avec solde prépayé > 0, rôle indifférent). */
    public function countClients(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.paidTokens > 0')
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
