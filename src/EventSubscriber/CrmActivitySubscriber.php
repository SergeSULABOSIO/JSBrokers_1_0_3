<?php

namespace App\EventSubscriber;

use App\Crm\CrmSyncService;
use App\Entity\Utilisateur;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * @file Suivi d'activité léger pour le CRM interne.
 * @description À chaque connexion réussie d'un compte suivi par le CRM, horodate
 * la dernière connexion, incrémente le compteur et resynchronise son profil CRM
 * (étape de pipeline + score de santé). Sont suivis : les utilisateurs classiques
 * (non agents) ET les agents JS Brokers qui sont eux-mêmes clients payants
 * (solde prépayé > 0). Aucune ressaisie : tout vient du SaaS.
 */
class CrmActivitySubscriber implements EventSubscriberInterface
{
    public function __construct(private CrmSyncService $crmSync)
    {
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        // Suivi des utilisateurs classiques, et des agents qui sont aussi clients
        // payants (solde prépayé > 0). Les agents non-clients sont ignorés.
        if (!$user instanceof Utilisateur || ($user->isAgent() && $user->getPaidTokens() <= 0)) {
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
