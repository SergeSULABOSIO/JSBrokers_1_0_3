<?php

namespace App\Controller\Console;

use App\Entity\TaxeVente;
use App\Form\TaxeVenteType;
use App\Repository\TaxeVenteRepository;
use App\Repository\TokenPurchaseRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * CRUD des taxes sur les ventes propres de JS Brokers (fiscalité de la plateforme).
 * Distinct de l'Admin\TaxeController (taxes du domaine assurance) : préfixe de
 * route « console.taxe. » dédié, aucune collision de noms.
 */
#[Route('/console/taxes', name: 'console.taxe.')]
#[IsGranted('ROLE_ADMIN')]
class TaxeVenteController extends AbstractConsoleController
{
    public function __construct(
        private TaxeVenteRepository $taxeVenteRepository,
        private TokenPurchaseRepository $purchaseRepository,
    ) {
    }

    #[Route('', name: 'index')]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        return $this->render('console/taxe_vente/index.html.twig', [
            'pageName'    => 'Fiscalité',
            'pageIcon'    => 'taxe',
            'taxes'       => $this->taxeVenteRepository->paginateAll($request->query->getInt('page', 1)),
            // Revenu total cumulé : assiette du montant représenté par chaque taxe
            // (calcul par ligne via le helper Twig prix_ht, cf. liste).
            'revenuTotal' => $this->purchaseRepository->totals()['revenue'],
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        return $this->traiter(
            new TaxeVente(),
            $request,
            'Nouvelle taxe',
            'Créer la taxe',
            true,
            'Définissez une taxe due par JS Brokers sur ses ventes et reversée à une autorité fiscale.'
        );
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(TaxeVente $taxe, Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        return $this->traiter(
            $taxe,
            $request,
            'Éditer ' . $taxe->getCode(),
            'Enregistrer',
            false,
            'Modifiez les paramètres de cette taxe sur les ventes.'
        );
    }

    #[Route('/{id}', name: 'delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function delete(TaxeVente $taxe, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-taxe-' . $taxe->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $code = $taxe->getCode();
        $this->em->remove($taxe);
        $this->em->flush();

        $this->addFlash('success', sprintf('Taxe « %s » supprimée.', $code));

        return $this->redirectToRoute('console.taxe.index');
    }

    /** Traitement partagé création/édition (DRY). */
    private function traiter(TaxeVente $taxe, Request $request, string $pageName, string $submitLabel, bool $isNew, string $description = ''): Response
    {
        $form = $this->createForm(TaxeVenteType::class, $taxe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($isNew) {
                $this->em->persist($taxe);
            }
            $this->em->flush();

            $this->addFlash('success', sprintf('Taxe « %s » enregistrée.', $taxe->getCode()));

            return $this->redirectToRoute('console.taxe.index');
        }

        return $this->render('console/taxe_vente/form.html.twig', [
            'pageName'    => $pageName,
            'form'        => $form,
            'backUrl'     => $this->generateUrl('console.taxe.index'),
            'backLabel'   => 'Fiscalité',
            'submitLabel' => $submitLabel,
            'description' => $description,
            'formIcon'    => 'taxe',
        ]);
    }
}
