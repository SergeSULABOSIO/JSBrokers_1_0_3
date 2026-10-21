<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * LA DÉCONNEXION VIDE LE TRAVAIL LAISSÉ DANS LE NAVIGATEUR (chantier 15).
 *
 * Onglets, filtres, panneau de Ket : l'espace de travail les garde dans le stockage du
 * navigateur. Sur un poste partagé, ils ne doivent pas survivre à la déconnexion. Décision
 * de Serge : vidage CIBLÉ en JavaScript plutôt que l'en-tête `Clear-Site-Data`, qui
 * détruirait aussi les préférences d'affichage (et n'agit qu'en HTTPS).
 *
 * Le serveur ne peut pas toucher au stockage du navigateur : il le DIT à la page suivante.
 * La connexion s'ouvre avec `?deconnexion=1`, base.html.twig le traduit en
 * `data-jsb-deconnexion`, et assets/stockage-de-travail.js efface.
 *
 * Priorité 0 : après le `DefaultLogoutListener` (64), dont on remplace la redirection.
 */
#[AsEventListener(event: LogoutEvent::class)]
class DeconnexionListener
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function __invoke(LogoutEvent $event): void
    {
        $event->setResponse(new RedirectResponse($this->urls->generate('app_login', ['deconnexion' => 1])));
    }
}
