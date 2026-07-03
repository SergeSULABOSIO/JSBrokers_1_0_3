<?php

namespace App\Repository;

use App\Entity\Depense;
use App\Entity\DepenseCourtier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Knp\Component\Pager\PaginatorInterface;

/**
 * @extends ServiceEntityRepository<DepenseCourtier>
 *
 * @method DepenseCourtier|null find($id, $lockMode = null, $lockVersion = null)
 * @method DepenseCourtier|null findOneBy(array $criteria, array $orderBy = null)
 * @method DepenseCourtier[]    findAll()
 * @method DepenseCourtier[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DepenseCourtierRepository extends ServiceEntityRepository
{
    public function __construct(
        private ManagerRegistry $registry,
        private PaginatorInterface $paginator,
    ) {
        parent::__construct($registry, DepenseCourtier::class);
    }

    public function save(DepenseCourtier $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(DepenseCourtier $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function paginateForEntreprise(int $idEntreprise, int $page): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder('d')
                ->where('d.entreprise = :entrepriseId')
                ->setParameter('entrepriseId', $idEntreprise)
                ->orderBy('d.id', 'DESC'),
            $page,
            20,
        );
    }

    /**
     * Dépenses NON ANNULÉES d'une entreprise, chronologiques — source du moteur
     * d'écritures comptables du courtier (CourtierEcritureComptableService).
     *
     * @return DepenseCourtier[]
     */
    public function findChronologiqueForEntreprise(int $idEntreprise): array
    {
        return $this->createQueryBuilder('d')
            ->join('d.charge', 'c')->addSelect('c')
            ->where('d.entreprise = :entrepriseId')
            ->andWhere('d.statut != :annulee')
            ->setParameter('entrepriseId', $idEntreprise)
            ->setParameter('annulee', Depense::STATUT_ANNULEE)
            ->orderBy('d.dateDepense', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
