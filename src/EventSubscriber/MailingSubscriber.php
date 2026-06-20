<?php

namespace App\EventSubscriber;

use App\DTO\DemandeContactDTO;
use App\Entity\Invite;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use App\Event\DemandeContactEvent;
use App\Event\InvitationEvent;
use App\Event\TokenPurchaseEvent;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class MailingSubscriber implements EventSubscriberInterface
{
    /**
     * Nom d'expéditeur affiché dans TOUS les e-mails sortants (cohérence corporate :
     * la source apparaît comme « JS Brokers », jamais comme l'adresse technique brute).
     */
    private const SENDER_NAME = 'JS Brokers';

    /** Boîte interne recevant les demandes de contact du site. */
    private const CONTACT_INBOX = 'contact@jsbrokers.com';

    public function __construct(
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private string $mailFrom,
        private string $logoPath,
    ) {}

    public function onDemandeContactEvent(DemandeContactEvent $event): void
    {
        /** @var DemandeContactDTO $data */
        $data = $event->data;

        // 1) E-mail interne (vers la boîte de contact de l'équipe). L'expéditeur reste
        // « JS Brokers » pour la cohérence ; on positionne l'adresse du visiteur en
        // « Répondre à » afin de pouvoir lui répondre directement d'un clic.
        $this->envoyerMail(
            self::CONTACT_INBOX,
            $this->buildSubject($data->objet ?: 'Demande de contact', $data->email),
            'home/mail/message_demande_de_contact.html.twig',
            ['data' => $data],
            new Address($data->email, $data->name ?: $data->email),
        );

        // 2) Accusé de réception automatique au visiteur (même charte corporate). On place
        // la boîte de contact en « Répondre à » : si le visiteur répond, l'équipe reçoit.
        $this->envoyerMail(
            $data->email,
            $this->buildSubject('Accusé de réception', $data->name ?: $data->email),
            'home/mail/accuse_reception_contact.html.twig',
            ['data' => $data],
            new Address(self::CONTACT_INBOX, self::SENDER_NAME),
        );
    }

    public function onInvitationAjoutee(InvitationEvent $event): void
    {
        /** @var Invite $invite */
        $invite = $event->data;

        $destinataire = $invite->getEmail();
        if (!$destinataire) {
            // Invitation sans email exploitable : rien à envoyer.
            return;
        }

        // Liens absolus selon l'état de l'invitation :
        // - en attente (pas de compte) → page de création de compte, email pré-rempli ;
        // - active (compte existant)   → page de connexion.
        $registerUrl = $this->urlGenerator->generate(
            'app_register',
            ['email' => $destinataire],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
        $loginUrl = $this->urlGenerator->generate('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $entreprise = $invite->getEntreprise();

        $this->envoyerMail(
            $destinataire,
            $this->buildSubject('Invitation à rejoindre ' . $entreprise->getNom(), $invite->getNom() ?: $destinataire),
            'home/mail/message_invitation.html.twig',
            [
                'invite' => $invite,
                'entreprise' => $entreprise,
                'enAttente' => $invite->isEnAttente(),
                'registerUrl' => $registerUrl,
                'loginUrl' => $loginUrl,
            ],
        );
    }

    public function onTokenPurchase(TokenPurchaseEvent $event): void
    {
        $purchase = $event->purchase;
        $user = $purchase->getUtilisateur();

        $destinataire = $user?->getEmail();
        if (!$destinataire) {
            return;
        }

        $accountUrl = $this->urlGenerator->generate(
            'admin.token.index',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $this->envoyerMail(
            $destinataire,
            $this->buildSubject('Confirmation de paiement', $user->getNom() ?: $destinataire),
            'emails/token_purchase_confirmation.html.twig',
            [
                'purchase'   => $purchase,
                'accountUrl' => $accountUrl,
            ],
        );
    }

    /**
     * Construit l'objet normalisé de tous les e-mails sortants :
     *   « JS Brokers - [objet] - [destinataire / nom concerné] ».
     */
    private function buildSubject(string $object, string $concerned): string
    {
        return sprintf('%s - %s - %s', self::SENDER_NAME, $object, $concerned);
    }

    /**
     * Point d'envoi unique : expéditeur « JS Brokers », priorité haute, logo + adresse
     * de contact injectés dans le contexte pour la signature (cf. emails/_layout.html.twig).
     */
    private function envoyerMail(
        string $to,
        string $subject,
        string $twigTemplate,
        array $contextData = [],
        ?Address $replyTo = null,
    ): void {
        $contextData += [
            'logoPath' => $this->logoPath,
            'senderEmail' => $this->mailFrom,
        ];

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailFrom, self::SENDER_NAME))
            ->to($to)
            ->priority(Email::PRIORITY_HIGH)
            ->subject($subject)
            ->htmlTemplate($twigTemplate)
            ->context($contextData);

        if ($replyTo !== null) {
            $email->replyTo($replyTo);
        }

        $this->mailer->send($email);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            DemandeContactEvent::class => 'onDemandeContactEvent',
            InvitationEvent::class => 'onInvitationAjoutee',
            TokenPurchaseEvent::class => 'onTokenPurchase',
        ];
    }
}
