<?php

namespace App\Controller\Console;

use App\Entity\Coupon;
use App\Form\CouponType;
use App\Repository\CouponRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * CRUD des coupons / offres de réduction sur l'achat de paquets de tokens.
 */
#[Route('/console/coupons', name: 'console.coupon.')]
#[IsGranted('ROLE_ADMIN')]
class CouponController extends AbstractConsoleController
{
    public function __construct(private CouponRepository $couponRepository)
    {
    }

    #[Route('', name: 'index')]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        return $this->render('console/coupon/index.html.twig', [
            'pageName' => 'Coupons de réduction',
            'pageIcon' => 'offre',
            'coupons'  => $this->couponRepository->paginateAll($request->query->getInt('page', 1)),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        $coupon = new Coupon();
        $coupon->setDateDebut(new \DateTimeImmutable('today'));
        $coupon->setDateFin(new \DateTimeImmutable('today +1 month'));

        return $this->traiter(
            $coupon,
            $request,
            'Nouveau coupon',
            'Créer le coupon',
            true,
            'Définissez une offre de réduction applicable à l\'achat de paquets de tokens.'
        );
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(Coupon $coupon, Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        return $this->traiter(
            $coupon,
            $request,
            'Éditer ' . $coupon->getCode(),
            'Enregistrer',
            false,
            'Modifiez les paramètres de ce coupon de réduction.'
        );
    }

    #[Route('/{id}', name: 'delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function delete(Coupon $coupon, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-coupon-' . $coupon->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $code = $coupon->getCode();
        $this->em->remove($coupon);
        $this->em->flush();

        $this->addFlash('success', sprintf('Coupon « %s » supprimé.', $code));

        return $this->redirectToRoute('console.coupon.index');
    }

    /** Traitement partagé création/édition (DRY). */
    private function traiter(Coupon $coupon, Request $request, string $pageName, string $submitLabel, bool $isNew, string $description = ''): Response
    {
        $form = $this->createForm(CouponType::class, $coupon);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($isNew) {
                $this->em->persist($coupon);
            }
            $this->em->flush();

            $this->addFlash('success', sprintf('Coupon « %s » enregistré.', $coupon->getCode()));

            return $this->redirectToRoute('console.coupon.index');
        }

        return $this->render('console/coupon/form.html.twig', [
            'pageName'    => $pageName,
            'form'        => $form,
            'backUrl'     => $this->generateUrl('console.coupon.index'),
            'backLabel'   => 'Coupons',
            'submitLabel' => $submitLabel,
            'description' => $description,
            'formIcon'    => 'offre',
        ]);
    }
}
