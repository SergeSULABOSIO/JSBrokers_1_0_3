<?php

namespace App\MessageHandler;

use App\Ai\Telemetrie\JournalTokens;
use App\Ai\Traitement\IdentiteDuTraitement;
use App\Ai\Traitement\TraitementDuMessage;
use App\Ai\Traitement\VerrouDeConversation;
use App\Entity\AssistantMessage;
use App\Entity\AssistantTache;
use App\Message\TraiterMessagesAssistant;
use App\Repository\AssistantConversationRepository;
use App\Repository\AssistantTacheRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @file Draine les questions en attente d'une conversation, une par une.
 * @description Le seul endroit d'où Ket répond quand elle tourne en tâche de fond.
 *
 * UN SIGNAL, UN DRAINAGE. Le handler ne traite pas « la » tâche du message reçu :
 * il prend le verrou de la conversation, puis vide la file tant qu'elle contient
 * quelque chose. Trois propriétés en découlent d'un coup :
 *
 *  - FIFO STRICT. prochaineEnAttente() rend la plus ancienne. Une rafale de trois
 *    est donc traitée dans l'ordre où l'utilisateur a tapé, et chaque question
 *    voit dans le fil la réponse à la précédente — exactement comme trois envois
 *    séquentiels. C'est la définition même de « aucun changement métier ».
 *  - IDEMPOTENCE. Un signal en double, un rejeu Messenger, un dispatch superflu :
 *    le verrou est déjà pris, le handler sort sans rien faire. Ce n'est pas un
 *    échec, c'est le fonctionnement normal — d'où le `return`, et surtout pas une
 *    exception qui déclencherait un retry.
 *  - INDIFFÉRENCE AU NOMBRE DE WORKERS. On en exploite un ; deux ne casseraient
 *    rien, ils se répartiraient les conversations sans jamais se croiser sur la
 *    même.
 */
#[AsMessageHandler]
final class TraiterMessagesAssistantHandler
{
    public function __construct(
        private readonly AssistantTacheRepository $taches,
        private readonly AssistantConversationRepository $conversations,
        private readonly VerrouDeConversation $verrou,
        private readonly IdentiteDuTraitement $identite,
        private readonly TraitementDuMessage $traitement,
        private readonly JournalTokens $journalTokens,
        private readonly MessageBusInterface $bus,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(TraiterMessagesAssistant $message): void
    {
        $idConversation = $message->idConversation;

        if (!$this->verrou->prendre($idConversation)) {
            // Un autre drainage est en cours sur cette conversation : il prendra
            // aussi nos tâches. Se retirer est la bonne réponse.
            return;
        }

        try {
            while (($tache = $this->taches->prochaineEnAttente($idConversation)) !== null) {
                $this->traiterUneTache($tache);
            }
        } finally {
            $this->verrou->relacher($idConversation);
        }

        // COURSE DE FERMETURE. Une question acceptée entre la dernière itération
        // et le relâchement du verrou serait restée orpheline : son propre signal,
        // émis pendant que nous tenions encore le verrou, s'est retiré en no-op.
        // Personne ne viendrait plus la chercher. On réémet donc le signal.
        if ($this->taches->prochaineEnAttente($idConversation) !== null) {
            $this->bus->dispatch($message);
        }
    }

    /**
     * Une question, une réponse. N'échoue jamais pour une panne du moteur :
     * celle-ci produit une réponse d'excuse persistée, et la tâche se termine
     * normalement. Le statut « echouee » est réservé aux pannes du traitement
     * lui-même, où il n'y a aucune réponse à montrer.
     */
    private function traiterUneTache(AssistantTache $tache): void
    {
        $conversation = $tache->getConversation();

        // La conversation a pu être supprimée pendant que la question attendait.
        // Rien à traiter, et surtout rien à faire échouer : la tâche a disparu
        // avec elle en cascade — ce test ne couvre que la fenêtre où l'entité est
        // encore en mémoire.
        if ($conversation === null
            || $this->conversations->find((int) $conversation->getId()) === null) {
            $this->logger->info('Assistant IA : conversation disparue avant traitement, tâche abandonnée.', [
                'tache' => $tache->getId(),
            ]);
            $this->em->remove($tache);
            $this->em->flush();

            return;
        }

        // LA QUESTION ENTRE DANS LE FIL MAINTENANT, et pas à l'acceptation : son
        // identifiant se place ainsi juste avant celui de sa réponse, et une
        // rafale reste ordonnée « Q1 A1 Q2 A2 Q3 A3 ». Voir AssistantTache.
        $messageUser = (new AssistantMessage())
            ->setRole(AssistantMessage::ROLE_USER)
            ->setContenu($tache->getContenu())
            ->setRepondA($tache->getRepondA())
            // Instantané pris à l'ENVOI, recopié tel quel : ce que l'utilisateur
            // détache pendant l'attente ne réécrit pas ce qu'il a envoyé.
            ->setContexteObjets($tache->getContexteObjets())
            ->setFichiersJoints($tache->getFichiersJoints());
        $conversation->addMessage($messageUser);

        $tache->setMessageUtilisateur($messageUser)
            ->setStatut(AssistantTache::STATUT_EN_COURS)
            ->setStartedAt(new \DateTimeImmutable());
        $this->em->flush();

        // Le worker VIT, à la différence d'une requête HTTP qui repart d'un
        // conteneur neuf : sans cette remise à zéro, les compteurs de la tâche
        // précédente contamineraient le récapitulatif de celle-ci. Le moteur
        // rappellera nouveauMessage() aussitôt — c'est idempotent, et cela couvre
        // le cas où la construction du contexte échoue AVANT lui.
        $this->journalTokens->nouveauMessage();

        // Même abonné que le flux SSE, même contrat : ce que le journal annonce,
        // la tâche le note, et le navigateur le lit tel quel.
        $idTache = (int) $tache->getId();
        $this->journalTokens->ecouter(function (array $etape) use ($idTache): void {
            $this->taches->noterEtape($idTache, $etape);
        });

        $this->identite->endosser($conversation->getInvite(), $conversation->getEntreprise());

        try {
            $messageAssistant = $this->traitement->repondre($messageUser);

            $tache->setMessageAssistant($messageAssistant)
                ->setStatut(AssistantTache::STATUT_TERMINEE)
                ->setEtape(null)
                ->setFinishedAt(new \DateTimeImmutable());
            $this->em->flush();
        } catch (\Throwable $e) {
            // On arrive ici quand le TRAITEMENT casse, pas quand le moteur
            // échoue : ce dernier a son propre rattrapage et rend une excuse.
            // Il n'y a donc aucune réponse à montrer — la boucle doit malgré tout
            // avancer, sinon la conversation resterait bloquée sur cette question.
            $this->logger->error('Assistant IA : le traitement de la question a échoué.', [
                'exception' => $e,
                'tache'     => $tache->getId(),
            ]);
            $this->marquerEchouee($tache, $e);
        } finally {
            // TOUJOURS débrancher et relâcher : le journal et le stockage de jeton
            // sont des services partagés, et le processus enchaînera sur une autre
            // tâche — peut-être d'un autre cabinet.
            $this->journalTokens->ecouter(null);
            $this->identite->relacher();
        }
    }

    /**
     * Consigne l'échec sans dépendre de l'état de l'EntityManager, qui peut être
     * fermé par l'exception qu'on est en train de traiter. Sans ce repli, la
     * tâche resterait « en_cours » pour toujours et le navigateur l'attendrait
     * indéfiniment.
     */
    private function marquerEchouee(AssistantTache $tache, \Throwable $e): void
    {
        try {
            if (!$this->em->isOpen()) {
                throw new \RuntimeException("L'EntityManager est fermé.");
            }
            $tache->setStatut(AssistantTache::STATUT_ECHOUEE)
                ->setEtape(null)
                ->setErreur(mb_substr($e->getMessage(), 0, 2000))
                ->setFinishedAt(new \DateTimeImmutable());
            $this->em->flush();
        } catch (\Throwable) {
            $this->em->getConnection()->update(
                'assistant_tache',
                [
                    'statut'      => AssistantTache::STATUT_ECHOUEE,
                    'etape'       => null,
                    'erreur'      => mb_substr($e->getMessage(), 0, 2000),
                    'finished_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
                ['id' => $tache->getId()],
            );
        }
    }
}
