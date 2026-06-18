<?php

namespace App\Security;

use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

class EmailVerifier
{
    public function __construct(
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private MailerInterface $mailer,
        private EntityManagerInterface $entityManager,
        private string $mailFrom,
        private string $logoPath,
    ) {
    }

    public function sendEmailConfirmation(string $verifyEmailRouteName, Utilisateur $user, TemplatedEmail $email): void
    {
        // On embarque l'id de l'utilisateur dans l'URL signée (`?id=...`). Ainsi le lien
        // de validation est CONSOMMABLE SANS être connecté : le contrôleur retrouve
        // l'utilisateur via cet id, plutôt que via la session. La signature couvre l'id
        // et l'e-mail : un id falsifié invaliderait la signature.
        $signatureComponents = $this->verifyEmailHelper->generateSignature(
            $verifyEmailRouteName,
            (string) $user->getId(),
            (string) $user->getEmail(),
            ['id' => $user->getId()]
        );

        $context = $email->getContext();
        $context['signedUrl'] = $signatureComponents->getSignedUrl();
        $context['expiresAtMessageKey'] = $signatureComponents->getExpirationMessageKey();
        $context['expiresAtMessageData'] = $signatureComponents->getExpirationMessageData();

        // Données de marque pour le layout commun (logo embarqué + signature).
        $context['logoPath'] = $this->logoPath;
        $context['senderEmail'] = $this->mailFrom;
        $context['recipientName'] = $user->getNom() ?: (string) $user->getEmail();

        $email->context($context);

        $this->mailer->send($email);
    }

    /**
     * @throws VerifyEmailExceptionInterface
     */
    public function handleEmailConfirmation(Request $request, Utilisateur $user): void
    {
        $this->verifyEmailHelper->validateEmailConfirmationFromRequest($request, (string) $user->getId(), (string) $user->getEmail());

        $user->setVerified(true);

        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }
}
