<?php

/**
 * @file Ce fichier contient le contrôleur RegistrationController.
 * @description Ce contrôleur gère tout le cycle de vie de l'enregistrement et de la vérification d'un utilisateur.
 * Il est responsable de :
 * 1. `register()`: Afficher le formulaire d'inscription pour un nouvel utilisateur ou le formulaire d'édition de profil pour un utilisateur connecté (utilisé par la fonctionnalité "Mon Compte").
 * 2. `verifyUserEmail()`: Gérer le lien de confirmation envoyé par email pour valider l'adresse de l'utilisateur.
 * 3. `reverifyUserEmail()`: Permettre à un utilisateur connecté de demander un nouvel email de vérification.
 * Il utilise le service `EmailVerifier` pour la logique de confirmation par email.
 */

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Event\AgentNotificationEvent;
use App\Form\RegistrationFormType;
use App\Legal\Cgu;
use App\Security\EmailVerifier;
use App\Repository\UtilisateurRepository;
use App\Services\InvitationLinker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

class RegistrationController extends AbstractController
{
    public function __construct(
        private EmailVerifier $emailVerifier,
        private InvitationLinker $invitationLinker,
        private string $mailFrom,
        private EventDispatcherInterface $dispatcher,
    ) {}

    #[Route('/register', name: 'app_register')]
    public function register(Request $request, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager): Response
    {
        $titrePage = "Création du compte utilisateur";

        // AMÉLIORATION : Logique clarifiée pour la création vs l'édition.
        $isEditMode = false;
        if ($this->getUser()) {
            /** @var Utilisateur $user */
            $user = $this->getUser();
            $titrePage = "Edition de " . $user->getNom();
            $isEditMode = true;
        } else {
            $user = new Utilisateur();
            // Pré-remplissage de l'email lorsqu'on arrive depuis un lien d'invitation
            // (MailingSubscriber ajoute ?email=... à l'URL d'inscription).
            $emailInvite = $request->query->get('email');
            if ($emailInvite) {
                $user->setEmail($emailInvite);
            }
        }

        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // AMÉLIORATION : Le mot de passe n'est encodé que s'il a été fourni.
            // Cela permet à un utilisateur de modifier son profil sans changer son mot de passe.
            $plainPassword = $form->get('plainPassword')->getData();
            if ($plainPassword) {
                $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));
            }

            // Création de compte : on conserve la preuve d'acceptation des CGU
            // (version acceptée + date). En édition de profil, on n'y touche pas.
            if (!$isEditMode) {
                $user->setCguAcceptedVersion(Cgu::VERSION);
                $user->setCguAcceptedAt(new \DateTimeImmutable());
            }

            $entityManager->persist($user);
            $entityManager->flush();

            // AMÉLIORATION : L'email de confirmation n'est envoyé qu'en mode création.
            if (!$isEditMode) {
                // Notifie l'équipe JS Brokers de la création d'un nouveau compte.
                $this->dispatcher->dispatch(new AgentNotificationEvent(
                    AgentNotificationEvent::ACTION_CREATE,
                    AgentNotificationEvent::TYPE_UTILISATEUR,
                    $user->getNom() ?: (string) $user->getEmail(),
                    ['Nom' => (string) $user->getNom(), 'E-mail' => (string) $user->getEmail()],
                ));

                // Rattachement automatique des invitations en attente portant cet email :
                // l'entreprise invitante apparaîtra dans la liste dès la première connexion.
                $this->invitationLinker->linkPendingInvitations($user);

                // generate a signed url and email it to the user
                $this->emailVerifier->sendEmailConfirmation(
                    'app_verify_email',
                    $user,
                    (new TemplatedEmail())
                        ->from(new Address($this->mailFrom, 'JS Brokers'))
                        ->to((string) $user->getEmail())
                        ->subject("JS Brokers - Confirmation d'adresse mail - " . $user->getEmail())
                        ->htmlTemplate('registration/confirmation_email.html.twig')
                );

                // On NE connecte PAS automatiquement : le compte n'est pas encore
                // vérifié, et AppAuthenticator renverrait alors en boucle vers la
                // re-vérification. On renvoie vers la connexion avec une invitation
                // claire à consulter sa boîte mail.
                $this->addFlash('success', sprintf(
                    "Votre compte a bien été créé. Un email de confirmation vient d'être envoyé à %s : ouvrez-le et cliquez sur le lien de validation pour activer votre compte.",
                    $user->getEmail()
                ));
                return $this->redirectToRoute('app_login');
            }
            $this->addFlash('success', 'Votre profil a été mis à jour avec succès.');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
            'pageName' => $titrePage,
        ]);
    }

    #[Route('/verify/email', name: 'app_verify_email')]
    public function verifyUserEmail(Request $request, TranslatorInterface $translator, UtilisateurRepository $utilisateurRepository): Response
    {
        // Route PUBLIQUE : on NE demande PAS d'être connecté. Le lien reçu par e-mail
        // doit pouvoir être cliqué directement, sans session (sinon le clic rebondit
        // vers la page de connexion, ce qui est déroutant). On retrouve l'utilisateur
        // via l'id porté par l'URL signée ; la signature garantit l'authenticité.
        $id = $request->query->get('id');
        if ($id === null) {
            return $this->redirectToRoute('app_register');
        }

        $user = $utilisateurRepository->find($id);
        if ($user === null) {
            return $this->redirectToRoute('app_register');
        }

        // Valide la signature du lien, puis pose User::isVerified = true et persiste.
        try {
            $this->emailVerifier->handleEmailConfirmation($request, $user);
        } catch (VerifyEmailExceptionInterface $exception) {
            $this->addFlash('verify_email_error', $translator->trans($exception->getReason(), [], 'VerifyEmailBundle'));

            return $this->redirectToRoute('app_login');
        }

        // Vérification réussie → on renvoie vers la connexion. À la connexion,
        // AppAuthenticator rattache les invitations en attente et ouvre l'espace
        // (liste des entreprises de l'utilisateur + celles auxquelles il est invité).
        $this->addFlash('success', "Votre adresse e-mail est vérifiée. Connectez-vous pour accéder à votre espace.");
        return $this->redirectToRoute('app_login');
    }


    #[Route('/reverify/email', name: 'app_reverify_email')]
    public function reverifyUserEmail(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        /** @var Utilisateur $user */
        $user = $this->getUser();

        // Déjà vérifié : il n'y a plus rien à faire ici, on rejoint l'espace applicatif.
        if ($user->isVerified()) {
            return $this->redirectToRoute('admin.entreprise.index');
        }

        // IMPORTANT : on NE ré-authentifie PAS l'utilisateur ici (l'ancien
        // `$security->login(...)` ré-exécutait AppAuthenticator::onAuthenticationSuccess,
        // qui — l'utilisateur n'étant pas vérifié — renvoyait vers cette même page :
        // d'où une boucle de redirection infinie (ERR_TOO_MANY_REDIRECTS) qui renvoyait
        // en prime un e-mail à chaque tour). L'utilisateur est déjà authentifié
        // (denyAccessUnlessGranted ci-dessus) : cette page est une simple impasse 200.
        //
        // L'e-mail de validation n'est renvoyé que sur action explicite (clic sur
        // « Renvoyer le lien »), et non à chaque affichage : cette page est la cible de
        // redirection du EmailVerificationSubscriber et peut donc être atteinte souvent.
        // Après envoi, on repasse en GET « nu » (motif PRG) pour qu'un rafraîchissement
        // ne déclenche pas un nouvel envoi.
        if ($request->query->getBoolean('send')) {
            $this->emailVerifier->sendEmailConfirmation(
                'app_verify_email',
                $user,
                (new TemplatedEmail())
                    ->from(new Address($this->mailFrom, 'JS Brokers'))
                    ->to((string) $user->getEmail())
                    ->subject("JS Brokers - Confirmation d'adresse mail - " . $user->getEmail())
                    ->htmlTemplate('registration/confirmation_email.html.twig')
            );
            $this->addFlash('success', "Nous venons de vous renvoyer un email de vérification. Ouvrez-le et cliquez sur le lien de validation pour activer votre compte.");

            return $this->redirectToRoute('app_reverify_email');
        }

        return $this->render('registration/reverify_email.html.twig', [
            'pageName' => "Vérification de votre adresse e-mail",
        ]);
    }
}
