<?php

namespace App\Controller\Console;

use App\Entity\TokenPurchase;
use App\Repository\TokenPurchaseRepository;
use App\Token\ParametresTokenService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

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
        $filtres = [
            'from'   => $request->query->get('from'),
            'to'     => $request->query->get('to'),
            'pack'   => $request->query->get('pack'),
            'status' => $request->query->get('status'),
            'q'      => $request->query->get('q'),
        ];

        return $this->render('console/vente/index.html.twig', [
            'pageName' => 'Ventes de tokens',
            'ventes'   => $this->purchaseRepository->paginateFiltered($filtres, $request->query->getInt('page', 1)),
            'totals'   => $this->purchaseRepository->totals($filtres),
            'parPack'  => $this->purchaseRepository->groupByPack($filtres),
            'filtres'  => $filtres,
            'packs'    => array_keys($this->parametres->packs()),
            'statuses' => [TokenPurchase::STATUS_PAID_SIMULATED],
        ]);
    }
}
