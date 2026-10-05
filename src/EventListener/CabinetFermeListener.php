<?php

namespace App\EventListener;

use App\Service\Workspace\AucunCabinetOuvertException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * QUAND LE CABINET SE FERME SOUS LES PIEDS DE L'UTILISATEUR.
 *
 * Le gardien {@see \App\Service\Workspace\CabinetActif} ferme le cabinet dès que
 * l'`Invite` qui l'autorisait disparaît. L'utilisateur, lui, est au milieu d'un écran :
 * il faut le ramener au choix d'espace, pas lui opposer une erreur.
 *
 * ── DEUX SURFACES, DEUX RÉPONSES ────────────────────────────────────────────────
 * L'espace de travail parle en JSON autant qu'en HTML. Rediriger une requête XHR la
 * ferait suivre la redirection et injecter une PAGE ENTIÈRE dans un panneau — un
 * symptôme illisible pour une cause simple. On répond donc :
 *
 *   - 409 + `{ "redirect": … }` à ce qui attend du JSON, à charge pour le client de
 *     naviguer ;
 *   - 302 vers le choix d'espace au reste.
 *
 * Le 409 (Conflict) plutôt qu'un 403 : rien n'est interdit, c'est l'état du compte qui
 * a changé pendant la requête.
 */
#[AsEventListener(event: ExceptionEvent::class)]
class CabinetFermeListener
{
    public function __construct(private UrlGeneratorInterface $urlGenerator)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof AucunCabinetOuvertException) {
            return;
        }

        $message = $event->getThrowable()->getMessage();
        $choixDEspace = $this->urlGenerator->generate('admin.entreprise.index');
        $requete = $event->getRequest();

        $attendDuJson = $requete->isXmlHttpRequest()
            || str_contains((string) $requete->headers->get('Accept'), 'application/json');

        $event->setResponse($attendDuJson
            ? new JsonResponse(
                ['message' => $message, 'redirect' => $choixDEspace],
                Response::HTTP_CONFLICT,
            )
            : new RedirectResponse($choixDEspace));
    }
}
