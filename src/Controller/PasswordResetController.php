<?php

/**
 * @file Ce fichier contient le contrôleur PasswordResetController.
 * @description Gère le parcours « mot de passe oublié / réinitialisation » :
 * 1. `request()`  : formulaire de demande (saisie de l'e-mail) → envoi du lien signé.
 * 2. `reset()`    : page atteinte via le lien e-mail → définition d'un nouveau mot de passe.
 *
 * Réutilise l'infrastructure existante (VerifyEmailBundle via {@see PasswordResetHelper},
 * e-mails de marque, pages d'auth chartées). Aucune donnée métier n'est touchée :
 * seul le champ `password` (et `verified`) de l'utilisateur est modifié.
 */

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Form\ChangePasswordFormType;
use App\Repository\UtilisateurRepository;
use App\Security\PasswordResetHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

class PasswordResetController extends AbstractController
{
    public function __construct(
        private PasswordResetHelper $passwordResetHelper,
        private string $mailFrom,
    ) {}

    #[Route('/mot-de-passe-oublie', name: 'app_forgot_password')]
    public function request(Request $request, UtilisateurRepository $utilisateurRepository): Response
    {
        // Route PUBLIQUE : on ne demande pas d'être connecté (l'utilisateur a justement
        // perdu l'accès). Soumission simple par <form method="post"> (pas de FormType :
        // un seul champ e-mail, KISS — comme le formulaire de login).
        if ($request->isMethod('POST')) {
            $email = trim((string) $request->request->get('email'));
            $user = $email !== '' ? $utilisateurRepository->findOneBy(['email' => $email]) : null;

            // Le lien n'est envoyé que si un compte existe, MAIS le message et la
            // redirection sont TOUJOURS identiques : on ne révèle pas si l'adresse
            // est connue (anti-énumération de comptes).
            if ($user instanceof Utilisateur) {
                $this->passwordResetHelper->sendPasswordResetEmail(
                    $user,
                    (new TemplatedEmail())
                        ->from(new Address($this->mailFrom, 'JS Brokers'))
                        ->to((string) $user->getEmail())
                        ->subject('JS Brokers - Réinitialisation du mot de passe - ' . $user->getEmail())
                        ->htmlTemplate('emails/reset_password_email.html.twig')
                );
            }

            $this->addFlash('success', "Si un compte est associé à cette adresse, un e-mail contenant un lien de réinitialisation vient d'être envoyé. Pensez à vérifier vos courriers indésirables.");

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/forgot_password.html.twig', [
            'pageName' => 'Mot de passe oublié',
        ]);
    }

    #[Route('/mot-de-passe/reinitialiser', name: 'app_reset_password')]
    public function reset(
        Request $request,
        UtilisateurRepository $utilisateurRepository,
        UserPasswordHasherInterface $userPasswordHasher,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
    ): Response {
        // On retrouve l'utilisateur via l'id porté par l'URL signée ; la signature
        // garantit l'authenticité (et lie le lien au mot de passe courant).
        $id = $request->query->get('id');
        $user = $id !== null ? $utilisateurRepository->find($id) : null;

        if (!$user instanceof Utilisateur) {
            $this->addFlash('error', "Ce lien de réinitialisation est invalide. Veuillez en demander un nouveau.");

            return $this->redirectToRoute('app_login');
        }

        // Validation de la signature à CHAQUE accès (GET pour afficher le formulaire,
        // POST pour appliquer) : le lien reste valable tant que le mot de passe n'a
        // pas changé. Un lien falsifié, expiré ou déjà consommé est rejeté ici.
        try {
            $this->passwordResetHelper->validateReset($request, $user);
        } catch (VerifyEmailExceptionInterface $exception) {
            $this->addFlash('error', $translator->trans($exception->getReason(), [], 'VerifyEmailBundle'));

            return $this->redirectToRoute('app_login');
        }

        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();

            $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));
            // Cliquer le lien reçu par e-mail prouve la possession de l'adresse : on en
            // profite pour débloquer un compte jamais vérifié (pas de boucle de re-vérif
            // après reset). Aucune donnée métier n'est touchée.
            $user->setVerified(true);

            $entityManager->flush();

            $this->addFlash('success', "Votre mot de passe a été réinitialisé. Vous pouvez désormais vous connecter avec votre nouveau mot de passe.");

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/reset_password.html.twig', [
            'resetForm' => $form,
            'pageName' => 'Réinitialisation du mot de passe',
        ]);
    }
}
