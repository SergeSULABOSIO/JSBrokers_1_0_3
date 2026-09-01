<?php

namespace App\Repository;

use App\Entity\Entreprise;
use App\Entity\JourFerie;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JourFerie>
 */
class JourFerieRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JourFerie::class);
    }

    /**
     * Jours fériés du cabinet couvrant la période, bornes incluses.
     *
     * UNE SEULE REQUÊTE PAR CALCUL : le calculateur reçoit la liste, il ne va jamais la
     * chercher jour par jour. Une demande de trois semaines ferait sinon vingt et un
     * allers-retours pour une information qui tient en une ligne.
     *
     * @return JourFerie[]
     */
    public function pourPeriode(
        Entreprise $entreprise,
        \DateTimeInterface $debut,
        \DateTimeInterface $fin,
    ): array {
        return $this->createQueryBuilder('j')
            ->andWhere('j.entreprise = :entreprise')
            ->andWhere('j.date >= :debut')
            ->andWhere('j.date <= :fin')
            ->setParameter('entreprise', $entreprise)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->orderBy('j.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Le cabinet a-t-il déjà déclaré ce jour férié ? Garde d'idempotence pour toute
     * reprise éventuelle.
     */
    public function existeA(Entreprise $entreprise, \DateTimeInterface $date): bool
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->andWhere('j.entreprise = :entreprise')
            ->andWhere('j.date = :date')
            ->setParameter('entreprise', $entreprise)
            ->setParameter('date', $date)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
