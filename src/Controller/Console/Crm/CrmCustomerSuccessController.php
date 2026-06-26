<?php

namespace App\Controller\Console\Crm;

use App\Controller\Console\AbstractConsoleController;
use App\Crm\CrmRecommendationService;
use App\Crm\CrmSyncService;
use App\Crm\ParametresCrmService;
use App\Repository\Crm\CrmProfilRepository;
use App\Repository\UtilisateurRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Tableau de bord Customer Success : santé du portefeuille, adoption, risques de
 * churn, clients inactifs et recommandations automatiques d'actions.
 */
#[Route('/console/crm/customer-success', name: 'console.crm.cs')]
#[IsGranted('ROLE_ADMIN')]
class CrmCustomerSuccessController extends AbstractConsoleController
{
    public function __construct(
        private CrmProfilRepository $profilRepository,
        private UtilisateurRepository $utilisateurRepository,
        private CrmSyncService $crmSync,
        private CrmRecommendationService $recommendations,
        private ParametresCrmService $params,
    ) {
    }

    #[Route('', name: '')]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        $aRisque = $this->profilRepository->findARisque(10);

        // Recommandations pour les comptes à risque (signaux recalculés à la volée).
        $reco = [];
        foreach ($aRisque as $profil) {
            $client = $profil->getUtilisateur();
            $reco[$client->getId()] = $this->recommendations->forClient($profil, $this->crmSync->buildSignals($client));
        }

        $cutoff = (new \DateTimeImmutable())->modify('-' . $this->params->inactiviteJours() . ' days');

        return $this->render('console/crm/customer_success.html.twig', [
            'pageName'    => 'CRM — Customer Success',
            'pageIcon'    => 'feedback',
            'sante'       => $this->profilRepository->countByHealthColor(),
            'pipeline'    => $this->profilRepository->countByStage(),
            'scoreMoyen'  => round($this->profilRepository->averageScore()),
            'total'       => $this->profilRepository->countAll(),
            'aRisque'     => $aRisque,
            'reco'        => $reco,
            'inactifs'    => $this->utilisateurRepository->findSansConnexionCrm($cutoff, 10),
        ]);
    }
}
