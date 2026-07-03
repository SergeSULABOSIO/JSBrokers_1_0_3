<?php

namespace App\Repository;

use App\Entity\ChargeCourtier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Knp\Component\Pager\PaginatorInterface;

/**
 * @extends ServiceEntityRepository<ChargeCourtier>
 *
 * @method ChargeCourtier|null find($id, $lockMode = null, $lockVersion = null)
 * @method ChargeCourtier|null findOneBy(array $criteria, array $orderBy = null)
 * @method ChargeCourtier[]    findAll()
 * @method ChargeCourtier[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ChargeCourtierRepository extends ServiceEntityRepository
{
    public function __construct(
        private ManagerRegistry $registry,
        private PaginatorInterface $paginator,
    ) {
        parent::__construct($registry, ChargeCourtier::class);
    }

    public function save(ChargeCourtier $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ChargeCourtier $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function paginateForEntreprise(int $idEntreprise, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder('c')
                ->where('c.entreprise = :entrepriseId')
                ->setParameter('entrepriseId', $idEntreprise)
                ->orderBy('c.id', 'DESC'),
            $page,
            20,
        );
    }
}
