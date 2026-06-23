<?php

namespace App\Repository;

use App\Entity\TokenConsumption;
use App\Entity\Utilisateur;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<TokenConsumption>
 */
class TokenConsumptionRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private PaginatorInterface $paginator,
    ) {
        parent::__construct($registry, TokenConsumption::class);
    }

    /**
     * Historique de consommation d'un propriétaire (payeur), du plus récent au
     * plus ancien, paginé.
     */
    public function paginateForProprietaire(int $idProprietaire, int $page, int $perPage = 20): PaginationInterface
    {
        return $this->paginator->paginate(
            $this->createQueryBuilder('c')
                ->where('c.proprietaire = :uid')
                ->setParameter('uid', $idProprietaire)
                ->orderBy('c.createdAt', 'DESC')
                ->addOrderBy('c.id', 'DESC'),
            $page,
            $perPage,
        );
    }

    /**
     * Total de tokens consommés par entreprise, pour un lot d'identifiants.
     * Agrégat groupé en une seule requête (évite le N+1 sur une liste paginée).
     *
     * @param int[] $entrepriseIds
     *
     * @return array<int,int> [entrepriseId => totalPoids]
     */
    public function totauxParEntreprises(array $entrepriseIds): array
    {
        if ($entrepriseIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.entreprise) AS eid, COALESCE(SUM(c.poidsTotal), 0) AS total')
            ->where('c.entreprise IN (:ids)')
            ->setParameter('ids', $entrepriseIds)
            ->groupBy('c.entreprise')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['eid']] = (int) $r['total'];
        }

        return $map;
    }

    /**
     * Total de tokens consommés par propriétaire (payeur), pour un lot d'IDs.
     * Agrégat groupé en une seule requête (évite le N+1 sur une liste paginée).
     *
     * @param int[] $proprietaireIds
     *
     * @return array<int,int> [proprietaireId => totalPoids]
     */
    public function totauxParProprietaires(array $proprietaireIds): array
    {
        if ($proprietaireIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.proprietaire) AS uid, COALESCE(SUM(c.poidsTotal), 0) AS total')
            ->where('c.proprietaire IN (:ids)')
            ->setParameter('ids', $proprietaireIds)
            ->groupBy('c.proprietaire')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['uid']] = (int) $r['total'];
        }

        return $map;
    }

    /** Total de tokens consommés par un propriétaire (tous sens confondus). */
    public function totalConsommeForProprietaire(int $idProprietaire): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COALESCE(SUM(c.poidsTotal), 0)')
            ->where('c.proprietaire = :uid')
            ->setParameter('uid', $idProprietaire)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
