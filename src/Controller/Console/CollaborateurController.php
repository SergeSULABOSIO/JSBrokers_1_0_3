<?php

namespace App\Controller\Console;

use App\Entity\Utilisateur;
use App\Event\AgentNotificationEvent;
use App\Form\CollaborateurType;
use App\Repository\UtilisateurRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Gestion des collaborateurs (agents) JS Brokers. La création, la suppression et
 * l'attribution du rôle super-admin sont réservées au super-admin.
 */
#[Route('/console/collaborateurs', name: 'console.collaborateur.')]
#[IsGranted('ROLE_ADMIN')]
class CollaborateurController extends AbstractConsoleController
{
    public function __construct(
        private UtilisateurRepository $utilisateurRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private EventDispatcherInterface $dispatcher,
    ) {}

    #[Route('', name: 'index')]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        return $this->render('console/collaborateur/index.html.twig', [
            'pageName'       => 'Collaborateurs JS Brokers',
            'collaborateurs' => $this->utilisateurRepository->findAgents(),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function new(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        $collaborateur = new Utilisateur();
        $form = $this->createForm(CollaborateurType::class, $collaborateur, [
            'is_edit'         => false,
            'can_grant_super' => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $collaborateur->setPassword(
                $this->passwordHasher->hashPassword($collaborateur, (string) $form->get('plainPassword')->getData())
            );
            $collaborateur->setVerified(true); // Compte interne : pas de cycle de vérification e-mail.
            $collaborateur->setRoles($form->get('superAdmin')->getData() ? ['ROLE_SUPER_ADMIN'] : ['ROLE_ADMIN']);

            $this->em->persist($collaborateur);
            $this->em->flush();

            $this->notifier(AgentNotificationEvent::ACTION_CREATE, $collaborateur);
            $this->addFlash('success', sprintf('Collaborateur « %s » créé.', $collaborateur->getNom()));

            return $this->redirectToRoute('console.collaborateur.index');
        }

        return $this->render('console/form.html.twig', [
            'pageName'    => 'Nouveau collaborateur',
            'form'        => $form,
            'backUrl'     => $this->generateUrl('console.collaborateur.index'),
            'backLabel'   => 'Collaborateurs',
            'submitLabel' => 'Créer le collaborateur',
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(Utilisateur $collaborateur, Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        $canGrantSuper = $this->isGranted('ROLE_SUPER_ADMIN');
        $form = $this->createForm(CollaborateurType::class, $collaborateur, [
            'is_edit'         => true,
            'can_grant_super' => $canGrantSuper,
            'is_super'        => in_array('ROLE_SUPER_ADMIN', $collaborateur->getRoles(), true),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plain = $form->get('plainPassword')->getData();
            if ($plain) {
                $collaborateur->setPassword($this->passwordHasher->hashPassword($collaborateur, $plain));
            }
            if ($canGrantSuper) {
                $collaborateur->setRoles($form->get('superAdmin')->getData() ? ['ROLE_SUPER_ADMIN'] : ['ROLE_ADMIN']);
            }

            $this->em->flush();

            $this->notifier(AgentNotificationEvent::ACTION_UPDATE, $collaborateur);
            $this->addFlash('success', sprintf('Collaborateur « %s » mis à jour.', $collaborateur->getNom()));

            return $this->redirectToRoute('console.collaborateur.index');
        }

        return $this->render('console/form.html.twig', [
            'pageName'    => 'Éditer ' . $collaborateur->getNom(),
            'form'        => $form,
            'backUrl'     => $this->generateUrl('console.collaborateur.index'),
            'backLabel'   => 'Collaborateurs',
            'submitLabel' => 'Enregistrer',
        ]);
    }

    #[Route('/{id}', name: 'delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function delete(Utilisateur $collaborateur, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-collaborateur-' . $collaborateur->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        // Garde-fous : on ne se supprime pas soi-même, ni le dernier super-admin.
        if ($collaborateur === $this->getUser()) {
            $this->addFlash('error', 'Vous ne pouvez pas supprimer votre propre compte.');

            return $this->redirectToRoute('console.collaborateur.index');
        }
        if (in_array('ROLE_SUPER_ADMIN', $collaborateur->getRoles(), true)
            && count($this->superAdmins()) <= 1) {
            $this->addFlash('error', 'Impossible de supprimer le dernier super-administrateur.');

            return $this->redirectToRoute('console.collaborateur.index');
        }

        $nom = $collaborateur->getNom();
        $this->em->remove($collaborateur);
        $this->em->flush();

        $this->dispatcher->dispatch(new AgentNotificationEvent(
            AgentNotificationEvent::ACTION_DELETE,
            AgentNotificationEvent::TYPE_UTILISATEUR,
            $nom,
            ['Collaborateur supprimé' => (string) $nom],
        ));
        $this->addFlash('success', sprintf('Collaborateur « %s » supprimé.', $nom));

        return $this->redirectToRoute('console.collaborateur.index');
    }

    /** @return Utilisateur[] */
    private function superAdmins(): array
    {
        return array_filter(
            $this->utilisateurRepository->findAgents(),
            static fn (Utilisateur $u) => in_array('ROLE_SUPER_ADMIN', $u->getRoles(), true)
        );
    }

    private function notifier(string $action, Utilisateur $collaborateur): void
    {
        $this->dispatcher->dispatch(new AgentNotificationEvent(
            $action,
            AgentNotificationEvent::TYPE_UTILISATEUR,
            $collaborateur->getNom() ?: (string) $collaborateur->getEmail(),
            [
                'Nom'    => (string) $collaborateur->getNom(),
                'E-mail' => (string) $collaborateur->getEmail(),
                'Rôle'   => in_array('ROLE_SUPER_ADMIN', $collaborateur->getRoles(), true) ? 'Super-administrateur' : 'Agent',
            ],
        ));
    }
}
