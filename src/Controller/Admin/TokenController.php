<?php

namespace App\Controller\Admin;

use App\DTO\TokenPurchaseDTO;
use App\Entity\TokenPurchase;
use App\Entity\Utilisateur;
use App\Event\TokenPurchaseEvent;
use App\Form\TokenPurchaseType;
use App\Repository\TokenConsumptionRepository;
use App\Token\CouponService;
use App\Token\ParametresTokenService;
use App\Token\TokenAccountService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * Espace compte « Tokens » : visualisation du solde, historique détaillé de
 * consommation et achat (simulé) de paquets prépayés.
 */
#[Route('/admin/tokens', name: 'admin.token.')]
#[IsGranted('ROLE_USER')]
class TokenController extends AbstractController
{
    public function __construct(
        private TokenAccountService $tokenAccountService,
        private TokenConsumptionRepository $consumptionRepository,
        private EntityManagerInterface $em,
        private TranslatorInterface $translator,
        private EventDispatcherInterface $dispatcher,
        private ParametresTokenService $parametres,
        private CouponService $couponService,
    ) {}

    /** Page compte : solde + historique de consommation paginé + accès à l'achat. */
    #[Route('', name: 'index')]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $this->applyLangPreference($request, $user, $localeSwitcher);

        $page = max(1, $request->query->getInt('page', 1));

        return $this->render('admin/token/index.html.twig', [
            'pageName'     => $this->translator->trans('token_account_title'),
            'balance'      => $this->tokenAccountService->getBalance($user),
            'consumptions' => $this->consumptionRepository->paginateForProprietaire($user->getId(), $page),
            'usdPerToken'  => $this->parametres->usdPerToken(),
        ]);
    }

    /** Solde courant au format JSON pour le rafraîchissement temps réel du widget. */
    #[Route('/balance', name: 'balance', methods: ['GET'])]
    public function balance(): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $b = $this->tokenAccountService->getBalance($user);

        return $this->json([
            'free'          => $b['free'],
            'paid'          => $b['paid'],
            'total'         => $b['total'],
            'allowance'     => $b['allowance'],
            'nextRenewalAt' => $b['nextRenewalAt']?->format(\DateTimeImmutable::ATOM),
        ]);
    }

    /** Achat d'un paquet de tokens (paiement simulé, pattern PRG). */
    #[Route('/buy', name: 'buy', methods: ['GET', 'POST'])]
    public function buy(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $this->applyLangPreference($request, $user, $localeSwitcher);

        $dto = new TokenPurchaseDTO();
        if ($request->query->has('pack') && $this->parametres->pack((string) $request->query->get('pack')) !== null) {
            $dto->pack = $request->query->get('pack');
        }
        // Préremplissage du code promo (lien « Profiter de l'offre » depuis la vitrine).
        if ($request->query->has('coupon')) {
            $dto->couponCode = (string) $request->query->get('coupon');
        }

        $form = $this->createForm(TokenPurchaseType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $pack = $this->parametres->pack($dto->pack);

            // Application d'un éventuel coupon de réduction (% ou montant fixe) sur
            // le prix du paquet. Un code fourni mais invalide bloque l'achat avec
            // un message explicite (l'utilisateur peut corriger ou le retirer).
            $remise = $this->couponService->appliquer($dto->couponCode, $dto->pack, (float) $pack['price']);
            if ($remise['erreur'] !== null) {
                $this->addFlash('error', $this->translator->trans($remise['erreur']));

                return $this->render('admin/token/buy.html.twig', [
                    'pageName' => $this->translator->trans('token_buy.title'),
                    'form'     => $form,
                    'packs'    => $this->parametres->packs(),
                    'balance'  => $this->tokenAccountService->getBalance($user),
                ]);
            }

            // --- FRONTIÈRE DE SIMULATION DE PAIEMENT ----------------------------
            // Aujourd'hui : toute carte bien formée « réussit ». Pour passer en
            // production, remplacer ce bloc par l'appel à l'API du prestataire
            // (création d'intention de paiement, confirmation, webhook) ; le reste
            // de la logique (crédit + e-mail) demeure inchangé.
            $purchase = (new TokenPurchase())
                ->setUtilisateur($user)
                ->setPack($dto->pack)
                ->setTokens($pack['tokens'])
                ->setMontantUsd($remise['montantFinal'])
                ->setRemiseUsd($remise['remiseUsd'])
                ->setCouponCode($remise['coupon']?->getCode())
                ->setCardLast4($dto->cardLast4())
                ->setReference($this->generateReference())
                ->setStatus(TokenPurchase::STATUS_PAID_SIMULATED);

            $this->em->persist($purchase);
            $this->em->flush();
            // --------------------------------------------------------------------

            // Consommation du coupon (incrément du compteur d'usage) après succès.
            if ($remise['coupon'] !== null) {
                $this->couponService->consommer($remise['coupon']);
            }

            // Crédit effectif des tokens prépayés (cumulables).
            $this->tokenAccountService->credit($user, $pack['tokens']);

            // E-mail de confirmation corporate + notification des agents JS Brokers.
            $this->dispatcher->dispatch(new TokenPurchaseEvent($purchase));

            $this->addFlash('success', $this->translator->trans('token_buy.success', [
                ':tokens' => number_format($pack['tokens'], 0, ',', ' '),
            ]));

            return $this->redirectToRoute('admin.token.index');
        }

        return $this->render('admin/token/buy.html.twig', [
            'pageName' => $this->translator->trans('token_buy.title'),
            'form'     => $form,
            'packs'    => $this->parametres->packs(),
            'balance'  => $this->tokenAccountService->getBalance($user),
        ]);
    }

    /**
     * Aperçu en direct de la remise d'un coupon sur un paquet (sans effet de bord).
     * Réutilise EXACTEMENT la logique d'achat (CouponService::appliquer) : le prix
     * affiché en aperçu est donc identique au prix qui sera facturé. GET sans effet
     * de bord → pas de jeton CSRF nécessaire.
     */
    #[Route('/coupon-preview', name: 'coupon_preview', methods: ['GET'])]
    public function couponPreview(Request $request): JsonResponse
    {
        $packKey = (string) $request->query->get('pack', '');
        $pack = $this->parametres->pack($packKey);
        if ($pack === null) {
            return $this->json(['erreur' => $this->translator->trans('coupon.invalid')], Response::HTTP_BAD_REQUEST);
        }

        $base = (float) $pack['price'];
        $remise = $this->couponService->appliquer($request->query->get('code'), $packKey, $base);

        return $this->json([
            'base'         => $base,
            'montantFinal' => $remise['montantFinal'],
            'remiseUsd'    => $remise['remiseUsd'],
            'erreur'       => $remise['erreur'] !== null ? $this->translator->trans($remise['erreur']) : null,
        ]);
    }

    /** Référence lisible d'achat : TOK-ddMMyy-HHmmss. */
    private function generateReference(): string
    {
        return 'TOK-' . (new \DateTimeImmutable())->format('dmy-His');
    }

    /**
     * Bascule de langue persistante pour l'espace authentifié : ?lang= met à
     * jour la préférence de l'utilisateur (le UserLocaleListener s'appuie
     * ensuite sur getLocale()) et applique la locale au rendu courant.
     */
    private function applyLangPreference(Request $request, Utilisateur $user, LocaleSwitcher $localeSwitcher): void
    {
        $lang = $request->query->get('lang');
        if (in_array($lang, ['fr', 'en'], true) && $lang !== $user->getLocale()) {
            $user->setLocale($lang);
            $this->em->flush();
            $localeSwitcher->setLocale($lang);
        }
    }
}
