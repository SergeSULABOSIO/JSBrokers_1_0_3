<?php

namespace App\Token;

use App\Entity\TokenPurchase;
use App\Event\TokenPurchaseEvent;
use App\Event\TokenRefundEvent;
use App\Repository\CouponRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @file Logique « après paiement » d'un achat de tokens, IDEMPOTENTE et partagée.
 * @description Extrait du contrôleur le bloc exécuté une fois le paiement abouti,
 * afin d'être appelé indifféremment par la confirmation synchrone (simulateur /
 * retour PSP) ET par le webhook de réconciliation, sans jamais double-créditer.
 *
 * Garde d'idempotence : un achat déjà encaissé (resp. déjà remboursé) ressort
 * immédiatement — un webhook rejoué n'a aucun effet de bord. L'encaissement
 * (statut + consommation coupon + crédit + numéro de facture) est atomique ;
 * l'e-mail est dispatché APRÈS commit pour qu'un échec d'envoi ne défasse pas
 * le paiement.
 */
class TokenPurchaseFulfillmentService
{
    public function __construct(
        private EntityManagerInterface $em,
        private TokenAccountService $tokenAccountService,
        private CouponService $couponService,
        private CouponRepository $couponRepository,
        private InvoiceNumberService $invoiceNumberService,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * Encaisse un achat : passe PAID, consomme le coupon, crédite les tokens et
     * attribue un numéro de facture, puis notifie. Sans effet si déjà encaissé.
     *
     * @return bool true si l'achat vient d'être encaissé ; false s'il l'était déjà.
     */
    public function fulfill(TokenPurchase $purchase): bool
    {
        if ($purchase->isPaid()) {
            return false; // Idempotence : déjà encaissé (retour + webhook concurrents).
        }

        $this->em->wrapInTransaction(function () use ($purchase): void {
            $purchase
                ->setStatus(TokenPurchase::STATUS_PAID)
                ->setPaidAt(new \DateTimeImmutable())
                ->setFailedReason(null);

            // Consommation du coupon (incrément d'usage) une seule fois, à l'encaissement.
            if ($purchase->getCouponCode() !== null) {
                $coupon = $this->couponRepository->findOneByCode($purchase->getCouponCode());
                if ($coupon !== null) {
                    $this->couponService->consommer($coupon);
                }
            }

            // Crédit effectif des tokens prépayés (cumulables).
            $this->tokenAccountService->credit($purchase->getUtilisateur(), $purchase->getTokens());

            // Numéro de facture séquentiel (verrou pessimiste dans la transaction).
            if ($purchase->getInvoiceNumber() === null) {
                $purchase->setInvoiceNumber($this->invoiceNumberService->next());
            }

            $this->em->flush();
        });

        // E-mail de confirmation corporate + facture jointe (hors transaction).
        $this->dispatcher->dispatch(new TokenPurchaseEvent($purchase));

        return true;
    }

    /** Marque un achat en échec (refus / annulation PSP). Sans crédit. Idempotent. */
    public function markFailed(TokenPurchase $purchase, ?string $reason = null): void
    {
        if ($purchase->isPaid() || $purchase->getStatus() === TokenPurchase::STATUS_REFUNDED) {
            return; // Ne jamais « échouer » un paiement déjà abouti.
        }

        $purchase->setStatus(TokenPurchase::STATUS_FAILED)->setFailedReason($reason);
        $this->em->flush();
    }

    /**
     * Rembourse un achat encaissé : passe REFUNDED, reprend les tokens (borné à 0)
     * et notifie (avoir). Sans effet si non encaissé ou déjà remboursé.
     *
     * @return bool true si le remboursement vient d'être effectué.
     */
    public function refund(TokenPurchase $purchase, ?string $reason = null): bool
    {
        if (!$purchase->isPaid()) {
            return false; // Rien à rembourser (jamais encaissé, ou déjà remboursé).
        }

        $this->em->wrapInTransaction(function () use ($purchase, $reason): void {
            $purchase
                ->setStatus(TokenPurchase::STATUS_REFUNDED)
                ->setRefundedAt(new \DateTimeImmutable())
                ->setFailedReason($reason);

            $this->tokenAccountService->refund($purchase->getUtilisateur(), $purchase->getTokens());

            $this->em->flush();
        });

        $this->dispatcher->dispatch(new TokenRefundEvent($purchase));

        return true;
    }
}
