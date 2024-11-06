<?php

namespace App\Controller;

use App\DTO\LangueDTO;
use App\Form\LangueType;
use App\Entity\Utilisateur;
use App\Constantes\Constantes;
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
    public function index(Request $request): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        // dd($user);
        if ($user) {
            $this->localeSwitcher->setLocale($user->getLocale());
        } else {
            $this->localeSwitcher->setLocale($request->getLocale());
        }

        return $this->render('home/index.html.twig', [
            'pageName' => $this->translator->trans("security_home"),
            'user' => $user,
        ]);
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
                // return new RedirectResponse($this->urlGenerator->generate("app_user_dashbord", ['idUtilisateur' => 32]));
                return $this->redirectToRoute('admin.entreprise.index');
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
