<?php

namespace App\EventSubscriber;

use App\Entity\Utilisateur;
use App\Service\Onboarding\OnboardingNotifier;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * DÉCLENCHE LA SYNTHÈSE DE CONFIGURATION APRÈS UNE ÉCRITURE.
 *
 * ── POURQUOI `kernel.terminate` ─────────────────────────────────────────────────────
 * La réponse est DÉJÀ PARTIE quand cet événement se produit : le recalcul du score et
 * l'envoi n'ajoutent donc pas une milliseconde au temps que l'utilisateur attend après
 * avoir cliqué « Enregistrer ».
 *
 * C'est aussi ce qui évite le piège d'un écouteur Doctrine `postFlush` : le notifieur
 * doit lui-même écrire (le dernier score annoncé) et donc flusher, ce qu'un `postFlush`
 * interdit en pratique.
 *
 * ── POURQUOI SEULEMENT LES ÉCRITURES ────────────────────────────────────────────────
 * Un score ne bouge pas sur un GET. Recalculer à chaque affichage de liste ferait payer
 * une quinzaine de comptages à des requêtes qui n'en tireraient rien.
 *
 * ── ET SEULEMENT POUR LE PROPRIÉTAIRE ───────────────────────────────────────────────
 * La synthèse s'adresse à qui peut agir. On lit l'entreprise sur `connectedTo`, que
 * l'espace de travail synchronise à chaque entrée : c'est le cabinet réellement ouvert,
 * et non un cabinet quelconque de l'utilisateur.
 */
class OnboardingScoreSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private OnboardingNotifier $notifier,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'surFinDeRequete'];
    }

    public function surFinDeRequete(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if ($event->getRequest()->isMethodSafe()) {
            return;
        }

        // Une réponse en erreur n'a rien enregistré : annoncer un nouveau score après un
        // 422 de validation reviendrait à féliciter pour une saisie refusée.
        if ($event->getResponse()->getStatusCode() >= 400) {
            return;
        }

        $utilisateur = $this->tokenStorage->getToken()?->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $entreprise = $utilisateur->getConnectedTo();
        if ($entreprise === null || $entreprise->getUtilisateur()?->getId() !== $utilisateur->getId()) {
            return;
        }

        try {
            $this->notifier->notifierSiScoreChange($entreprise);
        } catch (\Throwable) {
            // Fail-safe : la requête est terminée et son travail est enregistré. Rien de
            // ce qui se passe ici ne doit pouvoir remonter jusqu'à l'utilisateur.
        }
    }
}
