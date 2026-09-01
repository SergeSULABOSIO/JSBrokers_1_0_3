<?php

namespace App\Repository;

use App\Entity\Entreprise;
use App\Entity\PeriodeBlocage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PeriodeBlocage>
 */
class PeriodeBlocageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PeriodeBlocage::class);
    }

    /**
     * Les périodes ACTIVES du cabinet qui chevauchent l'intervalle donné.
     *
     * Le filtrage de chevauchement est fait en base : sur un cabinet qui accumule les
     * clôtures d'exercice année après année, tout charger pour n'en garder qu'une serait
     * payer la mémoire de dix ans d'historique à chaque saisie de congé.
     *
     * @return PeriodeBlocage[]
     */
    public function actifsChevauchant(
        Entreprise $entreprise,
        \DateTimeInterface $debut,
        \DateTimeInterface $fin,
    ): array {
        return $this->createQueryBuilder('b')
            ->andWhere('b.entreprise = :entreprise')
            ->andWhere('b.actif = true')
            ->andWhere('b.dateDebut <= :fin')
            ->andWhere('b.dateFin >= :debut')
            ->setParameter('entreprise', $entreprise)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->orderBy('b.dateDebut', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
