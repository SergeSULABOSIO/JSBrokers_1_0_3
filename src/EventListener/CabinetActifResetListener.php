<?php

namespace App\EventListener;

use App\Service\Workspace\CabinetActif;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * LA RÉPONSE DU GARDIEN NE VAUT QUE POUR LA REQUÊTE EN COURS.
 *
 * {@see CabinetActif} mémorise sa réponse : il est interrogé des dizaines de fois par
 * requête (chaque champ de relation d'un formulaire y passe), et la reposer à la base
 * à chaque fois coûterait autant de requêtes SQL pour une réponse qui ne change pas.
 *
 * ── POURQUOI UN LISTENER, ET NON UN SIMPLE SERVICE PARTAGÉ ──────────────────────
 * Parce que la mémoire peut être prise AVANT la requête. Un `EntityType` interrogé
 * hors contexte HTTP — construction d'un formulaire pour en lire l'arborescence,
 * commande en ligne, test fonctionnel qui inspecte un champ avant d'émettre sa
 * première requête — fait résoudre le gardien alors qu'aucun utilisateur n'est
 * authentifié. Il mémorise alors « aucun cabinet », légitimement ; mais si le conteneur
 * survit jusqu'à la requête suivante, cette réponse-là devient fausse.
 *
 * Le symptôme observé : le PREMIER champ d'autocomplétion d'une session de test ne
 * proposait rien, les suivants fonctionnaient. Un noyau non redémarré suffisait à
 * transporter la réponse d'avant-la-requête dans la requête.
 *
 * ── PRIORITÉ ───────────────────────────────────────────────────────────────────
 * 4096 : avant le pare-feu, donc avant que quoi que ce soit ne puisse interroger le
 * gardien pour cette requête. Un reset postérieur à la première lecture ne servirait
 * à rien.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4096)]
class CabinetActifResetListener
{
    public function __construct(private CabinetActif $cabinetActif)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->cabinetActif->reset();
        }
    }
}
