<?php

/**
 * @file Ce fichier contient le contrôleur SecurityController.
 * @description Ce contrôleur gère les routes publiques et le processus d'authentification.
 * Il est responsable de :
 * 1. `index()`: Afficher la page d'accueil de l'application.
 * 2. `login()`: Afficher le formulaire de connexion et gérer les erreurs d'authentification.
 * 3. `logout()`: Gérer la déconnexion de l'utilisateur (intercepté par le pare-feu de sécurité).
 * (La langue se choisit par `?lang=` — l'ancienne route `/translate/…` est supprimée, chantier 21.)
 */

namespace App\Controller;

use App\Entity\Utilisateur;
use App\DTO\DemandeContactDTO;
use App\Form\DemandeContactType;
use App\Event\DemandeContactEvent;
use App\Service\Contact\GardeDuFormulaireDeContact;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    public function __construct(
        private TranslatorInterface $translator,
        private readonly LocaleSwitcher $localeSwitcher
    ) {}

    #[Route(path: '/', name: 'app_index')]
    public function index(Request $request, EventDispatcherInterface $dispatcher, GardeDuFormulaireDeContact $garde): Response
    {
        // Vitrine publique : la langue ne peut dépendre d'un compte connecté. On
        // l'expose via un paramètre ?lang= (fr|en), auto-suffisant pour les visiteurs
        // anonymes ; à défaut, on suit la locale courante de la requête (fr par défaut).
        // Même pattern que LegalController::terms() (DRY).
        $lang = $request->query->get('lang');
        if (!in_array($lang, ['fr', 'en'], true)) {
            $lang = in_array($request->getLocale(), ['fr', 'en'], true) ? $request->getLocale() : 'fr';
        }
        // On active la locale choisie pour que tous les « | trans » du rendu en tiennent compte.
        $this->localeSwitcher->setLocale($lang);

        // Section « Contact » de la vitrine : un visiteur (anonyme) nous laisse un message
        // et son e-mail. À la soumission, l'événement déclenche l'envoi du message à
        // l'équipe ET un accusé de réception au visiteur (cf. MailingSubscriber). On suit
        // le pattern Post/Redirect/Get pour éviter le renvoi au rafraîchissement.
        $contactForm = $this->createForm(DemandeContactType::class, new DemandeContactDTO(), [
            'action' => $this->generateUrl('app_index', ['lang' => $lang]) . '#contact',
        ]);
        $contactForm->handleRequest($request);
        if ($contactForm->isSubmitted() && $contactForm->isValid()) {
            // LE GARDE AVANT L'ENVOI : un robot reçoit la même réponse qu'un humain, mais
            // rien ne part ; un envoi au-delà des limites est refusé, et le dit.
            $verdict = $garde->verifier($request, $contactForm, $contactForm->getData());
            if ($verdict === GardeDuFormulaireDeContact::LIMITE) {
                $this->addFlash('error', $this->translator->trans('contact_trop_de_demandes'));
            } elseif ($verdict === GardeDuFormulaireDeContact::ROBOT) {
                $this->addFlash('success', $this->translator->trans('contact_email_sent_ok'));
            } else {
                try {
                    $dispatcher->dispatch(new DemandeContactEvent($contactForm->getData()));
                    $this->addFlash('success', $this->translator->trans('contact_email_sent_ok'));
                } catch (\Throwable) {
                    $this->addFlash('error', $this->translator->trans('contact_email_sent_error'));
                }
            }
            return $this->redirect($this->generateUrl('app_index', ['lang' => $lang]) . '#contact');
        }

        return $this->render('home/index.html.twig', [
            'pageName'    => $this->translator->trans("Accueil"),
            'lang'        => $lang,
            'contactForm' => $contactForm,
        ]);
    }

    // L'ancienne bascule de langue `/translate/{locale}/{currentURL}` a été SUPPRIMÉE (chantier
    // 21) : elle redirigeait vers n'importe quelle adresse reçue (redirection ouverte) et n'était
    // plus reliée à rien. La langue se choisit par `?lang=` (components/_lang_switch.html.twig).

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
