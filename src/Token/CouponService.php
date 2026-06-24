<?php

namespace App\Token;

use App\Entity\Coupon;
use App\Repository\CouponRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @file Validation et application des coupons de réduction sur l'achat de tokens.
 * @description Centralise la logique : retrouve le coupon par code, vérifie sa
 * validité (période, activation, limite d'usage, paquet ciblé), calcule la
 * remise (% ou montant fixe) et borne le résultat à [0, prix]. Renvoie une
 * erreur traduisible plutôt qu'une exception : un code invalide doit informer
 * l'utilisateur, pas planter l'achat.
 */
class CouponService
{
    public function __construct(
        private CouponRepository $couponRepository,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Applique un éventuel code de réduction au prix d'un paquet.
     *
     * @return array{montantFinal: float, remiseUsd: float, coupon: ?Coupon, erreur: ?string}
     */
    public function appliquer(?string $code, string $packKey, float $montantBase): array
    {
        $sansRemise = ['montantFinal' => $montantBase, 'remiseUsd' => 0.0, 'coupon' => null, 'erreur' => null];

        $code = $code !== null ? trim($code) : '';
        if ($code === '') {
            return $sansRemise; // Aucun code saisi : plein tarif, sans erreur.
        }

        $coupon = $this->couponRepository->findOneByCode($code);
        if ($coupon === null) {
            return [...$sansRemise, 'erreur' => 'coupon.invalid'];
        }
        if (!$coupon->isValideMaintenant()) {
            return [...$sansRemise, 'erreur' => 'coupon.expired'];
        }
        if (!$coupon->estApplicableAuPack($packKey)) {
            return [...$sansRemise, 'erreur' => 'coupon.not_applicable'];
        }

        $remise = $this->calculerRemise($coupon, $montantBase);
        $montantFinal = max(0.0, round($montantBase - $remise, 2));

        return [
            'montantFinal' => $montantFinal,
            'remiseUsd'    => round($montantBase - $montantFinal, 2),
            'coupon'       => $coupon,
            'erreur'       => null,
        ];
    }

    /**
     * Meilleure promo PUBLIQUE applicable à un paquet, pour la vitrine. Parcourt
     * les coupons visibles et valides, ne garde que ceux applicables au paquet, et
     * renvoie celui qui offre la plus grosse remise — ou null s'il n'y en a aucun.
     *
     * @return array{code: string, type: string, valeur: float, montantFinal: float, remiseUsd: float}|null
     */
    public function meilleureRemisePublique(string $packKey, float $prix, ?\DateTimeImmutable $now = null): ?array
    {
        $now ??= new \DateTimeImmutable();
        $meilleure = null;

        foreach ($this->couponRepository->findVisiblesPourVitrine($now) as $coupon) {
            if (!$coupon->estApplicableAuPack($packKey)) {
                continue;
            }

            $remise = $this->calculerRemise($coupon, $prix);
            $montantFinal = max(0.0, round($prix - $remise, 2));
            $remiseUsd = round($prix - $montantFinal, 2);

            if ($meilleure === null || $remiseUsd > $meilleure['remiseUsd']) {
                $meilleure = [
                    'code'         => $coupon->getCode(),
                    'type'         => $coupon->getType(),
                    'valeur'       => $coupon->getValeur(),
                    'montantFinal' => $montantFinal,
                    'remiseUsd'    => $remiseUsd,
                ];
            }
        }

        return $meilleure;
    }

    /** Calcule la remise brute (avant bornage) selon le type du coupon. */
    private function calculerRemise(Coupon $coupon, float $montantBase): float
    {
        if ($coupon->getType() === Coupon::TYPE_PERCENT) {
            return $montantBase * (max(0.0, min(100.0, $coupon->getValeur())) / 100);
        }

        return max(0.0, $coupon->getValeur());
    }

    /** Enregistre la consommation d'un coupon (incrément + flush). */
    public function consommer(Coupon $coupon): void
    {
        $coupon->incrementUsage();
        $this->em->flush();
    }
}
