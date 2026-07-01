<?php

namespace App\Controller\Api;

use App\Payment\Gateway\PaymentGatewayInterface;
use App\Payment\PaymentStatus;
use App\Repository\TokenPurchaseRepository;
use App\Token\TokenPurchaseFulfillmentService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @file Webhook de réconciliation des paiements (endpoint PUBLIC).
 * @description Confirmations asynchrones du PSP. Pas d'authentification de
 * session : l'appel est authentifié par la SIGNATURE vérifiée dans
 * PaymentGatewayInterface::parseWebhook(). Traitement IDEMPOTENT (garde de
 * statut dans TokenPurchaseFulfillmentService) → un événement rejoué n'induit
 * ni double-crédit ni double-débit. Répond toujours 200 après traitement pour
 * que le PSP cesse de réémettre l'événement.
 */
#[Route('/api/payment/webhook', name: 'api.payment.webhook.')]
class PaymentWebhookController extends AbstractController
{
    public function __construct(
        private PaymentGatewayInterface $gateway,
        private TokenPurchaseRepository $purchaseRepository,
        private TokenPurchaseFulfillmentService $fulfillment,
        private ?LoggerInterface $logger = null,
    ) {
    }

    #[Route('/{provider}', name: 'receive', methods: ['POST'])]
    public function receive(string $provider, Request $request): JsonResponse
    {
        // Le prestataire de l'URL doit correspondre à l'implémentation active.
        if ($provider !== $this->gateway->name()) {
            return $this->json(['status' => 'ignored'], Response::HTTP_NOT_FOUND);
        }

        // Authentification par signature : toute requête non signée/altérée est rejetée.
        try {
            $event = $this->gateway->parseWebhook($request);
        } catch (\Throwable $e) {
            $this->logger?->warning('Webhook paiement rejeté : ' . $e->getMessage());

            return $this->json(['status' => 'invalid'], Response::HTTP_BAD_REQUEST);
        }

        $purchase = $this->purchaseRepository->findOneBy(['providerReference' => $event->providerReference]);
        if ($purchase === null) {
            // Référence inconnue : on acquitte (200) pour stopper les rejeux, mais on trace.
            $this->logger?->warning('Webhook paiement : référence inconnue ' . $event->providerReference);

            return $this->json(['status' => 'unknown']);
        }

        match ($event->status) {
            PaymentStatus::PAID     => $this->fulfillment->fulfill($purchase),
            PaymentStatus::FAILED   => $this->fulfillment->markFailed($purchase, $event->failureReason),
            PaymentStatus::REFUNDED => $this->fulfillment->refund($purchase, $event->failureReason),
            default                 => null, // PENDING ou statut neutre : rien à faire.
        };

        return $this->json(['status' => 'processed']);
    }
}
