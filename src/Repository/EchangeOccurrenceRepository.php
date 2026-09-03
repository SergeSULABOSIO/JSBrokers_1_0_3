<?php

namespace App\Repository;

use App\Entity\EchangeOccurrence;
use App\Entity\Entreprise;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EchangeOccurrence>
 *
 * @method EchangeOccurrence|null find($id, $lockMode = null, $lockVersion = null)
 * @method EchangeOccurrence|null findOneBy(array $criteria, array $orderBy = null)
 * @method EchangeOccurrence[]    findAll()
 * @method EchangeOccurrence[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EchangeOccurrenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EchangeOccurrence::class);
    }

    /**
     * Nombre d'occurrences ABOUTIES du cabinet, tous types confondus — export et import
     * se partagent le même quota, comme le veut la règle « une occurrence = une
     * exportation OU une importation ».
     */
    public function compterPour(Entreprise $entreprise): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.entreprise = :entreprise')
            ->setParameter('entreprise', $entreprise)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Historique du cabinet, du plus récent au plus ancien.
     *
     * @return EchangeOccurrence[]
     */
    public function historiquePour(Entreprise $entreprise, int $limite = 100): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.entreprise = :entreprise')
            ->setParameter('entreprise', $entreprise)
            ->orderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * L'occurrence déjà enregistrée sous cette clé, s'il y en a une.
     *
     * Le garde-fou RÉEL contre le double débit est la contrainte d'unicité en base ;
     * cette lecture ne sert qu'à répondre proprement au rejeu plutôt qu'à laisser
     * remonter une violation de contrainte.
     */
    public function parCleIdempotence(string $cle): ?EchangeOccurrence
    {
        return $this->findOneBy(['cleIdempotence' => $cle]);
    }
}
