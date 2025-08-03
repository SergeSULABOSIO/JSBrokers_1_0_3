<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Form\RegistrationFormType;
use App\Security\AppAuthenticator;
use App\Security\EmailVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

class RegistrationController extends AbstractController
{
    public function __construct(private EmailVerifier $emailVerifier) {}

    #[Route('/register/{idEntreprise}', name: 'app_register')]
    public function register($idEntreprise, Request $request, UserPasswordHasherInterface $userPasswordHasher, Security $security, EntityManagerInterface $entityManager): Response
    {
        $titrePage = "Création du compte utilisateur";

        $user = new Utilisateur();
        if ($this->getUser()) {
            /** @var Utilisateur $user */
            $user = $this->getUser();
            $titrePage = "Edition de " . $user->getNom();
        }

        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();

            // encode the plain password
            $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));

            $entityManager->persist($user);
            $entityManager->flush();

            if (!$this->getUser()) {
                // generate a signed url and email it to the user
                $this->emailVerifier->sendEmailConfirmation(
                    'app_verify_email',
                    $user,
                    (new TemplatedEmail())
                        ->from(new Address('support@demo.fr', 'Support'))
                        ->to((string) $user->getEmail())
                        ->subject("JS Brokers - Confirmation d'adresse mail - " . $user->getEmail())
                        ->htmlTemplate('registration/confirmation_email.html.twig')
                );
            }
            // do anything else you need here, like send an email
            return $security->login($user, AppAuthenticator::class, 'main');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
            'pageName' => $titrePage,
        ]);
    }

    #[Route('/verify/email', name: 'app_verify_email')]
    public function verifyUserEmail(Request $request, TranslatorInterface $translator): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        // validate email confirmation link, sets User::isVerified=true and persists
        try {
            /** @var Utilisateur $user */
            $user = $this->getUser();
            $this->emailVerifier->handleEmailConfirmation($request, $user);
        } catch (VerifyEmailExceptionInterface $exception) {
            $this->addFlash('verify_email_error', $translator->trans($exception->getReason(), [], 'VerifyEmailBundle'));

            return $this->redirectToRoute('app_register');
        }

        // @TODO Change the redirect on success and handle or remove the flash message in your templates
        $this->addFlash('success', "Votre adresse mail vient d'être vérifiée et elle est bien valide. Vous pouvez maintenant travailler.");
        return $this->redirectToRoute('app_login');
    }


    #[Route('/reverify/email', name: 'app_reverify_email')]
    public function reverifyUserEmail(Security $security): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        /** @var Utilisateur $user */
        $user = $this->getUser();
        $this->emailVerifier->sendEmailConfirmation(
            'app_verify_email',
            $user,
            (new TemplatedEmail())
                ->from(new Address('support@demo.fr', 'Support'))
                ->to((string) $user->getEmail())
                ->subject("JS Brokers - Confirmation d'adresse mail - " . $user->getEmail())
                ->htmlTemplate('registration/confirmation_email.html.twig')
        );
        $this->addFlash('success', "Nous venons de vous renvoyer un email de vérification. Ouvrez cet email et cliquez sur le lien de vérification afin que nous puissions valider votre adresse mail.");
        return $security->login($user, AppAuthenticator::class, 'main');
    }
}
