<?php

namespace App\Controller\Console;

use App\Entity\Entreprise;
use App\Event\AgentNotificationEvent;
use App\Repository\EntrepriseRepository;
use App\Services\ServiceSuppressionEntreprise;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Liste globale des entreprises de la plateforme + suppression (irréversible).
 */
#[Route('/console/entreprises', name: 'console.entreprise.')]
#[IsGranted('ROLE_ADMIN')]
class EntrepriseController extends AbstractConsoleController
{
    public function __construct(
        private EntrepriseRepository $entrepriseRepository,
        private ServiceSuppressionEntreprise $serviceSuppression,
        private EventDispatcherInterface $dispatcher,
    ) {}

    #[Route('', name: 'index')]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        return $this->render('console/entreprise/index.html.twig', [
            'pageName'    => 'Entreprises',
            'entreprises' => $this->entrepriseRepository->paginateAll($request->query->getInt('page', 1)),
        ]);
    }

    #[Route('/{id}', name: 'delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function delete(Entreprise $entreprise, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-entreprise-' . $entreprise->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $nom = $entreprise->getNom();
        $proprietaire = $entreprise->getUtilisateur();

        // Destruction totale et irréversible (données scopées + fichiers serveur).
        $this->serviceSuppression->supprimer($entreprise);

        $this->dispatcher->dispatch(new AgentNotificationEvent(
            AgentNotificationEvent::ACTION_DELETE,
            AgentNotificationEvent::TYPE_ENTREPRISE,
            $nom,
            ['Entreprise' => $nom, 'Propriétaire' => $proprietaire?->getNom() ?: '—'],
        ));
        $this->addFlash('success', sprintf('Entreprise « %s » supprimée.', $nom));

        return $this->redirectToRoute('console.entreprise.index');
    }
}
