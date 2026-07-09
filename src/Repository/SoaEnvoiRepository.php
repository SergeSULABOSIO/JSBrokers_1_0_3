<?php

namespace App\Repository;

use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\SoaEnvoi;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SoaEnvoi>
 */
class SoaEnvoiRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SoaEnvoi::class);
    }

    /**
     * Derniers envois du SOA de ce client dans cette entreprise, du plus récent
     * au plus ancien (affichés dans la boîte d'envoi comme contexte).
     * @return SoaEnvoi[]
     */
    public function findDerniersPourClient(Client $client, Entreprise $entreprise, int $limit = 5): array
    {
        return $this->findBy(
            ['client' => $client, 'entreprise' => $entreprise],
            ['id' => 'DESC'],
            $limit,
        );
    }
}
