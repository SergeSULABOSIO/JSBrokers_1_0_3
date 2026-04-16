<?php

/**
 * @file Ce fichier contient le contrôleur SecurityController.
 * @description Ce contrôleur gère les routes publiques et le processus d'authentification.
 * Il est responsable de :
 * 1. `index()`: Afficher la page d'accueil de l'application.
 * 2. `login()`: Afficher le formulaire de connexion et gérer les erreurs d'authentification.
 * 3. `logout()`: Gérer la déconnexion de l'utilisateur (intercepté par le pare-feu de sécurité).
 * 4. `translateApp()`: Gérer le changement de langue pour l'utilisateur.
 */

namespace App\Controller;

use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $manager,
        private TranslatorInterface $translator,
        private readonly LocaleSwitcher $localeSwitcher
    ) {}

    #[Route(path: '/', name: 'app_index')]
    public function index(AuthenticationUtils $authenticationUtils): Response
    {
        // AMÉLIORATION : Si l'utilisateur est déjà connecté, on le redirige vers sa liste d'entreprises.
        if ($this->getUser()) {
            return $this->redirectToRoute('admin.entreprise.index');
        }

        // Sinon, on le redirige vers la page de connexion.
        return $this->redirectToRoute('app_login');
    }

    #[Route(path: '/translate/{locale}/{currentURL}', name: 'app_translate', requirements: ['currentURL' => '.+'])]
    public function translateApp(Request $request, $locale, $currentURL): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        if ($user) {
            $user->setlocale($locale);
            $this->manager->persist($user);
            $this->manager->flush();

            $this->localeSwitcher->setLocale($user->getLocale());
        } else {
            $this->localeSwitcher->setLocale($request->getLocale());
        }
        return $this->redirect($currentURL);
    }

    #[Route(path: '/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // dd($this->getUser());
        if ($this->getUser()) {
            /** @var Utilisateur $user */
            $user = $this->getUser();
            if ($user->isVerified()) {
                return $this->redirectToRoute('admin.entreprise.index');
            } else {
                // AMÉLIORATION : Si l'utilisateur est connecté mais non vérifié, on le redirige
                // vers la page qui lui permet de renvoyer l'email de vérification.
                return $this->redirectToRoute('app_reverify_email');
            }
        }

        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();
        // last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render(
            'security/login.html.twig',
            [
                'last_username' => $lastUsername,
                'error' => $error,
                'pageName' => "Authentification"
            ]
        );
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
