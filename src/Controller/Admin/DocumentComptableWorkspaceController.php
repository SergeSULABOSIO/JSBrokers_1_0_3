<?php

namespace App\Controller\Admin;

use App\Comptabilite\ComptaExportService;
use App\Comptabilite\CourtierEcritureComptableService;
use App\Comptabilite\CourtierSuiviFiscalService;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Repository\EntrepriseRepository;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Token\InsufficientTokensException;
use App\Token\TokenAccountService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @file Documents comptables OHADA du courtier (espace de travail).
 * @description Rend le composant « Documents comptables » du workspace : les sept
 * états SYSCOHADA (journal, grand livre, balance, résultat, TFR, bilan, TFT)
 * générés à la volée depuis les opérations de l'entreprise
 * (CourtierEcritureComptableService), plus le suivi fiscal des taxes
 * (CourtierSuiviFiscalService), avec export Excel (unitaire ou classeur complet).
 *
 * Sécurité : forwardToComponent ne valide que l'accès au workspace — ce contrôleur
 * re-vérifie lui-même le périmètre (fail-closed) via WorkspaceAccessResolver sur la
 * pseudo-entité « DocumentComptable » (RolesEnFinance::accessDocumentComptable).
 * Le niveau LECTURE suffit pour consulter ET exporter (décision produit).
 *
 * Tokens : chaque consultation et chaque export consomment des tokens (métrage
 * lecture, pseudo-entité « DocumentComptable »), comme toute lecture d'entité.
 */
#[Route('/admin/document-comptable', name: 'admin.documentcomptable.')]
#[IsGranted('ROLE_USER')]
class DocumentComptableWorkspaceController extends AbstractController
{
    /** Nom court de la pseudo-entité gouvernant l'accès et le métrage. */
    private const ENTITY_SHORT_NAME = 'DocumentComptable';

    public function __construct(
        private CourtierEcritureComptableService $courtierComptabilite,
        private CourtierSuiviFiscalService $suiviFiscal,
        private ComptaExportService $exportService,
        private EntrepriseRepository $entrepriseRepository,
        private WorkspaceAccessResolver $accessResolver,
        private TokenAccountService $tokenAccountService,
    ) {
    }

    /**
     * Rend le composant dans l'espace de travail. Atteint par le « Cerveau » via
     * forwardToComponent (chargement initial) puis rechargé en AJAX par le
     * contrôleur Stimulus `document-comptable` (changement d'onglet / d'exercice).
     */
    #[Route('/workspace/{idEntreprise}', name: 'workspace', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function loadWorkspaceComponent(int $idEntreprise, Request $request): Response
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);

        // Fail-closed : lecture du périmètre Finances requise (le menu n'est que cosmétique).
        if ($invite === null || !$this->accessResolver->canRead($invite, self::ENTITY_SHORT_NAME)) {
            return $this->render('components/_access_denied.html.twig', [
                'entiteNom' => 'Documents comptables',
            ]);
        }

        // MÉTRAGE TOKENS (lecture) : une consultation = une unité de lecture.
        try {
            $this->tokenAccountService->meterRead(self::ENTITY_SHORT_NAME, 1, $entreprise, $this->getUser());
        } catch (InsufficientTokensException $e) {
            return $this->render('components/_tokens_blocked.html.twig', [
                'nextRenewalAt' => $e->nextRenewalAt,
                'required'      => $e->required,
                'available'     => $e->available,
            ]);
        }

        $exercices = $this->courtierComptabilite->exercicesDisponibles($entreprise);
        $exercice  = $request->query->getInt('exercice') ?: $exercices[0];

        $docs = ComptaExportService::DOCUMENTS + [ComptaExportService::DOC_SUIVI_FISCAL => ComptaExportService::SUIVI_FISCAL_LABEL];
        $doc  = (string) $request->query->get('doc', 'journal');
        if (!isset($docs[$doc])) {
            $doc = 'journal';
        }

        return $this->render('components/_document_comptable_component.html.twig', [
            'documents'   => $this->courtierComptabilite->documents($entreprise, $exercice),
            'suiviFiscal' => $doc === ComptaExportService::DOC_SUIVI_FISCAL ? $this->suiviFiscal->suivi($entreprise, $exercice) : null,
            'docs'        => $docs,
            'docActif'    => $doc,
            'exercice'    => $exercice,
            'exercices'   => $exercices,
            'idEntreprise' => $idEntreprise,
        ]);
    }

    /**
     * Export Excel : un document (`doc` ∈ DOCUMENTS ∪ {suivi-fiscal}) ou le classeur
     * complet (`doc=all`, 8 onglets). Téléchargement GET direct.
     */
    #[Route('/export/{idEntreprise}', name: 'export', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET'])]
    public function exportXlsx(int $idEntreprise, Request $request): Response
    {
        [$entreprise, $invite] = $this->resolveWorkspace($idEntreprise);

        if ($invite === null || !$this->accessResolver->canRead($invite, self::ENTITY_SHORT_NAME)) {
            throw $this->createAccessDeniedException("Les documents comptables sont hors de votre périmètre d'accès.");
        }

        $exercices = $this->courtierComptabilite->exercicesDisponibles($entreprise);
        $exercice  = $request->query->getInt('exercice') ?: $exercices[0];
        $doc       = (string) $request->query->get('doc', 'journal');

        // MÉTRAGE TOKENS (lecture) : une unité par document exporté (8 pour le classeur).
        $unites = $doc === 'all' ? count(ComptaExportService::DOCUMENTS) + 1 : 1;
        try {
            $this->tokenAccountService->meterRead(self::ENTITY_SHORT_NAME, $unites, $entreprise, $this->getUser());
        } catch (InsufficientTokensException $e) {
            return new Response(
                "Quota de tokens épuisé : rechargez votre solde ou attendez le renouvellement de votre allocation gratuite.",
                Response::HTTP_PAYMENT_REQUIRED,
            );
        }

        return $this->exportService->exportDocuments(
            $this->courtierComptabilite->documents($entreprise, $exercice),
            $doc,
            $entreprise->getNom() ?? 'Entreprise',
            $this->suiviFiscal->suivi($entreprise, $exercice),
        );
    }

    /**
     * Charge l'entreprise (404 sinon) et résout l'invité connecté, en refusant tout
     * invité rattaché à une AUTRE entreprise que celle demandée.
     *
     * @return array{0: Entreprise, 1: ?Invite}
     */
    private function resolveWorkspace(int $idEntreprise): array
    {
        $entreprise = $this->entrepriseRepository->find($idEntreprise);
        if (!$entreprise instanceof Entreprise) {
            throw $this->createNotFoundException('Entreprise introuvable.');
        }

        /** @var Utilisateur $user */
        $user = $this->getUser();
        $invite = $this->accessResolver->resolveConnectedInvite($user);
        if ($invite !== null && $invite->getEntreprise()?->getId() !== $entreprise->getId()) {
            $invite = null;
        }

        return [$entreprise, $invite];
    }
}
