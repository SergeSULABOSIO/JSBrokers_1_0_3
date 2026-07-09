<?php

namespace App\Service\Soa;

use App\Entity\SoaAccesToken;
use App\Services\Mail\CorporateMailer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @file Envoie au client (assuré) le lien d'accès public à son relevé de compte (SOA).
 * @description E-mail corporate adressé au destinataire choisi par le courtier
 * (e-mail du client ou d'un de ses contacts), contenant le bouton d'accès au
 * SOA public, la date d'expiration du lien et un éventuel message
 * d'accompagnement du courtier. Sur le modèle d'InvitePerimetreNotifier :
 * tolérance aux pannes — un échec d'envoi n'annule jamais le jeton persisté,
 * l'appelant décide du message à montrer selon le booléen retourné.
 */
class SoaClientNotifier
{
    public function __construct(
        private readonly CorporateMailer $corporateMailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function envoyerLien(
        SoaAccesToken $token,
        string $emailDestinataire,
        string $nomDestinataire,
        ?string $messageCourtier,
        string $locale = 'fr',
    ): bool {
        $client     = $token->getClient();
        $entreprise = $token->getEntreprise();

        $soaUrl = $this->urlGenerator->generate(
            'public.soa.view',
            ['token' => $token->getToken()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        try {
            $this->corporateMailer->send(
                $emailDestinataire,
                $this->corporateMailer->buildSubject(
                    $this->translator->trans('email_soa_subject', [], 'messages', $locale),
                    (string) $client->getNom(),
                ),
                'emails/soa_lien_client.html.twig',
                [
                    'locale'          => $locale,
                    'recipientName'   => $nomDestinataire,
                    'clientNom'       => $client->getNom(),
                    'entrepriseNom'   => $entreprise?->getNom() ?: 'JS Brokers',
                    'entrepriseTel'   => $entreprise?->getTelephone(),
                    'soaUrl'          => $soaUrl,
                    'expiresAt'       => $token->getExpiresAt(),
                    'messageCourtier' => $messageCourtier !== null && trim($messageCourtier) !== '' ? trim($messageCourtier) : null,
                ],
            );

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('SOA client : échec d\'envoi du lien à {email} pour le client {client} : {msg}', [
                'email'  => $emailDestinataire,
                'client' => $client->getNom(),
                'msg'    => $e->getMessage(),
            ]);

            return false;
        }
    }
}
