<?php

namespace App\Repository;

use App\Entity\Contact;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<Contact>
 */
class ContactRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private PaginatorInterface $paginator,
        private Security $security
    )
    {
        parent::__construct($registry, Contact::class);
    }

    //    /**
    //     * @return Contact[] Returns an array of Contact objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('c')
    //            ->andWhere('c.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('c.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Contact
    //    {
    //        return $this->createQueryBuilder('c')
    //            ->andWhere('c.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }

    public function paginateForEntreprise(int $idEntreprise, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder("contact")
                //via le client
                ->leftJoin("contact.client", "client")
                //via la notification sinistre, invite
                ->leftJoin("contact.notificationSinistre", "notificationsinistre")
                ->leftJoin("notificationsinistre.invite", "invite")
                //via la notification sinistre, client/assuré
                ->leftJoin("notificationsinistre.assure", "assure")
                //condition
                ->where('client.entreprise = :entrepriseId')
                ->orwhere('invite.entreprise = :entrepriseId')
                ->orwhere('assure.entreprise = :entrepriseId')
                //paramètres
                ->setParameter('entrepriseId', '' . $idEntreprise . '')
                //ordre de données
                ->orderBy('contact.id', 'DESC'),
            $page,
            20,
        );
    }
}
