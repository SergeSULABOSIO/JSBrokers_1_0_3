<?php

namespace App\EventSubscriber;

use App\Crm\CrmNotifier;
use App\DTO\DemandeContactDTO;
use App\Entity\Crm\CrmNotification;
use App\Entity\Crm\CrmTicket;
use App\Event\DemandeContactEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @file Transforme un message du formulaire de contact (vitrine) en CrmTicket.
 * @description Écoute le même DemandeContactEvent que MailingSubscriber : en plus
 * des e-mails (équipe + accusé visiteur), chaque message crée un ticket de support
 * (canal « contact ») dans la file de la console et notifie les agents in-app, afin
 * qu'une action soit entreprise rapidement. Le visiteur étant anonyme, son identité
 * est portée par les champs contact* du ticket (pas de compte client).
 */
class ContactTicketSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private CrmNotifier $notifier,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    public function onDemandeContact(DemandeContactEvent $event): void
    {
        /** @var DemandeContactDTO $data */
        $data = $event->data;

        // La création du ticket ne doit jamais compromettre l'envoi des e-mails
        // (gérés par MailingSubscriber sur le même évènement) : un souci de
        // persistance est journalisé, pas propagé.
        try {
            $ticket = (new CrmTicket())
                ->setSujet($data->objet ?: 'Demande de contact')
                ->setDescription($data->message)
                ->setCanal(CrmTicket::CANAL_CONTACT)
                ->setContactNom($data->name ?: null)
                ->setContactEmail($data->email ?: null)
                ->setContactTelephone($data->wantsPhone ? ($data->phone ?: null) : null);

            $this->em->persist($ticket);
            $this->em->flush();

            // Notification in-app à tous les agents, avec lien direct vers la fiche.
            $this->notifier->broadcast(
                'Nouveau message de contact',
                sprintf(
                    '%s a laissé un message via la vitrine (ticket %s) : « %s ».',
                    $ticket->getDemandeurNom() ?: 'Un visiteur',
                    $ticket->getReference(),
                    $ticket->getSujet(),
                ),
                CrmNotification::NIVEAU_INFO,
                $this->urlGenerator->generate('console.crm.ticket.show', ['id' => $ticket->getId()]),
                true,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Contact: échec de création du ticket pour {email}: {msg}', [
                'email' => $data->email,
                'msg'   => $e->getMessage(),
            ]);
        }
    }

    public static function getSubscribedEvents(): array
    {
        // Priorité élevée : le ticket (objectif principal) est créé avant l'envoi
        // des e-mails. Comme le corps est encadré d'un try/catch, l'envoi des
        // e-mails par MailingSubscriber reste garanti même en cas d'échec ici.
        return [
            DemandeContactEvent::class => ['onDemandeContact', 10],
        ];
    }
}
