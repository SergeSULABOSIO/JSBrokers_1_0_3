<?php

namespace App\Repository;

use App\Entity\Objectif;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Objectif>
 */
class ObjectifRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Objectif::class);
    }

    /**
     * Objectifs d'un collaborateur pour une période (année + trimestre).
     *
     * @return Objectif[]
     */
    public function findForPeriode(Utilisateur $collaborateur, int $annee, int $trimestre): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.collaborateur = :c')->setParameter('c', $collaborateur)
            ->andWhere('o.annee = :a')->setParameter('a', $annee)
            ->andWhere('o.trimestre = :t')->setParameter('t', $trimestre)
            ->orderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Périodes (année + trimestre) renseignées pour un collaborateur, plus
     * récentes d'abord — alimente l'historique de la fiche d'évaluation.
     *
     * @return array<int, array{annee: int, trimestre: int}>
     */
    public function findPeriodes(Utilisateur $collaborateur): array
    {
        /** @var array<int, array{annee: int, trimestre: int}> $rows */
        $rows = $this->createQueryBuilder('o')
            ->select('DISTINCT o.annee AS annee, o.trimestre AS trimestre')
            ->andWhere('o.collaborateur = :c')->setParameter('c', $collaborateur)
            ->orderBy('o.annee', 'DESC')
            ->addOrderBy('o.trimestre', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $r): array => ['annee' => (int) $r['annee'], 'trimestre' => (int) $r['trimestre']],
            $rows,
        );
    }
}
