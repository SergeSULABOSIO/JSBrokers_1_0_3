<?php

namespace App\Repository\Crm;

use App\Entity\Crm\CrmTicketFeedback;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CrmTicketFeedback>
 */
class CrmTicketFeedbackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CrmTicketFeedback::class);
    }
}
