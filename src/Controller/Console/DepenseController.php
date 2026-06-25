<?php

namespace App\Controller\Console;

use App\Entity\Charge;
use App\Entity\Depense;
use App\Form\DepenseType;
use App\Repository\ChargeRepository;
use App\Repository\DepenseRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Gestion des dépenses (sorties de fonds) de JS Brokers : liste filtrable avec
 * agrégats (sur le modèle des Ventes) ET saisie/édition/suppression (les dépenses
 * sont enregistrées, contrairement aux ventes issues des achats de tokens).
 */
#[Route('/console/depenses', name: 'console.depense.')]
#[IsGranted('ROLE_ADMIN')]
class DepenseController extends AbstractConsoleController
{
    public function __construct(
        private DepenseRepository $depenseRepository,
        private ChargeRepository $chargeRepository,
    ) {
    }

    #[Route('', name: 'index')]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        // Filtres lus depuis la query string (GET, sans JS) — même approche que les
        // Ventes : au chargement, la période pointe sur le jour courant (et non vide).
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $filtres = [
            'from'        => $request->query->get('from', $today),
            'to'          => $request->query->get('to', $today),
            'charge'      => $request->query->get('charge'),
            'statut'      => $request->query->get('statut'),
            'destination' => $request->query->get('destination'),
            'q'           => $request->query->get('q'),
        ];

        return $this->render('console/depense/index.html.twig', [
            'pageName'     => 'Dépenses',
            'pageIcon'     => 'depense',
            'depenses'     => $this->depenseRepository->paginateFiltered($filtres, $request->query->getInt('page', 1)),
            'totals'       => $this->depenseRepository->totals($filtres),
            'parCharge'    => $this->depenseRepository->groupByCharge($filtres),
            'filtres'      => $filtres,
            'charges'      => $this->chargeRepository->findActives(),
            'statuts'      => Depense::STATUTS,
            'destinations' => Charge::DESTINATIONS,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        $depense = new Depense();
        $depense->setDateDepense(new \DateTimeImmutable('today'));

        return $this->traiter(
            $depense,
            $request,
            'Nouvelle dépense',
            'Enregistrer la dépense',
            true,
            'Enregistrez une sortie de fonds et rattachez-la à un type de charge.'
        );
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(Depense $depense, Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        return $this->traiter(
            $depense,
            $request,
            'Éditer la dépense',
            'Enregistrer',
            false,
            'Modifiez les informations de cette dépense.'
        );
    }

    #[Route('/{id}', name: 'delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function delete(Depense $depense, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-depense-' . $depense->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $this->em->remove($depense);
        $this->em->flush();

        $this->addFlash('success', 'Dépense supprimée.');

        return $this->redirectToRoute('console.depense.index');
    }

    /** Traitement partagé création/édition (DRY). */
    private function traiter(Depense $depense, Request $request, string $pageName, string $submitLabel, bool $isNew, string $description = ''): Response
    {
        $form = $this->createForm(DepenseType::class, $depense);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($isNew) {
                $this->em->persist($depense);
            }
            $this->em->flush();

            $this->addFlash('success', 'Dépense enregistrée.');

            return $this->redirectToRoute('console.depense.index');
        }

        return $this->render('console/depense/form.html.twig', [
            'pageName'    => $pageName,
            'form'        => $form,
            'backUrl'     => $this->generateUrl('console.depense.index'),
            'backLabel'   => 'Dépenses',
            'submitLabel' => $submitLabel,
            'description' => $description,
            'formIcon'    => 'depense',
        ]);
    }
}
