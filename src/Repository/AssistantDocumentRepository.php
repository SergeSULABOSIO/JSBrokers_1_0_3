<?php

namespace App\Repository;

use App\Entity\AssistantConversation;
use App\Entity\AssistantDocument;
use App\Entity\AssistantMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssistantDocument>
 */
class AssistantDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssistantDocument::class);
    }

    /**
     * Le document produit pour CE message, ou null.
     *
     * Sert l'anti-rejeu de l'endpoint de production : si la meta a été perdue mais
     * que le document existe, on ne le refabrique pas — et on ne le refacture pas.
     */
    public function pourMessage(AssistantMessage $message): ?AssistantDocument
    {
        return $this->findOneBy(['message' => $message]);
    }

    /**
     * Le document appartenant à CETTE conversation, ou null.
     *
     * Le filtre par conversation EST la preuve d'appartenance : la conversation
     * sort déjà de findOneDeLInvite(), donc l'id d'un autre invité ou d'une autre
     * entreprise ne peut pas être atteint par ce chemin.
     */
    public function dansConversation(int $id, AssistantConversation $conversation): ?AssistantDocument
    {
        return $this->findOneBy(['id' => $id, 'conversation' => $conversation]);
    }
}
