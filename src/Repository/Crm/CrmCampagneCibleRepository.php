<?php

namespace App\Repository\Crm;

use App\Entity\Crm\CrmCampagneCible;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CrmCampagneCible>
 */
class CrmCampagneCibleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CrmCampagneCible::class);
    }
}
