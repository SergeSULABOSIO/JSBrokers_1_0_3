<?php

namespace App\Repository;

use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssistantMessage>
 */
class AssistantMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssistantMessage::class);
    }

    /**
     * Message appartenant à CETTE conversation, sinon null.
     *
     * FAIL-CLOSED PAR CONSTRUCTION : la conversation fait partie du critère SQL.
     * Ne jamais remplacer par un find() suivi d'une comparaison a posteriori —
     * c'est précisément l'oubli qui ouvrirait la citation d'un message d'un
     * autre invité.
     */
    public function findDansConversation(int $id, AssistantConversation $conversation): ?AssistantMessage
    {
        return $this->findOneBy(['id' => $id, 'conversation' => $conversation]);
    }
}
