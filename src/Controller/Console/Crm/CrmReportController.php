<?php

namespace App\Controller\Console\Crm;

use App\Controller\Console\AbstractConsoleController;
use App\Crm\CrmPipelineService;
use App\Entity\Utilisateur;
use App\Repository\Crm\CrmProfilRepository;
use App\Repository\TokenPurchaseRepository;
use App\Repository\UtilisateurRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Reporting CRM : rapports exploitables, exportables en Excel (réutilise
 * PhpSpreadsheet déjà présent). Couvre le pipeline, les clients et les tokens.
 */
#[Route('/console/crm/rapports', name: 'console.crm.rapport.')]
#[IsGranted('ROLE_ADMIN')]
class CrmReportController extends AbstractConsoleController
{
    private const RAPPORTS = [
        'clients'  => 'Clients (étape, santé, LTV)',
        'pipeline' => 'Répartition du pipeline',
        'tokens'   => 'Ventes de tokens par paquet',
    ];

    public function __construct(
        private CrmProfilRepository $profilRepository,
        private UtilisateurRepository $utilisateurRepository,
        private TokenPurchaseRepository $purchaseRepository,
        private CrmPipelineService $pipeline,
    ) {
    }

    #[Route('', name: 'index')]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        return $this->render('console/crm/rapport/index.html.twig', [
            'pageName' => 'CRM — Rapports',
            'pageIcon' => 'document',
            'rapports' => self::RAPPORTS,
        ]);
    }

    #[Route('/{type}/export', name: 'export')]
    public function export(string $type): Response
    {
        if (!isset(self::RAPPORTS[$type])) {
            throw $this->createNotFoundException('Rapport inconnu.');
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(ucfirst($type));

        [$entetes, $lignes] = $this->donnees($type);
        $sheet->fromArray($entetes, null, 'A1');
        if ($lignes !== []) {
            $sheet->fromArray($lignes, null, 'A2');
        }

        $response = new StreamedResponse(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="crm-rapport-%s.xlsx"', $type));

        return $response;
    }

    /**
     * @return array{0: string[], 1: array<int, array<int, mixed>>}
     */
    private function donnees(string $type): array
    {
        return match ($type) {
            'pipeline' => $this->donneesPipeline(),
            'tokens'   => $this->donneesTokens(),
            default    => $this->donneesClients(),
        };
    }

    private function donneesClients(): array
    {
        $clients = $this->utilisateurRepository->findAllCrm();
        $profils = $this->profilRepository->mapByUserIds(array_map(static fn (Utilisateur $u) => (int) $u->getId(), $clients));
        $metrics = $this->purchaseRepository->metricsByUsers(array_map(static fn (Utilisateur $u) => (int) $u->getId(), $clients));

        $lignes = [];
        foreach ($clients as $u) {
            $p = $profils[$u->getId()] ?? null;
            $m = $metrics[$u->getId()] ?? ['count' => 0, 'montant' => 0.0];
            $lignes[] = [
                $u->getNom(),
                $u->getEmail(),
                $p ? $this->pipeline->label($p->getEtapePipeline()) : '',
                $p?->getScoreSante() ?? 0,
                $p?->getScoreCouleur() ?? '',
                $u->getPaidTokens(),
                $m['count'],
                round($m['montant'], 2),
            ];
        }

        return [['Nom', 'E-mail', 'Étape', 'Score', 'Couleur', 'Tokens prépayés', 'Nb achats', 'LTV (USD)'], $lignes];
    }

    private function donneesPipeline(): array
    {
        $counts = $this->profilRepository->countByStage();
        $lignes = [];
        foreach ($this->pipeline->orderedStages() as $key => $meta) {
            $lignes[] = [$meta['label'], $counts[$key] ?? 0];
        }
        $lignes[] = ['Churn / Inactif', $counts[CrmPipelineService::STAGE_CHURN] ?? 0];

        return [['Étape', 'Nombre de clients'], $lignes];
    }

    private function donneesTokens(): array
    {
        $lignes = [];
        foreach ($this->purchaseRepository->groupByPack() as $row) {
            $lignes[] = [$row['pack'], $row['nb'], $row['tokens'], round($row['revenue'], 2)];
        }

        return [['Paquet', 'Nb ventes', 'Tokens', 'Revenu (USD)'], $lignes];
    }
}
