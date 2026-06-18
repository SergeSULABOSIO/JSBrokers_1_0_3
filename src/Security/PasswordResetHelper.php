<?php

namespace App\Security;

use App\Entity\Utilisateur;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\Model\VerifyEmailSignatureComponents;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

/**
 * Génère et valide les liens de réinitialisation de mot de passe.
 *
 * Décalque volontairement {@see EmailVerifier} : on réutilise VerifyEmailBundle
 * (URL signée portant `?id=...`, AUCUN jeton stocké en base, aucune migration).
 * Le lien est ainsi CONSOMMABLE SANS être connecté — c'est tout l'intérêt d'une
 * récupération de mot de passe : l'utilisateur a, par définition, perdu l'accès.
 *
 * Particularité « usage unique » : la composante d'identité signée embarque le
 * HASH DU MOT DE PASSE COURANT. Dès que le mot de passe change (donc dès qu'un
 * reset aboutit), la signature ne correspond plus → tout ancien lien devient
 * invalide, en plus de l'expiration naturelle gérée par le bundle.
 */
class PasswordResetHelper
{
    public function __construct(
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private MailerInterface $mailer,
        private string $mailFrom,
        private string $logoPath,
    ) {
    }

    /**
     * Identité signée : e-mail + hash du mot de passe courant. Le hash lie le lien
     * à l'état actuel du compte ; il change au prochain reset → ancien lien mort.
     */
    private function signatureIdentity(Utilisateur $user): string
    {
        return (string) $user->getEmail() . '|' . (string) $user->getPassword();
    }

    /**
     * Génère l'URL signée de réinitialisation pour cet utilisateur. Exposé pour être
     * réutilisé par {@see sendPasswordResetEmail()} et exercé dans les tests sans
     * dépendre du transport e-mail.
     */
    public function generateResetSignature(Utilisateur $user): VerifyEmailSignatureComponents
    {
        return $this->verifyEmailHelper->generateSignature(
            'app_reset_password',
            (string) $user->getId(),
            $this->signatureIdentity($user),
            ['id' => $user->getId()]
        );
    }

    public function sendPasswordResetEmail(Utilisateur $user, TemplatedEmail $email): void
    {
        $signatureComponents = $this->generateResetSignature($user);

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
     * Valide la signature du lien reçu par e-mail. À appeler AVANT de modifier le
     * mot de passe (l'identité signée dépend du hash courant).
     *
     * @throws VerifyEmailExceptionInterface si le lien est falsifié, expiré ou déjà consommé
     */
    public function validateReset(Request $request, Utilisateur $user): void
    {
        $this->verifyEmailHelper->validateEmailConfirmationFromRequest(
            $request,
            (string) $user->getId(),
            $this->signatureIdentity($user)
        );
    }
}
