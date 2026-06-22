<?php

namespace App\Services\Mail;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * @file Point d'envoi unifié des e-mails corporate JS Brokers.
 * @description Extrait de MailingSubscriber (DRY) afin d'être réutilisable par
 * tous les émetteurs (confirmations, invitations, notifications aux agents…).
 * Garantit la cohérence corporate : expéditeur « JS Brokers », objet normalisé
 * « JS Brokers - [objet] - [concerné] », priorité haute, logo + adresse de
 * contact injectés dans le contexte pour la signature (cf. emails/_layout).
 */
class CorporateMailer
{
    /** Nom d'expéditeur affiché dans TOUS les e-mails sortants. */
    public const SENDER_NAME = 'JS Brokers';

    public function __construct(
        private MailerInterface $mailer,
        private string $mailFrom,
        private string $logoPath,
    ) {
    }

    /** Construit l'objet normalisé : « JS Brokers - [objet] - [concerné] ». */
    public function buildSubject(string $object, string $concerned): string
    {
        return sprintf('%s - %s - %s', self::SENDER_NAME, $object, $concerned);
    }

    /**
     * Envoie un e-mail corporate. Le logo (CID) et l'adresse de contact sont
     * ajoutés au contexte s'ils ne sont pas déjà fournis.
     *
     * @param string|string[] $to
     */
    public function send(
        string|array $to,
        string $subject,
        string $twigTemplate,
        array $contextData = [],
        ?Address $replyTo = null,
    ): void {
        $contextData += [
            'logoPath'    => $this->logoPath,
            'senderEmail' => $this->mailFrom,
        ];

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailFrom, self::SENDER_NAME))
            ->priority(Email::PRIORITY_HIGH)
            ->subject($subject)
            ->htmlTemplate($twigTemplate)
            ->context($contextData);

        foreach ((array) $to as $destinataire) {
            $email->addTo($destinataire);
        }

        if ($replyTo !== null) {
            $email->replyTo($replyTo);
        }

        $this->mailer->send($email);
    }
}
