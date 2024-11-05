<?php

namespace App\Controller;

use App\Constantes\Constantes;
use App\DTO\LangueDTO;
use App\Entity\Utilisateur;
use App\Form\LangueType;
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
        private TranslatorInterface $translator,
        private readonly LocaleSwitcher $localeSwitcher
    ) {}

    #[Route(path: '/', name: 'app_index')]
    public function index(Request $request): Response
    {
        // dd($request->getLocale());
        if ($this->getUser()) {
            // $welcome_message = $this->translator->trans("security_welcome_to_jsbrokers");
            // $this->addFlash("success", $welcome_message);
            /** @var Utilisateur $user */
            $user = $this->getUser();
            $this->localeSwitcher->setLocale($user->getLocale());
        }else{
            $this->localeSwitcher->setLocale($request->getLocale());
        }


        return $this->render('home/index.html.twig', [
            'pageName' => $this->translator->trans("security_home"),
            // 'languageForm' => $languageForm,
        ]);
    }

    #[Route(path: '/translate/{locale}', name: 'app_translate')]
    public function translateApp(Request $request): Response
    {
        // dd($request->getLocale());
        if ($this->getUser()) {
            // $welcome_message = $this->translator->trans("security_welcome_to_jsbrokers");
            // $this->addFlash("success", $welcome_message);
            /** @var Utilisateur $user */
            $user = $this->getUser();
            $this->localeSwitcher->setLocale($user->getLocale());
        }else{
            $this->localeSwitcher->setLocale($request->getLocale());
        }


        return $this->render('home/index.html.twig', [
            'pageName' => $this->translator->trans("security_home"),
            // 'languageForm' => $languageForm,
        ]);
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
