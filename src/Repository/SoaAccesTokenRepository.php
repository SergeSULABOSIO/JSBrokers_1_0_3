<?php

namespace App\Repository;

use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\SoaAccesToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SoaAccesToken>
 */
class SoaAccesTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SoaAccesToken::class);
    }

    /**
     * Jeton encore actif (non révoqué, non expiré) pour ce client dans cette entreprise.
     * Le plus récent gagne s'il en existait plusieurs.
     */
    public function findActifPourClient(Client $client, Entreprise $entreprise): ?SoaAccesToken
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.client = :client')
            ->andWhere('t.entreprise = :entreprise')
            ->andWhere('t.revokedAt IS NULL')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('client', $client)
            ->setParameter('entreprise', $entreprise)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('t.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Recherche par jeton brut, sans condition de validité : l'appelant évalue
     * isActif() lui-même afin de servir une réponse d'échec strictement uniforme.
     */
    public function findOneByToken(string $token): ?SoaAccesToken
    {
        return $this->findOneBy(['token' => $token]);
    }
}
