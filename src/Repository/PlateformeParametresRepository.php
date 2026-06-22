<?php

namespace App\Repository;

use App\Entity\PlateformeParametres;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlateformeParametres>
 */
class PlateformeParametresRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlateformeParametres::class);
    }

    /**
     * Retourne la ligne unique des paramètres, en la créant à la volée si elle
     * n'existe pas encore. Les champs restent nuls (= repli sur les constantes
     * TokenPricing) tant qu'aucune valeur n'a été personnalisée.
     */
    public function getSingleton(): PlateformeParametres
    {
        $params = $this->createQueryBuilder('p')
            ->orderBy('p.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($params === null) {
            $params = new PlateformeParametres();
            $em = $this->getEntityManager();
            $em->persist($params);
            $em->flush();
        }

        return $params;
    }
}
