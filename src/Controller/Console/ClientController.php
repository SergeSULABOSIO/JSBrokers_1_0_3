<?php

namespace App\Controller\Console;

use App\Repository\ClientRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Liste globale des clients (assurés/prospects) de toutes les entreprises.
 * Lecture seule : la gestion d'un client se fait dans l'espace de son entreprise.
 */
#[Route('/console/clients', name: 'console.client.')]
#[IsGranted('ROLE_ADMIN')]
class ClientController extends AbstractConsoleController
{
    public function __construct(private ClientRepository $clientRepository)
    {
    }

    #[Route('', name: 'index')]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        return $this->render('console/client/index.html.twig', [
            'pageName' => 'Clients',
            'clients'  => $this->clientRepository->paginateAll($request->query->getInt('page', 1)),
        ]);
    }
}
