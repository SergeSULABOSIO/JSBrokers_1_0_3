<?php

namespace App\Controller;

use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Event\AgentNotificationEvent;
use App\Form\OnboardingEntrepriseType;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use App\Services\ServiceProvisionEntreprise;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * Assistant d'onboarding self-service du courtier.
 *
 * Conduit un compte neuf — sans aucune intervention de l'équipe — de la création de
 * sa première entreprise jusqu'à un espace de travail amorcé et exploitable. C'est le
 * pendant côté courtier de l'automatisation CRM « 1er achat → onboarding » (équipe).
 *
 * L'aiguillage automatique vers cet assistant est fait par App\Security\AppAuthenticator
 * (utilisateur vérifié, sans entreprise possédée ni invitation) ; l'état vide de la
 * liste des entreprises y renvoie également.
 *
 * La toute première entreprise est OFFERTE : aucun token n'est débité (contrairement
 * à App\Controller\Admin\EntrepriseController::create qui débite 200 tokens pour les
 * entreprises suivantes).
 */
#[Route('/onboarding', name: 'app_onboarding.')]
#[IsGranted('ROLE_USER')]
class OnboardingController extends AbstractController
{
    public function __construct(
        private TranslatorInterface $translator,
        private ServiceProvisionEntreprise $serviceProvision,
        private EventDispatcherInterface $dispatcher,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
    ) {}

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        // Bascule de langue persistante (?lang=), même logique que l'espace authentifié.
        $lang = $request->query->get('lang');
        if (in_array($lang, ['fr', 'en'], true) && $lang !== $user->getLocale()) {
            $user->setLocale($lang);
            $localeSwitcher->setLocale($lang);
        }

        // L'assistant ne concerne QUE le tout premier pas : un utilisateur qui possède
        // déjà une entreprise ou dispose d'une invitation a un espace — on l'y renvoie.
        // On interroge la base (compte fiable, indépendant de l'état d'hydratation des
        // collections inverses) plutôt que les collections de l'entité.
        $aDejaUnEspace = $this->entrepriseRepository->count(['utilisateur' => $user]) > 0
            || $this->inviteRepository->count(['utilisateur' => $user]) > 0;
        if ($aDejaUnEspace) {
            return $this->redirectToRoute('admin.entreprise.index');
        }

        $entreprise = new Entreprise();
        $form = $this->createForm(OnboardingEntrepriseType::class, $entreprise);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Provisionnement complet (entreprise + invité propriétaire + paramètres par
            // défaut) SANS débit de token : la première entreprise est offerte.
            $invite = $this->serviceProvision->provisionner($entreprise, $user);

            $this->addFlash('success', $this->translator->trans('entreprise_created_ok', [
                ':company' => $entreprise->getNom(),
            ]));

            $this->dispatcher->dispatch(new AgentNotificationEvent(
                AgentNotificationEvent::ACTION_CREATE,
                AgentNotificationEvent::TYPE_ENTREPRISE,
                $entreprise->getNom(),
                ['Entreprise' => $entreprise->getNom(), 'Propriétaire' => $user->getNom() ?: (string) $user->getEmail()],
            ));

            // Redirection DIRECTE dans l'espace de travail fraîchement créé, avec le
            // marqueur ?welcome=1 qui déclenche le panneau d'accueil (étapes suggérées).
            return $this->redirectToRoute('app_espace_de_travail_component.index', [
                'idInvite' => $invite->getId(),
                'idEntreprise' => $entreprise->getId(),
                'welcome' => 1,
            ]);
        }

        return $this->render('onboarding/index.html.twig', [
            'utilisateur' => $user,
            'form' => $form,
        ]);
    }
}
