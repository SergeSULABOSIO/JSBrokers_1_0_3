<?php

namespace App\EventSubscriber;

use App\DTO\DemandeContactDTO;
use App\Entity\Invite;
use App\Services\Mail\CorporateMailer;
use Symfony\Component\Mime\Address;
use App\Event\DemandeContactEvent;
use App\Event\InvitationEvent;
use App\Event\TokenPurchaseEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class MailingSubscriber implements EventSubscriberInterface
{
    /** Nom d'expéditeur affiché dans TOUS les e-mails sortants. */
    private const SENDER_NAME = CorporateMailer::SENDER_NAME;

    /** Boîte interne recevant les demandes de contact du site. */
    private const CONTACT_INBOX = 'contact@jsbrokers.com';

    public function __construct(
        private CorporateMailer $corporateMailer,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
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

        // L'e-mail suit la langue choisie par l'utilisateur. La locale est figée
        // ici (sujet traduit + contexte `locale`) afin que le rendu reste correct
        // même lorsque l'envoi est différé (transport Messenger asynchrone).
        $locale = $user->getLocale() ?: 'fr';

        $this->envoyerMail(
            $destinataire,
            $this->buildSubject(
                $this->translator->trans('email_token_subject', [], 'messages', $locale),
                $user->getNom() ?: $destinataire
            ),
            'emails/token_purchase_confirmation.html.twig',
            [
                'purchase'   => $purchase,
                'accountUrl' => $accountUrl,
                'locale'     => $locale,
            ],
        );
    }

    /**
     * Construit l'objet normalisé de tous les e-mails sortants :
     *   « JS Brokers - [objet] - [destinataire / nom concerné] ».
     */
    private function buildSubject(string $object, string $concerned): string
    {
        return $this->corporateMailer->buildSubject($object, $concerned);
    }

    /**
     * Point d'envoi : délègue au CorporateMailer (expéditeur « JS Brokers »,
     * priorité haute, logo + adresse de contact injectés pour la signature).
     */
    private function envoyerMail(
        string $to,
        string $subject,
        string $twigTemplate,
        array $contextData = [],
        ?Address $replyTo = null,
    ): void {
        $this->corporateMailer->send($to, $subject, $twigTemplate, $contextData, $replyTo);
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
