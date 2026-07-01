<?php

namespace App\EventSubscriber;

use App\Entity\Utilisateur;
use App\Service\Console\ConsoleAccessResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Environment;

/**
 * @file Restreint l'accès aux rubriques de la Console au périmètre du département.
 * @description Toute route nommée `console.*` est filtrée : un collaborateur ne peut
 * atteindre que les rubriques de son département (cf. ConsoleAccessResolver). Le
 * super-admin et la Direction Générale passent toujours. Complète (côté serveur) le
 * masquage de la navigation, pour que la restriction soit réelle et non cosmétique.
 *
 * En cas de refus, on ne lève pas une exception brute : on substitue le contrôleur
 * par une réponse 403 STYLISÉE (boîte de dialogue « Accès restreint ») qui explique
 * clairement la restriction au collaborateur et rappelle son périmètre d'accès.
 */
class ConsoleAccessSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Security $security,
        private ConsoleAccessResolver $resolver,
        private Environment $twig,
        private UrlGeneratorInterface $urlGenerator,
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
            // Boîte de dialogue stylisée (403) au lieu d'une page d'erreur brute.
            $html = $this->twig->render('console/access_denied.html.twig', [
                'departement'  => $user->getDepartement(),
                'dashboardUrl' => $this->urlGenerator->generate('console.dashboard'),
            ]);

            $event->setController(static fn (): Response => new Response($html, Response::HTTP_FORBIDDEN));
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController',
        ];
    }
}
