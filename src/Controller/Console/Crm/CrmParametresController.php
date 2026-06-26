<?php

namespace App\Controller\Console\Crm;

use App\Controller\Console\AbstractConsoleController;
use App\Crm\CrmHealthScoreService;
use App\Crm\ParametresCrmService;
use App\Form\CrmParametresType;
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

        $form = $this->createForm(CrmParametresType::class, null, [
            'weights'    => $this->params->healthWeights(),
            'thresholds' => $this->params->thresholds(),
            'automation' => [
                'inactiviteJours' => $this->params->inactiviteJours(),
                'soldeBas'        => $this->params->soldeBas(),
                'churnJours'      => $this->params->churnJours(),
            ],
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $weights = [];
            foreach (array_keys(CrmHealthScoreService::WEIGHTS) as $key) {
                $weights[$key] = max(0, (int) $form->get('w_' . $key)->getData());
            }
            $singleton->setCrmHealthWeights($weights);

            $singleton->setCrmThresholds([
                'vert'   => (int) $form->get('t_vert')->getData(),
                'jaune'  => (int) $form->get('t_jaune')->getData(),
                'orange' => (int) $form->get('t_orange')->getData(),
            ]);

            $singleton->setCrmAutomation([
                'inactiviteJours' => max(1, (int) $form->get('a_inactivite')->getData()),
                'soldeBas'        => max(0, (int) $form->get('a_soldebas')->getData()),
                'churnJours'      => max(1, (int) $form->get('a_churn')->getData()),
            ]);

            $this->em->flush();
            $this->params->refresh();
            $this->addFlash('success', 'Paramètres CRM enregistrés.');

            return $this->redirectToRoute('console.crm.parametres.index');
        }

        return $this->render('console/crm/parametres_form.html.twig', [
            'pageName'    => 'CRM — Paramètres',
            'formIcon'    => 'action:settings',
            'form'        => $form,
            'backUrl'     => $this->generateUrl('console.crm.dashboard'),
            'backLabel'   => 'CRM',
            'submitLabel' => 'Enregistrer les paramètres',
            'description' => 'Poids du score de santé, seuils de couleur et paramètres d\'automatisation. Valeurs vides = valeurs par défaut.',
        ]);
    }
}
