<?php

namespace App\EventSubscriber;

use App\Entity\Utilisateur;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Confine globalement tout utilisateur authentifié mais NON vérifié au parcours
 * de re-vérification d'e-mail.
 *
 * Pourquoi : la vérification de l'e-mail n'est pas un rôle de sécurité ; sans ce
 * garde-fou, un utilisateur non vérifié pouvait atteindre n'importe quelle page
 * `ROLE_USER` via un lien direct (target path après login, remember-me, marque-page…).
 * Ce subscriber fait de la re-vérification la SEULE issue tant que l'e-mail n'est
 * pas validé, ce qui élimine la divergence de comportement entre les différents
 * contrôleurs (authenticator, page de login, contrôleur entreprise).
 *
 * Les routes strictement nécessaires à ce parcours (et les routes publiques)
 * restent accessibles via la liste blanche ci-dessous, afin d'éviter toute
 * boucle de redirection.
 */
class EmailVerificationSubscriber implements EventSubscriberInterface
{
    /**
     * Routes accessibles à un utilisateur non vérifié.
     * `app_reverify_email` (cible de la redirection) et `app_verify_email`
     * (lien de validation reçu par e-mail) sont indispensables au parcours.
     */
    private const ALLOWED_ROUTES = [
        'app_login',
        'app_logout',
        'app_reverify_email',
        'app_verify_email',
        'app_translate',
        'app_index',
        'app_register',
    ];

    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            // Priorité 7 : après le firewall (qui résout l'utilisateur) mais avant le contrôleur.
            KernelEvents::REQUEST => ['onKernelRequest', 7],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();

        // Pas d'utilisateur connecté, ou utilisateur déjà vérifié : rien à faire.
        if (!$user instanceof Utilisateur || $user->isVerified()) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');

        // On laisse passer les routes du parcours de vérification, les routes publiques,
        // et les routes internes (profiler, wdt… préfixées par « _ ») ou non nommées (assets).
        if ($route === null || str_starts_with($route, '_') || in_array($route, self::ALLOWED_ROUTES, true)) {
            return;
        }

        // Un seul message d'information, posé une fois, à la redirection.
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session !== null) {
            $session->getFlashBag()->add('warning', $this->translator->trans('entreprise_your_email_is_not_verified', [
                ':user'  => $user->getNom(),
                ':email' => $user->getEmail(),
            ]));
        }

        $event->setResponse(
            new RedirectResponse($this->urlGenerator->generate('app_reverify_email'))
        );
    }
}
