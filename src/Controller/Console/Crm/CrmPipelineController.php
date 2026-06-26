<?php

namespace App\Controller\Console\Crm;

use App\Controller\Console\AbstractConsoleController;
use App\Crm\CrmPipelineService;
use App\Entity\Utilisateur;
use App\Repository\Crm\CrmProfilRepository;
use App\Repository\UtilisateurRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Pipeline commercial en Kanban : une colonne par étape du cycle de vie client.
 * L'étape est dérivée automatiquement des signaux SaaS ; le commercial peut la
 * forcer par glisser-déposer (override manuel) via l'endpoint `move`.
 */
#[Route('/console/crm/pipeline', name: 'console.crm.pipeline.')]
#[IsGranted('ROLE_ADMIN')]
class CrmPipelineController extends AbstractConsoleController
{
    public function __construct(
        private CrmProfilRepository $profilRepository,
        private UtilisateurRepository $utilisateurRepository,
        private CrmPipelineService $pipeline,
    ) {
    }

    #[Route('', name: 'index')]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        // Regroupe les profils existants par étape pour le tableau Kanban.
        $colonnes = [];
        foreach (array_keys($this->pipeline->orderedStages()) as $stage) {
            $colonnes[$stage] = $this->profilRepository->findByStages([$stage], 50);
        }

        return $this->render('console/crm/pipeline/index.html.twig', [
            'pageName' => 'CRM — Pipeline',
            'pageIcon' => 'piste',
            'stages'   => $this->pipeline->orderedStages(),
            'colonnes' => $colonnes,
            'churn'    => $this->profilRepository->findByStages([CrmPipelineService::STAGE_CHURN], 50),
        ]);
    }

    /** Déplacement d'une carte (override manuel d'étape) — appelé par le Kanban. */
    #[Route('/{id}/move', name: 'move', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function move(Utilisateur $client, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('crm-pipeline-move', (string) $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false, 'error' => 'csrf'], 403);
        }

        $stage = (string) $request->request->get('etape', '');
        if (!$this->pipeline->isValidStage($stage)) {
            return new JsonResponse(['ok' => false, 'error' => 'stage'], 400);
        }

        $profil = $this->profilRepository->findForUser($client);
        if ($profil === null) {
            return new JsonResponse(['ok' => false, 'error' => 'profil'], 404);
        }

        $this->pipeline->forceStage($profil, $stage);
        $this->em->flush();

        return new JsonResponse(['ok' => true, 'etape' => $stage, 'label' => $this->pipeline->label($stage)]);
    }
}
