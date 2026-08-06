<?php

namespace App\Repository;

use App\Entity\AssistantConversation;
use App\Entity\AssistantProgramme;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssistantProgramme>
 */
class AssistantProgrammeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssistantProgramme::class);
    }

    /**
     * Le programme encore EN COURS de cette conversation, sinon null. Il ne peut
     * y en avoir qu'un : préparer un second programme tant que le premier n'est
     * pas terminé rejouerait, à l'échelle de la série, l'empilement de plans que
     * le verrou PlanEnAttente interdit déjà à l'échelle du plan.
     */
    public function courantDe(AssistantConversation $conversation): ?AssistantProgramme
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.conversation = :conversation')
            ->andWhere('p.statut = :statut')
            ->setParameter('conversation', $conversation)
            ->setParameter('statut', AssistantProgramme::STATUT_EN_COURS)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Programme de CETTE conversation par son id.
     *
     * FAIL-CLOSED PAR CONSTRUCTION (même règle que
     * AssistantMessageRepository::findDansConversation) : la conversation fait
     * partie du critère SQL, jamais d'un contrôle a posteriori.
     */
    public function findDansConversation(int $id, AssistantConversation $conversation): ?AssistantProgramme
    {
        return $this->findOneBy(['id' => $id, 'conversation' => $conversation]);
    }
}
