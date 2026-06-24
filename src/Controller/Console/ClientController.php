<?php

namespace App\Controller\Console;

use App\Repository\UtilisateurRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Liste des « clients » de la plateforme : comptes en mode payant, c.-à-d.
 * disposant encore d'un solde de jetons prépayés (paidTokens > 0), par
 * opposition aux utilisateurs gratuits (cf. UtilisateurController).
 */
#[Route('/console/clients', name: 'console.client.')]
#[IsGranted('ROLE_ADMIN')]
class ClientController extends AbstractConsoleController
{
    public function __construct(private UtilisateurRepository $utilisateurRepository)
    {
    }

    #[Route('', name: 'index')]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        return $this->render('console/client/index.html.twig', [
            'pageName'     => 'Clients',
            'pageIcon'     => 'client',
            'utilisateurs' => $this->utilisateurRepository->paginateClients($request->query->getInt('page', 1)),
        ]);
    }
}
