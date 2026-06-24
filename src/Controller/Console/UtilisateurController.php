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
 * Liste globale des utilisateurs (clients de la plateforme) + édition/suppression.
 */
#[Route('/console/utilisateurs', name: 'console.utilisateur.')]
#[IsGranted('ROLE_ADMIN')]
class UtilisateurController extends AbstractConsoleController
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

        return $this->render('console/utilisateur/index.html.twig', [
            'pageName'     => 'Utilisateurs',
            'pageIcon'     => 'utilisateur',
            'utilisateurs' => $this->utilisateurRepository->paginateRegularUsers($request->query->getInt('page', 1)),
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(Utilisateur $utilisateur, Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        $form = $this->createForm(CollaborateurType::class, $utilisateur, [
            'is_edit'         => true,
            'can_grant_super' => false,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plain = $form->get('plainPassword')->getData();
            if ($plain) {
                $utilisateur->setPassword($this->passwordHasher->hashPassword($utilisateur, $plain));
            }
            $this->em->flush();

            $this->dispatcher->dispatch(new AgentNotificationEvent(
                AgentNotificationEvent::ACTION_UPDATE,
                AgentNotificationEvent::TYPE_UTILISATEUR,
                $utilisateur->getNom() ?: (string) $utilisateur->getEmail(),
                ['Nom' => (string) $utilisateur->getNom(), 'E-mail' => (string) $utilisateur->getEmail()],
            ));
            $this->addFlash('success', sprintf('Utilisateur « %s » mis à jour.', $utilisateur->getNom()));

            return $this->redirectToRoute('console.utilisateur.index');
        }

        return $this->render('console/compte/form.html.twig', [
            'pageName'    => 'Éditer ' . $utilisateur->getNom(),
            'form'        => $form,
            'backUrl'     => $this->generateUrl('console.utilisateur.index'),
            'backLabel'   => 'Utilisateurs',
            'submitLabel' => 'Enregistrer',
            'description' => 'Modifiez les informations de ce compte client. '
                . 'Laissez le mot de passe vide pour le conserver.',
            'formIcon'    => 'utilisateur',
        ]);
    }

    #[Route('/{id}', name: 'delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function delete(Utilisateur $utilisateur, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-utilisateur-' . $utilisateur->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $nom = $utilisateur->getNom() ?: (string) $utilisateur->getEmail();
        $this->em->remove($utilisateur);
        $this->em->flush();

        $this->dispatcher->dispatch(new AgentNotificationEvent(
            AgentNotificationEvent::ACTION_DELETE,
            AgentNotificationEvent::TYPE_UTILISATEUR,
            $nom,
            ['Compte supprimé' => $nom],
        ));
        $this->addFlash('success', sprintf('Utilisateur « %s » supprimé.', $nom));

        return $this->redirectToRoute('console.utilisateur.index');
    }
}
