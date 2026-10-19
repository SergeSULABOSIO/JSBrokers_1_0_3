<?php

namespace App\Tests\Support;

use Symfony\Bridge\Monolog\Processor\DebugProcessor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * ENVIRONNEMENT DE TEST SEULEMENT (config/services_test.yaml) — rien en production.
 *
 * Depuis Symfony 7.4, la page d'erreur de DÉBOGAGE liste les journaux de TOUT le processus
 * (`DebugProcessor::getLogs()` appelé sans requête), et plus seulement ceux de la requête.
 * Dans un test, le processus contient aussi le SEMIS : l'INSERT du test lui-même apparaissait
 * dans la page 404, et les tests de cloisonnement (« le nom du client d'un autre cabinet ne
 * fuit pas ») échouaient sur leurs propres données. En production (APP_DEBUG=0) cette page
 * n'existe pas.
 *
 * On rend le comportement de 7.1 : chaque requête principale repart de journaux vides, et un
 * test ne juge que ce que la requête montre.
 */
final class JournauxDeDebogageParRequete
{
    public function __construct(
        #[Autowire(service: 'debug.log_processor')]
        private readonly DebugProcessor $journaux,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 4096)]
    public function auDebutDeLaRequete(RequestEvent $evenement): void
    {
        if ($evenement->isMainRequest()) {
            $this->journaux->reset();
        }
    }
}
