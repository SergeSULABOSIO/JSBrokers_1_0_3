<?php

namespace App\Controller\Console;

use App\Entity\TokenPurchase;
use App\Repository\TokenPurchaseRepository;
use App\Services\TokenInvoicePdfService;
use App\Token\ParametresTokenService;
use App\Token\TokenPurchaseFulfillmentService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Ventes (achats de tokens) : liste filtrable + volumes agrégés.
 */
#[Route('/console/ventes', name: 'console.vente.')]
#[IsGranted('ROLE_ADMIN')]
class VenteController extends AbstractConsoleController
{
    public function __construct(
        private TokenPurchaseRepository $purchaseRepository,
        private ParametresTokenService $parametres,
    ) {}

    #[Route('', name: 'index')]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        // Filtres lus depuis la query string (formulaire GET, sans JS).
        // Par défaut (aucun paramètre en query), les bornes de dates pointent sur
        // le jour en cours. Une valeur vide explicite ('') reste respectée (l'agent
        // a volontairement vidé le champ avant de filtrer).
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $filtres = [
            'from'   => $request->query->get('from', $today),
            'to'     => $request->query->get('to', $today),
            'pack'   => $request->query->get('pack'),
            'status' => $request->query->get('status'),
            'q'      => $request->query->get('q'),
        ];

        return $this->render('console/vente/index.html.twig', [
            'pageName' => 'Ventes de tokens',
            'pageIcon' => 'operation',
            'ventes'   => $this->purchaseRepository->paginateFiltered($filtres, $request->query->getInt('page', 1)),
            'totals'   => $this->purchaseRepository->totals($filtres),
            'parPack'  => $this->purchaseRepository->groupByPack($filtres),
            'filtres'  => $filtres,
            'packs'    => array_keys($this->parametres->packs()),
            'statuses' => [
                TokenPurchase::STATUS_PAID,
                TokenPurchase::STATUS_PENDING,
                TokenPurchase::STATUS_FAILED,
                TokenPurchase::STATUS_REFUNDED,
                TokenPurchase::STATUS_PAID_SIMULATED,
            ],
        ]);
    }

    /**
     * Remboursement manuel d'un achat encaissé : déclenche le chemin idempotent
     * (statut REFUNDED + reprise des tokens + e-mail d'avoir). Sans effet si
     * l'achat n'est pas encaissé ou déjà remboursé.
     */
    #[Route('/{id}/rembourser', name: 'refund', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function refund(
        TokenPurchase $purchase,
        Request $request,
        TokenPurchaseFulfillmentService $fulfillment,
        TranslatorInterface $translator,
    ): Response {
        if (!$this->isCsrfTokenValid('refund-vente-' . $purchase->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', $translator->trans('vente.refund.impossible'));

            return $this->redirectToRoute('console.vente.index');
        }

        $done = $fulfillment->refund($purchase, 'console.manual_refund');
        $this->addFlash($done ? 'success' : 'error', $translator->trans($done ? 'vente.refund.success' : 'vente.refund.impossible'));

        return $this->redirectToRoute('console.vente.index');
    }

    /**
     * Consultation par un agent JS Brokers de la facture / avoir effectivement
     * mise à disposition du client (même PDF). Disponible dès qu'un numéro de
     * facture a été émis (achat encaissé, même remboursé ⇒ avoir).
     */
    #[Route('/{id}/facture', name: 'invoice', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function invoice(TokenPurchase $purchase, TokenInvoicePdfService $pdfService): Response
    {
        if ($purchase->getInvoiceNumber() === null) {
            throw $this->createNotFoundException();
        }

        return new Response($pdfService->generate($purchase), Response::HTTP_OK, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $pdfService->fileName($purchase) . '"',
        ]);
    }
}
