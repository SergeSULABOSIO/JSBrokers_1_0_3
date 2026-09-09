<?php

namespace App\EventSubscriber;

use App\Service\Terminal\DetecteurDeTerminal;
use App\Service\Terminal\Terminal;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @file `?terminal=…` dans l'URL devient un choix DURABLE.
 * @description Sans ce souscripteur, le paramètre ne vaudrait que pour la page
 * où il est écrit : le premier lien interne cliqué ramènerait la détection
 * automatique, et la bascule « Afficher la version ordinateur » serait un
 * bouton qui ne tient pas.
 *
 * Le cookie est posé sur la RÉPONSE de la requête qui porte le paramètre, donc
 * cette page-là est déjà rendue dans le bon mode (le détecteur lit le paramètre
 * en premier) : il n'y a jamais de rechargement, ni de page servie « à côté ».
 *
 * `httpOnly: false` est délibéré : la sonde du navigateur écrit ce même cookie
 * en JavaScript, et deux cookies de même nom dont l'un serait inaccessible au
 * front donneraient deux vérités. Il ne porte aucun secret — seulement le nom
 * d'une mise en page.
 */
final class TerminalCookieSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'persisterLeChoix'];
    }

    public function persisterLeChoix(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $requete = $event->getRequest();
        $choisi = Terminal::depuisValeur($requete->query->get(DetecteurDeTerminal::PARAM));
        if ($choisi === null) {
            return;
        }

        // Déjà mémorisé à cette valeur : ne pas réécrire un en-tête pour rien
        // (une réponse mise en cache par un intermédiaire n'a pas à porter un
        // Set-Cookie qu'elle n'apporte pas).
        if (Terminal::depuisValeur($requete->cookies->get(DetecteurDeTerminal::COOKIE)) === $choisi) {
            return;
        }

        $event->getResponse()->headers->setCookie(
            Cookie::create(DetecteurDeTerminal::COOKIE)
                ->withValue($choisi->value)
                ->withPath('/')
                ->withExpires(time() + DetecteurDeTerminal::DUREE_COOKIE)
                ->withHttpOnly(false)
                ->withSameSite(Cookie::SAMESITE_LAX),
        );
    }
}
