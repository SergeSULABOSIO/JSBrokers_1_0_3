<?php

namespace App\Controller\Console\Crm;

use App\Controller\Console\AbstractConsoleController;
use App\Crm\CrmHealthScoreService;
use App\Crm\ParametresCrmService;
use App\Repository\PlateformeParametresRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Paramètres CRM (super-admin) : poids du score de santé, seuils de couleur et
 * paramètres d'automatisation, éditables en BDD avec repli sur les valeurs par
 * défaut (pattern PlateformeParametres / ParametresTokenService).
 */
#[Route('/console/crm/parametres', name: 'console.crm.parametres.')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class CrmParametresController extends AbstractConsoleController
{
    public function __construct(
        private PlateformeParametresRepository $repository,
        private ParametresCrmService $params,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);
        $singleton = $this->repository->getSingleton();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('crm-parametres', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $weights = [];
            foreach (array_keys(CrmHealthScoreService::WEIGHTS) as $key) {
                $weights[$key] = max(0, (int) $request->request->get('w_' . $key, 0));
            }
            $singleton->setCrmHealthWeights($weights);

            $singleton->setCrmThresholds([
                'vert'   => (int) $request->request->get('t_vert', 75),
                'jaune'  => (int) $request->request->get('t_jaune', 50),
                'orange' => (int) $request->request->get('t_orange', 25),
            ]);

            $singleton->setCrmAutomation([
                'inactiviteJours' => max(1, (int) $request->request->get('a_inactivite', 15)),
                'soldeBas'        => max(0, (int) $request->request->get('a_soldebas', 1000)),
                'churnJours'      => max(1, (int) $request->request->get('a_churn', 45)),
            ]);

            $this->em->flush();
            $this->params->refresh();
            $this->addFlash('success', 'Paramètres CRM enregistrés.');

            return $this->redirectToRoute('console.crm.parametres.index');
        }

        return $this->render('console/crm/parametres.html.twig', [
            'pageName'   => 'CRM — Paramètres',
            'pageIcon'   => 'action:settings',
            'weights'    => $this->params->healthWeights(),
            'thresholds' => $this->params->thresholds(),
            'automation' => [
                'inactiviteJours' => $this->params->inactiviteJours(),
                'soldeBas'        => $this->params->soldeBas(),
                'churnJours'      => $this->params->churnJours(),
            ],
            'labels'     => CrmHealthScoreService::WEIGHTS,
        ]);
    }
}
