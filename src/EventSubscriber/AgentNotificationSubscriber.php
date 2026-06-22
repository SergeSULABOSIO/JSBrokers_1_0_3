<?php

namespace App\EventSubscriber;

use App\Event\AgentNotificationEvent;
use App\Event\TokenPurchaseEvent;
use App\Repository\UtilisateurRepository;
use App\Services\Mail\CorporateMailer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @file Notifie l'équipe JS Brokers (tous les agents) des événements de gestion.
 * @description Couvre, comme demandé : création/édition/suppression d'un compte
 * ou d'une entreprise (AgentNotificationEvent) et chaque paiement de tokens
 * (réutilise TokenPurchaseEvent). Chaque agent (ROLE_ADMIN / ROLE_SUPER_ADMIN)
 * reçoit un e-mail corporate, ce qui permet d'intervenir au bon endroit, au bon
 * moment. Les e-mails internes sont rédigés en français (langue de l'équipe).
 */
class AgentNotificationSubscriber implements EventSubscriberInterface
{
    private const TEMPLATE = 'emails/agent_notification.html.twig';

    /** Libellés humains par couple action/type. */
    private const LIBELLES = [
        AgentNotificationEvent::ACTION_CREATE => [
            AgentNotificationEvent::TYPE_UTILISATEUR => 'Nouveau compte utilisateur',
            AgentNotificationEvent::TYPE_ENTREPRISE  => 'Nouvelle entreprise',
        ],
        AgentNotificationEvent::ACTION_UPDATE => [
            AgentNotificationEvent::TYPE_UTILISATEUR => 'Compte utilisateur modifié',
            AgentNotificationEvent::TYPE_ENTREPRISE  => 'Entreprise modifiée',
        ],
        AgentNotificationEvent::ACTION_DELETE => [
            AgentNotificationEvent::TYPE_UTILISATEUR => 'Compte utilisateur supprimé',
            AgentNotificationEvent::TYPE_ENTREPRISE  => 'Entreprise supprimée',
        ],
    ];

    public function __construct(
        private CorporateMailer $corporateMailer,
        private UtilisateurRepository $utilisateurRepository,
    ) {
    }

    public function onAgentNotification(AgentNotificationEvent $event): void
    {
        $objet = self::LIBELLES[$event->action][$event->type] ?? 'Notification';

        $this->diffuser(
            $objet,
            $event->libelle,
            'action:alert',
            $objet,
            'Action effectuée sur la plateforme : ' . strtolower($objet) . '.',
            $event->details,
        );
    }

    public function onTokenPurchase(TokenPurchaseEvent $event): void
    {
        $purchase = $event->purchase;
        $acheteur = $purchase->getUtilisateur();
        $libelle = $acheteur?->getNom() ?: ($acheteur?->getEmail() ?: 'Utilisateur');

        $details = [
            'Acheteur'   => $libelle . ($acheteur?->getEmail() ? ' (' . $acheteur->getEmail() . ')' : ''),
            'Paquet'     => ucfirst((string) $purchase->getPack()),
            'Tokens'     => number_format($purchase->getTokens(), 0, ',', ' '),
            'Montant'    => number_format($purchase->getMontantUsd(), 2, '.', ' ') . ' $',
            'Référence'  => (string) $purchase->getReference(),
        ];
        if ($purchase->getCouponCode()) {
            $details['Coupon'] = $purchase->getCouponCode()
                . ' (−' . number_format($purchase->getRemiseUsd(), 2, '.', ' ') . ' $)';
        }

        $this->diffuser(
            'Vente de tokens',
            $libelle,
            'action:cart',
            'Nouvelle vente de tokens',
            'Un paiement de paquet de tokens vient d\'être enregistré sur la plateforme.',
            $details,
        );
    }

    /** Envoie l'e-mail de notification à tous les agents JS Brokers. */
    private function diffuser(
        string $objet,
        string $concerne,
        string $icone,
        string $titre,
        string $intro,
        array $details,
    ): void {
        $agents = $this->utilisateurRepository->findAgents();
        if ($agents === []) {
            return;
        }

        foreach ($agents as $agent) {
            $email = $agent->getEmail();
            if (!$email) {
                continue;
            }

            $this->corporateMailer->send(
                $email,
                $this->corporateMailer->buildSubject($objet, $concerne),
                self::TEMPLATE,
                [
                    'titre'   => $titre,
                    'intro'   => $intro,
                    'icone'   => $icone,
                    'details' => $details,
                    'agent'   => $agent->getNom(),
                ],
            );
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AgentNotificationEvent::class => 'onAgentNotification',
            TokenPurchaseEvent::class => 'onTokenPurchase',
        ];
    }
}
