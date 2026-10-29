<?php

namespace App\EventSubscriber;

use App\Ai\Traitement\PoulsDuWorker;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Worker;

/**
 * LE BATTEMENT DE CŒUR DU WORKER DE KET (Serge, 2026-10-29) : au démarrage, puis au plus toutes
 * les PoulsDuWorker::INTERVALLE_SECONDES — le worker tourne en boucle (une itération par message,
 * ou par seconde d'attente), écrire à chaque tour serait inutile.
 *
 * Seul un worker qui consomme la file `async` (celle de Ket) bat : un autre consommateur ne dit
 * rien de la capacité de Ket à répondre.
 */
final class BattementDuWorkerSubscriber implements EventSubscriberInterface
{
    public const TRANSPORT = 'async';

    private ?int $dernierBattement = null;

    public function __construct(
        private readonly PoulsDuWorker $pouls,
        private readonly ClockInterface $horloge,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::class => 'auDemarrage',
            WorkerRunningEvent::class => 'enMarche',
        ];
    }

    public function auDemarrage(WorkerStartedEvent $event): void
    {
        if ($this->consommeLaFileDeKet($event->getWorker())) {
            $this->battre();
        }
    }

    public function enMarche(WorkerRunningEvent $event): void
    {
        if (!$this->consommeLaFileDeKet($event->getWorker())) {
            return;
        }
        $maintenant = $this->horloge->now()->getTimestamp();
        if ($this->dernierBattement === null || $maintenant - $this->dernierBattement >= PoulsDuWorker::INTERVALLE_SECONDES) {
            $this->battre();
        }
    }

    private function battre(): void
    {
        $this->pouls->battre();
        $this->dernierBattement = $this->horloge->now()->getTimestamp();
    }

    private function consommeLaFileDeKet(Worker $worker): bool
    {
        return \in_array(self::TRANSPORT, $worker->getMetadata()->getTransportNames(), true);
    }
}
