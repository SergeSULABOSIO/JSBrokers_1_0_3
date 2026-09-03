<?php

namespace App\Repository;

use App\Entity\EchangeImportRun;
use App\Entity\Entreprise;
use App\Entity\Invite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EchangeImportRun>
 *
 * @method EchangeImportRun|null find($id, $lockMode = null, $lockVersion = null)
 * @method EchangeImportRun|null findOneBy(array $criteria, array $orderBy = null)
 * @method EchangeImportRun[]    findAll()
 * @method EchangeImportRun[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EchangeImportRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EchangeImportRun::class);
    }

    /**
     * Le contrôle en attente de décision de CET invité, s'il y en a un.
     *
     * Scopé à l'invité et non au seul cabinet : le rapport porte le détail d'un fichier
     * qu'un collègue a déposé, avec ses erreurs et ses données. Deux personnes peuvent
     * préparer un import en parallèle sans se voir l'une l'autre.
     */
    public function enAttentePour(Entreprise $entreprise, Invite $invite): ?EchangeImportRun
    {
        $runs = $this->createQueryBuilder('r')
            ->andWhere('r.entreprise = :entreprise')
            ->andWhere('r.invite = :invite')
            ->andWhere('r.statut = :statut')
            ->andWhere('r.expireLe > :maintenant')
            ->setParameter('entreprise', $entreprise)
            ->setParameter('invite', $invite)
            ->setParameter('statut', EchangeImportRun::STATUT_EN_ATTENTE_CONFIRMATION)
            ->setParameter('maintenant', new \DateTimeImmutable('now'))
            ->orderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return $runs[0] ?? null;
    }

    /**
     * Contrôles périmés, à purger avec les fichiers qu'ils désignent.
     *
     * @return EchangeImportRun[]
     */
    public function expires(?\DateTimeImmutable $maintenant = null): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.expireLe <= :maintenant')
            ->andWhere('r.statut NOT IN (:definitifs)')
            ->setParameter('maintenant', $maintenant ?? new \DateTimeImmutable('now'))
            ->setParameter('definitifs', [EchangeImportRun::STATUT_TERMINE])
            ->getQuery()
            ->getResult();
    }
}
