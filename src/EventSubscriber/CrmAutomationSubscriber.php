<?php

namespace App\EventSubscriber;

use App\Crm\CrmAutomationEngine;
use App\Crm\CrmSyncService;
use App\Entity\Utilisateur;
use App\Event\TokenPurchaseEvent;
use App\Repository\TokenPurchaseRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @file Automatisations CRM en temps réel.
 * @description Réagit aux événements métier existants. Sur un achat de tokens :
 * resynchronise le profil du client (étape de pipeline + score) et, s'il s'agit
 * du tout premier achat, déclenche l'onboarding (tâche + notification). Réutilise
 * TokenPurchaseEvent déjà émis par le flux d'achat (DRY).
 */
class CrmAutomationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private CrmSyncService $crmSync,
        private CrmAutomationEngine $engine,
        private TokenPurchaseRepository $purchaseRepository,
    ) {
    }

    public function onTokenPurchase(TokenPurchaseEvent $event): void
    {
        $client = $event->purchase->getUtilisateur();
        if (!$client instanceof Utilisateur) {
            return;
        }

        // Premier achat ? (le nombre total d'achats vaut 1 juste après celui-ci.)
        $metrics = $this->purchaseRepository->metricsForUser((int) $client->getId());
        if ($metrics['count'] === 1) {
            $this->engine->onFirstPurchase($client);
        }

        // Resynchronise l'étape et le score (flushe les entités créées ci-dessus).
        $this->crmSync->refresh($client);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TokenPurchaseEvent::class => 'onTokenPurchase',
        ];
    }
}
