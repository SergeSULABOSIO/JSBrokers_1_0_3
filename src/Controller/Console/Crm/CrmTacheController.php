<?php

namespace App\Controller\Console\Crm;

use App\Controller\Console\AbstractConsoleController;
use App\Entity\Crm\CrmTache;
use App\Repository\Crm\CrmTacheRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Tâches CRM (vue globale de l'équipe) : liste des tâches ouvertes, échéances et
 * clôture. Les tâches d'un client se gèrent aussi depuis sa fiche.
 */
#[Route('/console/crm/taches', name: 'console.crm.tache.')]
#[IsGranted('ROLE_ADMIN')]
class CrmTacheController extends AbstractConsoleController
{
    public function __construct(private CrmTacheRepository $tacheRepository)
    {
    }

    #[Route('', name: 'index')]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        return $this->render('console/crm/tache/index.html.twig', [
            'pageName' => 'CRM — Tâches',
            'pageIcon' => 'tache',
            'taches'   => $this->tacheRepository->findOuvertes(null, 100),
        ]);
    }

    #[Route('/{id}/done', name: 'done', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function done(CrmTache $tache, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('crm-tache-done-' . $tache->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $tache->setStatut(CrmTache::STATUT_FAITE);
        $this->em->flush();
        $this->addFlash('success', 'Tâche marquée comme faite.');

        // Retour contextuel : si l'action vient de la fiche client, on y revient
        // (onglet Tâches). Sinon, on reste sur la liste globale des tâches.
        if ($request->request->get('_retour') === 'fiche' && $tache->getClient()) {
            return $this->redirectToRoute('console.crm.client.show', [
                'id'        => $tache->getClient()->getId(),
                '_fragment' => 'tab-taches',
            ]);
        }

        return $this->redirectToRoute('console.crm.tache.index');
    }
}
