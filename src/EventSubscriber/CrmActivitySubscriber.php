<?php

namespace App\EventSubscriber;

use App\Crm\CrmSyncService;
use App\Entity\Utilisateur;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * @file Suivi d'activité léger pour le CRM interne.
 * @description À chaque connexion réussie d'un client (utilisateur non agent),
 * horodate la dernière connexion, incrémente le compteur et resynchronise son
 * profil CRM (étape de pipeline + score de santé). Les agents JS Brokers sont
 * ignorés : le CRM ne suit que les clients. Aucune ressaisie : tout vient du SaaS.
 */
class CrmActivitySubscriber implements EventSubscriberInterface
{
    public function __construct(private CrmSyncService $crmSync)
    {
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof Utilisateur || $user->isAgent()) {
            return;
        }

        $user->registerLogin(new \DateTimeImmutable());
        // refresh() flushe l'utilisateur (lastLoginAt/loginCount) ET le profil CRM.
        $this->crmSync->refresh($user);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }
}
