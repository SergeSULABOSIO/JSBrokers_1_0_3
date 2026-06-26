<?php

namespace App\EventSubscriber;

use App\Crm\CrmHeartbeatService;
use App\Crm\CrmMaintenanceService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @file Déclenchement paresseux des automatisations CRM à l'usage de la Console.
 * @description À chaque accès à une page CRM (et au ping Stimulus), tente de
 * réserver le créneau quotidien ; si gagné, exécute la routine APRÈS l'envoi de
 * la réponse (kernel.terminate) — l'agent n'attend jamais. Sans cron ni worker.
 * Toute erreur est avalée : un échec de maintenance ne doit jamais impacter la
 * navigation (la réponse est déjà partie).
 */
class CrmHeartbeatSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private CrmHeartbeatService $heartbeat,
        private CrmMaintenanceService $maintenance,
    ) {
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!str_starts_with($event->getRequest()->getPathInfo(), '/console/crm')) {
            return;
        }

        try {
            if ($this->heartbeat->claimIfDue()) {
                $this->maintenance->runDaily();
            }
        } catch (\Throwable) {
            // La réponse est déjà envoyée : on n'altère jamais l'expérience agent.
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'onTerminate',
        ];
    }
}
