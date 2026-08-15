<?php

namespace App\Ai\Traitement;

use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\AssistantTache;
use App\Message\TraiterMessagesAssistant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * @file Par où une question entre dans la file.
 * @description Point d'entrée UNIQUE : inscrit la tâche, puis émet le signal.
 *
 * POURQUOI UN SEUL ENDROIT. Inscrire la tâche et émettre le signal sont deux
 * gestes qui n'ont de sens qu'ensemble : une tâche sans signal attendrait un
 * réveil qui ne viendrait pas, un signal sans tâche réveillerait un drainage qui
 * ne trouverait rien. Les tenir dans la même méthode est ce qui empêche
 * d'oublier l'un des deux.
 *
 * LA BASCULE TIENT DANS UNE VARIABLE D'ENVIRONNEMENT. À ASSISTANT_ASYNC=0, le
 * signal est estampillé pour le transport `sync` : le drainage a donc lieu
 * PENDANT le dispatch, dans le processus web, et l'appelant retrouve une tâche
 * déjà terminée — c'est-à-dire le comportement d'avant la refonte, à
 * l'identique. C'est ce qui permet de livrer toute la mécanique en production
 * sans l'exposer, et de revenir en arrière sans redéploiement.
 */
final class FileDeTraitement
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        #[Autowire('%env(bool:ASSISTANT_ASYNC)%')]
        private readonly bool $asynchrone,
    ) {
    }

    /**
     * Dépose une question déjà acceptée (validée, métrée) et réclame son
     * traitement.
     *
     * $question est TRANSITOIRE et le reste : elle a servi à métrer (le poids
     * est parométrique, il ne dépend que de la classe) et à porter l'instantané
     * du contexte pris à l'envoi. Le vrai message du fil naîtra au drainage,
     * pour que son identifiant se place juste avant celui de sa réponse — voir
     * le commentaire de AssistantTache, c'est ce qui garde le fil dans l'ordre.
     *
     * Le flush est indispensable AVANT le signal : un worker qui se réveillerait
     * plus vite que la transaction ne trouverait pas la tâche.
     */
    public function deposer(AssistantConversation $conversation, AssistantMessage $question): AssistantTache
    {
        $tache = (new AssistantTache())
            ->setConversation($conversation)
            ->setContenu((string) $question->getContenu())
            ->setContexteObjets($question->getContexteObjets())
            ->setFichiersJoints($question->getFichiersJoints())
            ->setRepondA($question->getRepondA());

        $this->em->persist($tache);
        $this->em->flush();

        $enveloppe = new Envelope(new TraiterMessagesAssistant((int) $conversation->getId()));
        if (!$this->asynchrone) {
            $enveloppe = $enveloppe->with(new TransportNamesStamp(['sync']));
        }
        $this->bus->dispatch($enveloppe);

        // Le drainage synchrone vient de la faire passer à « terminee » : c'est
        // l'appelant qui décide s'il rend la réponse ou un accusé de réception.
        return $tache;
    }
}
