<?php

namespace App\EventSubscriber;

use App\DTO\DemandeContactDTO;
use App\Entity\Invite;
use Symfony\Component\Mime\Email;
use App\Event\DemandeContactEvent;
use App\Event\InvitationEvent;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\SecurityBundle\Security;
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
        $this->envoyerMail(
            'contact@jsbrokers.com',
            $data->email,
            Email::PRIORITY_HIGH,
            'Demande de contact',
            "home/mail/message_demande_de_contact.html.twig",
            [
                "data" => $data
            ]
        );
    }

    public function onInvitationAjoutee(InvitationEvent $event): void
    {
        /** @var Invite $invite */
        $invite = $event->data;
        # C'est ici qu'on va gérer l'envoie de l'email de l'utilisateur
        $this->envoyerMail(
            'contact@jsbrokers.com',
            $invite->getEmail(),
            Email::PRIORITY_HIGH,
            'Invitation JS Brokers venant de ' . $invite->getUtilisateur()->getNom(),
            "home/mail/message_invitation.html.twig",
            [
                "invite" => $invite,
                "utilisateur" => $invite->getUtilisateur(),
            ]
        );
    }



    public function envoyerMail(string $to, string $from, $priority, string $subject, string $twigTemplate, array $contextData)
    {
        $email = (new TemplatedEmail())
            ->to($to)
            ->from($from)
            ->priority($priority)
            ->subject($subject)
            ->htmlTemplate($twigTemplate)
            ->context($contextData);
        $this->mailer->send($email);
    }


    public static function getSubscribedEvents(): array
    {
        return [
            DemandeContactEvent::class => 'onDemandeContactEvent',
            InvitationEvent::class => 'onInvitationAjoutee',
        ];
    }
}
