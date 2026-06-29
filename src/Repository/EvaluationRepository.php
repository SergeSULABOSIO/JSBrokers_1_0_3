<?php

namespace App\Repository;

use App\Entity\Evaluation;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Evaluation>
 */
class EvaluationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Evaluation::class);
    }

    /** Fiche d'un collaborateur pour une période, ou null si jamais ouverte. */
    public function findOnePeriode(Utilisateur $collaborateur, int $annee, int $trimestre): ?Evaluation
    {
        return $this->findOneBy([
            'collaborateur' => $collaborateur,
            'annee'         => $annee,
            'trimestre'     => $trimestre,
        ]);
    }
}
