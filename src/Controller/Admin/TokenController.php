<?php

namespace App\Controller\Admin;

use App\DTO\TokenPurchaseDTO;
use App\Entity\TokenPurchase;
use App\Entity\Utilisateur;
use App\Form\TokenPurchaseType;
use App\Payment\Gateway\PaymentGatewayInterface;
use App\Payment\PaymentContext;
use App\Repository\TokenConsumptionRepository;
use App\Repository\TokenPurchaseRepository;
use App\Services\TokenInvoicePdfService;
use App\Token\CouponService;
use App\Token\ParametresTokenService;
use App\Token\TokenAccountService;
use App\Token\TokenPurchaseFulfillmentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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
    /** Numéro de carte de TEST déclenchant un refus simulé (cf. SimulatedGateway). */
    private const TEST_DECLINE_CARD = '4000000000000002';

    public function __construct(
        private TokenAccountService $tokenAccountService,
        private TokenConsumptionRepository $consumptionRepository,
        private EntityManagerInterface $em,
        private TranslatorInterface $translator,
        private ParametresTokenService $parametres,
        private CouponService $couponService,
        private PaymentGatewayInterface $gateway,
        private TokenPurchaseFulfillmentService $fulfillment,
        private TokenPurchaseRepository $purchaseRepository,
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
            'purchases'    => $this->purchaseRepository->findForUser($user->getId()),
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

            // --- ENCAISSEMENT VIA LE PSP (abstraction PSP-agnostique) -----------
            // 1) Achat enregistré en PENDING (montant + coupon/remise figés) ;
            // 2) création de l'intention chez le PSP ;
            // 3) succès UNIQUEMENT → crédit + facture + e-mail (TokenPurchaseFulfillmentService),
            //    logique idempotente partagée avec le webhook de réconciliation.
            // L'implémentation par défaut (SimulatedGateway) confirme en synchrone ;
            // un PSP réel renverra une URL de redirection et confirmera via webhook.
            $purchase = (new TokenPurchase())
                ->setUtilisateur($user)
                ->setPack($dto->pack)
                ->setTokens($pack['tokens'])
                ->setMontantUsd($remise['montantFinal'])
                ->setRemiseUsd($remise['remiseUsd'])
                ->setCouponCode($remise['coupon']?->getCode())
                ->setCardLast4($dto->cardLast4())
                ->setReference($this->generateReference())
                ->setProvider($this->gateway->name())
                ->setStatus(TokenPurchase::STATUS_PENDING);

            $this->em->persist($purchase);
            $this->em->flush();

            $context = new PaymentContext(
                montant:   (float) $remise['montantFinal'],
                devise:    'USD',
                reference: $purchase->getReference(),
                libelle:   $this->translator->trans('token_buy.title') . ' — ' . ($pack['label'] ?? $dto->pack),
                email:     $user->getEmail(),
                returnUrl: $this->generateUrl('admin.token.payment_return', [], UrlGeneratorInterface::ABSOLUTE_URL),
                // Métadonnée NEUTRE pilotant l'issue du simulateur (carte de test de refus).
                metadata:  ['outcome' => $this->simulatedOutcome($dto)],
            );

            $intent = $this->gateway->createIntent($context);
            $purchase->setProviderReference($intent->providerReference);
            $this->em->flush();

            // PSP réel : redirection vers la page hébergée ; fulfillment au retour/webhook.
            if ($intent->requiresRedirect()) {
                $this->addFlash('info', $this->translator->trans('token_buy.pending'));

                return $this->redirect($intent->redirectUrl);
            }

            // Simulateur : confirmation synchrone.
            $result = $this->gateway->confirm($intent->providerReference);
            if ($result->isPaid()) {
                $this->fulfillment->fulfill($purchase);

                $this->addFlash('success', $this->translator->trans('token_buy.success', [
                    ':tokens' => number_format($pack['tokens'], 0, ',', ' '),
                ]));

                return $this->redirectToRoute('admin.token.index');
            }

            // Échec : aucun crédit, message explicite, l'utilisateur peut réessayer.
            $this->fulfillment->markFailed($purchase, $result->failureReason);
            $this->addFlash('error', $this->translator->trans($result->failureReason ?? 'token_buy.failed'));
            // --------------------------------------------------------------------
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

    /**
     * Retour après paiement chez un PSP réel (page hébergée). Confirme l'intention
     * et déclenche le fulfillment idempotent ; sans effet si le webhook a déjà
     * réconcilié l'achat. Inutilisé en mode simulé (confirmation synchrone).
     */
    #[Route('/payment/return', name: 'payment_return', methods: ['GET'])]
    public function paymentReturn(Request $request): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $ref = (string) $request->query->get('ref', '');

        $purchase = $ref !== '' ? $this->purchaseRepository->findOneBy(['providerReference' => $ref]) : null;
        // L'achat doit appartenir à l'utilisateur courant (anti-rejeu d'une réf. tierce).
        if ($purchase === null || $purchase->getUtilisateur()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }

        if ($purchase->isPaid()) {
            return $this->redirectToRoute('admin.token.index'); // Déjà réconcilié (webhook).
        }

        $result = $this->gateway->confirm($ref);
        if ($result->isPaid()) {
            $this->fulfillment->fulfill($purchase);
            $this->addFlash('success', $this->translator->trans('token_buy.success', [
                ':tokens' => number_format($purchase->getTokens(), 0, ',', ' '),
            ]));
        } else {
            $this->fulfillment->markFailed($purchase, $result->failureReason);
            $this->addFlash('error', $this->translator->trans($result->failureReason ?? 'token_buy.failed'));
        }

        return $this->redirectToRoute('admin.token.index');
    }

    /**
     * Facture (ou avoir) PDF d'un achat encaissé. Réservée au propriétaire de
     * l'achat ; régénérée à la volée (jamais stockée).
     */
    #[Route('/invoice/{id}', name: 'invoice', methods: ['GET'])]
    public function invoice(TokenPurchase $purchase, TokenInvoicePdfService $pdfService): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        if ($purchase->getUtilisateur()?->getId() !== $user->getId() || !$purchase->isPaid()) {
            throw $this->createNotFoundException();
        }

        $fileName = $pdfService->fileName($purchase);

        return new Response($pdfService->generate($purchase), Response::HTTP_OK, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $fileName . '"',
        ]);
    }

    /** Référence lisible d'achat : TOK-ddMMyy-HHmmss. */
    private function generateReference(): string
    {
        return 'TOK-' . (new \DateTimeImmutable())->format('dmy-His');
    }

    /**
     * Issue à demander au simulateur : « failed » si la carte de test de refus est
     * saisie, « paid » sinon. Permet de tester le parcours d'échec de bout en bout
     * sans dépendre d'un PSP réel. Donnée neutre passée en métadonnée du contexte.
     */
    private function simulatedOutcome(TokenPurchaseDTO $dto): string
    {
        $digits = preg_replace('/\D/', '', $dto->cardNumber);

        return $digits === self::TEST_DECLINE_CARD ? 'failed' : 'paid';
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
