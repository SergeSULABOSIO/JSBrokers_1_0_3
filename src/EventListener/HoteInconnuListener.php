<?php

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * UN HÔTE INCONNU N'EST PAS UNE PANNE.
 *
 * En production, Symfony n'accepte que joseara.com et www.joseara.com (trusted_hosts, parade
 * à l'empoisonnement d'en-tête Host). Une requête adressée à un autre nom — robot qui vise
 * l'adresse IP, domaine étranger, sous-domaine que cPanel sert depuis notre dossier — levait
 * « Untrusted Host », que Symfony journalisait en ERROR : un e-mail d'alerte par vague
 * (le dédoublonnage ne couvre que cinq minutes), et une ligne de supervision pour un défaut
 * qui n'est pas dans notre code. Les sous-domaines *.joseara.com sont désormais renvoyés vers
 * le site par public/.htaccess, AVANT PHP ; ce qui arrive malgré tout reçoit ici un 400 sobre,
 * journalisé en INFO (une trace, pas une alerte) — comme les 404 des robots.
 *
 * Seul ce refus-là est concerné : un 400 venu de NOS routes reste une alerte.
 * Priorité au-dessus du journal d'erreur de Symfony (ErrorListener::logKernelException, 0).
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 16)]
final class HoteInconnuListener
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(ExceptionEvent $evenement): void
    {
        $exception = $evenement->getThrowable();
        if (!$exception instanceof BadRequestHttpException
            || !$exception->getPrevious() instanceof SuspiciousOperationException
            || !str_starts_with($exception->getMessage(), 'Untrusted Host')) {
            return;
        }

        $this->logger->info('Hôte inconnu refusé : {message}', ['message' => $exception->getMessage()]);
        $evenement->setResponse(new Response('Hôte inconnu.', Response::HTTP_BAD_REQUEST, ['Content-Type' => 'text/plain; charset=UTF-8']));
        $evenement->stopPropagation();
    }
}
