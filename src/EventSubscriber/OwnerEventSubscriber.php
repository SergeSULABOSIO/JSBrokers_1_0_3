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
        if (!$user || !method_exists($entity, 'setInvite')) {
            return;
        }
        // L'AUTEUR DÉJÀ POSÉ (formulaire, Ket : le demandeur) NE SE RÉÉCRIT JAMAIS — la
        // frontière des tâches le lit. Avant, une invitation en attente au même e-mail, dans
        // n'importe quel cabinet, l'écrasait ; deux invitations levaient une exception.
        if (method_exists($entity, 'getInvite') && $entity->getInvite() !== null) {
            return;
        }
        $cabinet = method_exists($entity, 'getEntreprise') ? $entity->getEntreprise() : null;
        foreach ($this->inviteRepository->findBy(['email' => $user->getUserIdentifier()]) as $invite) {
            // Le cabinet de la fiche, et lui seul : jamais un auteur venu d'ailleurs.
            if ($cabinet !== null && $invite->getEntreprise() === $cabinet) {
                $entity->setInvite($invite);

                return;
            }
        }
        // On ne touche pas à 'setExecutor'.
    }
}
