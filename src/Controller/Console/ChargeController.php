<?php

namespace App\Controller\Console;

use App\Entity\Charge;
use App\Form\ChargeType;
use App\Repository\ChargeRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * CRUD des types de charges supportées par JS Brokers (référentiel comptable
 * OHADA : compte de classe 6 + axe analytique). Les dépenses réelles s'y rattachent.
 */
#[Route('/console/charges', name: 'console.charge.')]
#[IsGranted('ROLE_ADMIN')]
class ChargeController extends AbstractConsoleController
{
    public function __construct(private ChargeRepository $chargeRepository)
    {
    }

    #[Route('', name: 'index')]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        return $this->render('console/charge/index.html.twig', [
            'pageName' => 'Types de charges',
            'pageIcon' => 'charge',
            'charges'  => $this->chargeRepository->paginateAll($request->query->getInt('page', 1)),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        return $this->traiter(
            new Charge(),
            $request,
            'Nouveau type de charge',
            'Créer la charge',
            true,
            'Définissez une catégorie de charge (compte OHADA classe 6) à laquelle rattacher vos dépenses.'
        );
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(Charge $charge, Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        return $this->traiter(
            $charge,
            $request,
            'Éditer ' . $charge->getCode(),
            'Enregistrer',
            false,
            'Modifiez les paramètres de ce type de charge.'
        );
    }

    #[Route('/{id}', name: 'delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function delete(Charge $charge, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-charge-' . $charge->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $code = $charge->getCode();
        $this->em->remove($charge);
        $this->em->flush();

        $this->addFlash('success', sprintf('Charge « %s » supprimée.', $code));

        return $this->redirectToRoute('console.charge.index');
    }

    /** Traitement partagé création/édition (DRY). */
    private function traiter(Charge $charge, Request $request, string $pageName, string $submitLabel, bool $isNew, string $description = ''): Response
    {
        $form = $this->createForm(ChargeType::class, $charge);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($isNew) {
                $this->em->persist($charge);
            }
            $this->em->flush();

            $this->addFlash('success', sprintf('Charge « %s » enregistrée.', $charge->getCode()));

            return $this->redirectToRoute('console.charge.index');
        }

        return $this->render('console/charge/form.html.twig', [
            'pageName'    => $pageName,
            'form'        => $form,
            'backUrl'     => $this->generateUrl('console.charge.index'),
            'backLabel'   => 'Charges',
            'submitLabel' => $submitLabel,
            'description' => $description,
            'formIcon'    => 'charge',
        ]);
    }
}
