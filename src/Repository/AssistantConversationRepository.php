<?php

namespace App\Repository;

use App\Entity\AssistantConversation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssistantConversation>
 */
class AssistantConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssistantConversation::class);
    }

    /**
     * Conversations de CET invité dans CETTE entreprise, les plus récentes
     * d'abord. Le double critère matérialise l'isolation invité + entreprise.
     * @return AssistantConversation[]
     */
    public function findPourInvite(Invite $invite, Entreprise $entreprise): array
    {
        return $this->findBy(
            ['invite' => $invite, 'entreprise' => $entreprise],
            ['updatedAt' => 'DESC', 'id' => 'DESC'],
        );
    }

    /**
     * Charge une conversation uniquement si elle appartient à cet invité dans
     * cette entreprise (fail-closed : hors propriété → null → 404).
     */
    public function findOneDeLInvite(int $id, Invite $invite, Entreprise $entreprise): ?AssistantConversation
    {
        return $this->findOneBy(['id' => $id, 'invite' => $invite, 'entreprise' => $entreprise]);
    }
}
