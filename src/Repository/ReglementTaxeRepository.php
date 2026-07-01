<?php

namespace App\Repository;

use App\Entity\ReglementTaxe;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReglementTaxe>
 */
class ReglementTaxeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReglementTaxe::class);
    }

    /**
     * Reversements d'un exercice (année de période), plus récents d'abord.
     *
     * @return ReglementTaxe[]
     */
    public function findAnnee(int $annee): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.annee = :annee')->setParameter('annee', $annee)
            ->orderBy('r.mois', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Tous les reversements ordonnés chronologiquement (date de paiement) —
     * alimente la génération des écritures comptables.
     *
     * @return ReglementTaxe[]
     */
    public function findChronologique(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.datePaiement', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Total reversé par mois pour un exercice : [mois(1-12) => montant].
     *
     * @return array<int, float>
     */
    public function totalParMoisAnnee(int $annee): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('r.mois AS mois, COALESCE(SUM(r.montant), 0) AS total')
            ->where('r.annee = :annee')->setParameter('annee', $annee)
            ->groupBy('r.mois')
            ->getQuery()
            ->getArrayResult();

        $parMois = [];
        foreach ($rows as $row) {
            $parMois[(int) $row['mois']] = (float) $row['total'];
        }

        return $parMois;
    }

    /**
     * Somme des photos de TVA (collectée/déductible) déjà figées sur les
     * reversements d'une période — pour ne déclarer, au reversement suivant, que
     * le RESTE à déclarer (évite le double comptage en cas de paiements multiples).
     *
     * @return array{collectee: float, deductible: float}
     */
    public function sommeSnapshotsPeriode(int $annee, int $mois): array
    {
        $row = $this->createQueryBuilder('r')
            ->select('COALESCE(SUM(r.tvaCollectee), 0) AS collectee, COALESCE(SUM(r.tvaDeductible), 0) AS deductible')
            ->where('r.annee = :a AND r.mois = :m')
            ->setParameter('a', $annee)->setParameter('m', $mois)
            ->getQuery()
            ->getSingleResult();

        return ['collectee' => (float) $row['collectee'], 'deductible' => (float) $row['deductible']];
    }

    /** Années civiles présentes dans les reversements (pour le sélecteur d'exercice). */
    public function anneesDisponibles(): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('DISTINCT r.annee AS annee')
            ->orderBy('r.annee', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r) => (int) $r['annee'], $rows);
    }
}
