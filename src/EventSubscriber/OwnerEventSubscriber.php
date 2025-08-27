<?php

namespace App\EventSubscriber;

use App\Entity\Invite;
use App\Entity\OwnerAwareInterface;
use App\Repository\InviteRepository;
use Doctrine\Bundle\DoctrineBundle\EventSubscriber\EventSubscriberInterface;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Symfony\Bundle\SecurityBundle\Security;

class OwnerEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Security $security,
        private InviteRepository $inviteRepository
    ) {}

    public function getSubscribedEvents(): array
    {
        return [
            Events::prePersist,
        ];
    }

    public function prePersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        $user = $this->security->getUser();
        if (!$user) {
            return;
        }
        $invite = $this->inviteRepository->findOneByEmail($user->getUserIdentifier());
        if ($invite) {
            // CORRECTION : On ne vérifie et n'assigne QUE la propriété 'invite'
            if (method_exists($entity, 'setInvite')) {
                $entity->setInvite($invite);
            }
            // On ne touche plus à 'setExecutor'
        }
    }
}
