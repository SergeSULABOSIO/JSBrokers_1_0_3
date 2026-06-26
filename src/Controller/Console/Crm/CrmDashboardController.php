<?php

namespace App\Controller\Console\Crm;

use App\Controller\Console\AbstractConsoleController;
use App\Crm\CrmPipelineService;
use App\Repository\Crm\CrmProfilRepository;
use App\Repository\Crm\CrmTacheRepository;
use App\Repository\UtilisateurRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Tableau de bord CRM (vue commerciale) : ce que l'agent voit chaque matin.
 * Listes alimentées depuis les données SaaS déjà synchronisées (profils CRM,
 * connexions, soldes) — aucune ressaisie.
 */
#[Route('/console/crm', name: 'console.crm.dashboard')]
#[IsGranted('ROLE_ADMIN')]
class CrmDashboardController extends AbstractConsoleController
{
    /** Seuil (jours) sans connexion pour la relance d'inactivité. */
    private const INACTIVITE_JOURS = 15;
    /** Seuil de solde prépayé considéré comme « presque à court ». */
    private const SOLDE_BAS = 1000;

    public function __construct(
        private CrmProfilRepository $profilRepository,
        private UtilisateurRepository $utilisateurRepository,
        private CrmTacheRepository $tacheRepository,
    ) {
    }

    #[Route('', name: '')]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        $cutoff = (new \DateTimeImmutable())->modify('-' . self::INACTIVITE_JOURS . ' days');

        return $this->render('console/crm/dashboard.html.twig', [
            'pageName'       => 'CRM — Tableau de bord',
            'pageIcon'       => 'dashboard',
            'aRelancer'      => $this->profilRepository->findARelancer(10),
            'prospectsChauds' => $this->profilRepository->findByStages([
                CrmPipelineService::STAGE_ESSAI,
                CrmPipelineService::STAGE_DEMO,
                CrmPipelineService::STAGE_QUALIFICATION,
            ], 10),
            'sansConnexion'  => $this->utilisateurRepository->findSansConnexionCrm($cutoff, 10),
            'presqueCourt'   => $this->utilisateurRepository->findPresqueCourtCrm(self::SOLDE_BAS, 10),
            'taches'         => $this->tacheRepository->findOuvertes(null, 10),
            'sante'          => $this->profilRepository->countByHealthColor(),
            'pipeline'       => $this->profilRepository->countByStage(),
            'inactiviteJours' => self::INACTIVITE_JOURS,
        ]);
    }
}
