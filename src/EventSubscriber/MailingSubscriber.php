<?php

namespace App\EventSubscriber;

use App\DTO\DemandeContactDTO;
use Symfony\Component\Mime\Email;
use App\Event\DemandeContactEvent;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class MailingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MailerInterface $mailer,
    ) {}

    public function onDemandeContactEvent(DemandeContactEvent $event): void
    {
        /** @var DemandeContactDTO $data */
        $data = $event->data;
        # C'est ici qu'on va gérer l'envoie de l'email de l'utilisateur
        $email = (new TemplatedEmail())
            ->to('contact@demo.fr')
            ->from($data->email)
            //->cc('cc@example.com')
            //->bcc('bcc@example.com')
            //->replyTo('fabien@example.com')
            ->priority(Email::PRIORITY_HIGH)
            ->subject('Demande de contact')
            // ->text($data->message)
            // ->html('<p>' . $data->message . '</p>');
            ->htmlTemplate("home/mail/message_demande_de_contact.html.twig")
            ->context(["data" => $data]);
        $this->mailer->send($email);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            DemandeContactEvent::class => 'onDemandeContactEvent',
        ];
    }
}
