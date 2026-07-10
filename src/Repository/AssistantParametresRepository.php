<?php

namespace App\Repository;

use App\Entity\AssistantParametres;
use App\Entity\Entreprise;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssistantParametres>
 */
class AssistantParametresRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssistantParametres::class);
    }

    public function findOneByEntreprise(Entreprise $entreprise): ?AssistantParametres
    {
        return $this->findOneBy(['entreprise' => $entreprise]);
    }

    /**
     * Nom du personnage de l'entreprise, avec repli sur le nom par défaut tant
     * que l'entreprise n'a pas encore baptisé son assistant.
     */
    public function nomPour(Entreprise $entreprise): string
    {
        return $this->findOneByEntreprise($entreprise)?->getNom() ?? AssistantParametres::NOM_PAR_DEFAUT;
    }
}
