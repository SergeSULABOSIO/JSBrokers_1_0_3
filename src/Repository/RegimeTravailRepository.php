<?php

namespace App\Repository;

use App\Entity\Invite;
use App\Entity\RegimeTravail;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RegimeTravail>
 */
class RegimeTravailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RegimeTravail::class);
    }

    /**
     * Le régime en vigueur pour cet agent à cette date, ou null s'il n'en a aucun.
     *
     * Les régimes sont HISTORISÉS et peuvent se chevaucher si la saisie a été
     * approximative : on retient le PLUS RÉCENT qui couvre la date, jamais « le premier
     * trouvé ». Un ordre de lecture non déterminé donnerait deux décomptes différents
     * pour la même demande selon l'humeur du moteur.
     */
    public function applicableA(Invite $agent, \DateTimeInterface $date): ?RegimeTravail
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.agent = :agent')
            ->andWhere('r.dateDebut <= :date')
            ->andWhere('r.dateFin IS NULL OR r.dateFin >= :date')
            ->setParameter('agent', $agent)
            ->setParameter('date', $date)
            ->orderBy('r.dateDebut', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
