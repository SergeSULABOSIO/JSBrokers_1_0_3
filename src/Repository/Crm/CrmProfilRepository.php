<?php

namespace App\Repository\Crm;

use App\Entity\Crm\CrmProfil;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CrmProfil>
 */
class CrmProfilRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CrmProfil::class);
    }

    public function findForUser(Utilisateur $user): ?CrmProfil
    {
        return $this->find($user);
    }

    /**
     * Profils existants pour un lot d'utilisateurs, indexés par id d'utilisateur.
     * Évite le N+1 sur les listes (clients, pipeline).
     *
     * @param int[] $userIds
     *
     * @return array<int, CrmProfil>
     */
    public function mapByUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('p')
            ->join('p.utilisateur', 'u')->addSelect('u')
            ->where('u.id IN (:ids)')
            ->setParameter('ids', $userIds)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $profil) {
            /** @var CrmProfil $profil */
            $map[$profil->getUtilisateur()->getId()] = $profil;
        }

        return $map;
    }

    /**
     * Répartition des profils par étape du pipeline.
     *
     * @return array<string, int> [etape => count]
     */
    public function countByStage(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.etapePipeline AS etape, COUNT(p.utilisateur) AS nb')
            ->groupBy('p.etapePipeline')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['etape']] = (int) $r['nb'];
        }

        return $map;
    }

    /**
     * Clients à relancer : action planifiée échue OU dernier contact trop ancien.
     *
     * @return CrmProfil[]
     */
    public function findARelancer(int $limit = 20): array
    {
        $now = new \DateTimeImmutable();

        return $this->createQueryBuilder('p')
            ->join('p.utilisateur', 'u')->addSelect('u')
            ->where('p.prochaineActionAt IS NOT NULL AND p.prochaineActionAt <= :now')
            ->orWhere('p.dernierContactAt IS NOT NULL AND p.dernierContactAt <= :seuil')
            ->setParameter('now', $now)
            ->setParameter('seuil', $now->modify('-14 days'))
            ->orderBy('p.prochaineActionAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Profils dans une ou plusieurs étapes de pipeline (ex. prospects « chauds »).
     *
     * @param string[] $stages
     *
     * @return CrmProfil[]
     */
    public function findByStages(array $stages, int $limit = 20): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.utilisateur', 'u')->addSelect('u')
            ->where('p.etapePipeline IN (:stages)')
            ->setParameter('stages', $stages)
            ->orderBy('p.scoreSante', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Profils à risque (churn ou santé dégradée), les plus critiques d'abord.
     *
     * @return CrmProfil[]
     */
    public function findARisque(int $limit = 20): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.utilisateur', 'u')->addSelect('u')
            ->where('p.risqueChurn = true')
            ->orderBy('p.scoreSante', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Profils correspondant à un segment marketing : étapes de pipeline et/ou
     * couleurs de santé. Un critère vide n'applique aucun filtre sur cet axe.
     *
     * @param string[] $stages
     * @param string[] $couleurs
     *
     * @return CrmProfil[]
     */
    public function findBySegment(array $stages, array $couleurs, int $limit = 1000): array
    {
        $qb = $this->createQueryBuilder('p')
            ->join('p.utilisateur', 'u')->addSelect('u')
            ->setMaxResults($limit);

        if ($stages !== []) {
            $qb->andWhere('p.etapePipeline IN (:stages)')->setParameter('stages', $stages);
        }
        if ($couleurs !== []) {
            $qb->andWhere('p.scoreCouleur IN (:couleurs)')->setParameter('couleurs', $couleurs);
        }

        return $qb->getQuery()->getResult();
    }

    /** Score de santé moyen du portefeuille (0 si aucun profil). */
    public function averageScore(): float
    {
        return (float) $this->createQueryBuilder('p')
            ->select('COALESCE(AVG(p.scoreSante), 0)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Nombre total de profils CRM. */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.utilisateur)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Répartition des profils par couleur de santé.
     *
     * @return array<string, int> [couleur => count]
     */
    public function countByHealthColor(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.scoreCouleur AS couleur, COUNT(p.utilisateur) AS nb')
            ->groupBy('p.scoreCouleur')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['couleur']] = (int) $r['nb'];
        }

        return $map;
    }
}
