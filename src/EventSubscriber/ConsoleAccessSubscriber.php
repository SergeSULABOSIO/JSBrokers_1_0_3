<?php

namespace App\EventSubscriber;

use App\Entity\Utilisateur;
use App\Service\Console\ConsoleAccessResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @file Restreint l'accès aux rubriques de la Console au périmètre du département.
 * @description Toute route nommée `console.*` est filtrée : un collaborateur ne peut
 * atteindre que les rubriques de son département (cf. ConsoleAccessResolver). Le
 * super-admin et la Direction Générale passent toujours. Complète (côté serveur) le
 * masquage de la navigation, pour que la restriction soit réelle et non cosmétique.
 */
class ConsoleAccessSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Security $security,
        private ConsoleAccessResolver $resolver,
    ) {
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = (string) $event->getRequest()->attributes->get('_route');
        if ($route === '' || !str_starts_with($route, 'console.')) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof Utilisateur) {
            return; // L'authentification / IsGranted des contrôleurs s'en charge.
        }

        if (!$this->resolver->canAccessRoute($user, $route)) {
            throw new AccessDeniedException(
                'Cette rubrique ne relève pas de votre département. Contactez un super-administrateur.'
            );
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController',
        ];
    }
}
