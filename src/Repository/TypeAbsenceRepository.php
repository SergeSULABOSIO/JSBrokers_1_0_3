<?php

namespace App\Repository;

use App\Entity\Entreprise;
use App\Entity\TypeAbsence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TypeAbsence>
 */
class TypeAbsenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TypeAbsence::class);
    }

    /**
     * Types ACTIFS du cabinet, dans l'ordre de saisie. Un type désactivé reste lisible
     * dans l'historique mais n'est plus proposé à la création d'une demande.
     *
     * @return TypeAbsence[]
     */
    public function actifsDe(Entreprise $entreprise): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.entreprise = :entreprise')
            ->andWhere('t.actif = true')
            ->setParameter('entreprise', $entreprise)
            ->orderBy('t.libelle', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Le type portant ce code dans ce cabinet — actif ou non. Utilisé par le
     * provisionnement, qui doit reconnaître un type DÉJÀ semé (y compris désactivé
     * depuis) pour ne pas le recréer.
     */
    public function parCode(Entreprise $entreprise, string $code): ?TypeAbsence
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.entreprise = :entreprise')
            ->andWhere('t.code = :code')
            ->setParameter('entreprise', $entreprise)
            ->setParameter('code', $code)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
