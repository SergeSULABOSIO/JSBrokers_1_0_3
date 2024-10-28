<?php

namespace App\EventListener;

use App\Entity\Utilisateur;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Translation\LocaleSwitcher;

final class UserLocaleListener
{

    public function __construct(
        private readonly Security $security,
        private readonly LocaleSwitcher $localeSwitcher
    )
    {
        
    }

    #[AsEventListener(event: KernelEvents::REQUEST)]
    public function onKernelRequest(RequestEvent $event): void
    {
        $user = $this->security->getUser();
        if ($user && $user instanceof Utilisateur) {
            $this->localeSwitcher->setLocale($user->getLocale());
        }else{
            $this->localeSwitcher->setLocale('fr');
        }
    }
}
