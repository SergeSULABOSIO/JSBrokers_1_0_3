<?php

namespace App\Tests\Token;

use App\Entity\Coupon;
use App\Repository\CouponRepository;
use App\Token\CouponService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires du service de coupons : calcul de remise (% et fixe), bornage,
 * et sélection de la meilleure promo PUBLIQUE par paquet (ciblage). Le repository
 * est simulé : pas de base de données.
 */
class CouponServiceTest extends TestCase
{
    private function coupon(string $code, string $type, float $valeur, ?string $packCible = null): Coupon
    {
        return (new Coupon())
            ->setCode($code)
            ->setType($type)
            ->setValeur($valeur)
            ->setDateDebut(new \DateTimeImmutable('-1 day'))
            ->setDateFin(new \DateTimeImmutable('+1 day'))
            ->setActif(true)
            ->setVisiblePublic(true)
            ->setPackCible($packCible);
    }

    /** @param Coupon[] $visibles */
    private function service(array $visibles = [], ?Coupon $byCode = null): CouponService
    {
        $repo = $this->createMock(CouponRepository::class);
        $repo->method('findVisiblesPourVitrine')->willReturn($visibles);
        $repo->method('findOneByCode')->willReturn($byCode);

        return new CouponService($repo, $this->createMock(EntityManagerInterface::class));
    }

    public function testAppliquerPourcentage(): void
    {
        $svc = $this->service(byCode: $this->coupon('P20', Coupon::TYPE_PERCENT, 20));

        $r = $svc->appliquer('P20', 'intermediaire', 40.0);

        $this->assertNull($r['erreur']);
        $this->assertEqualsWithDelta(32.0, $r['montantFinal'], 0.001);
        $this->assertEqualsWithDelta(8.0, $r['remiseUsd'], 0.001);
    }

    public function testAppliquerMontantFixeBorneAZero(): void
    {
        // Remise fixe supérieure au prix : le montant final ne descend jamais sous 0.
        $svc = $this->service(byCode: $this->coupon('F50', Coupon::TYPE_FIXED, 50));

        $r = $svc->appliquer('F50', 'intermediaire', 10.0);

        $this->assertEqualsWithDelta(0.0, $r['montantFinal'], 0.001);
        $this->assertEqualsWithDelta(10.0, $r['remiseUsd'], 0.001);
    }

    public function testAppliquerCodeInconnu(): void
    {
        $svc = $this->service(byCode: null);

        $r = $svc->appliquer('NOPE', 'intermediaire', 10.0);

        $this->assertSame('coupon.invalid', $r['erreur']);
        $this->assertEqualsWithDelta(10.0, $r['montantFinal'], 0.001);
    }

    public function testMeilleureRemisePubliqueRespecteLeCiblage(): void
    {
        // Coupon ciblant UNIQUEMENT « professionnel ».
        $svc = $this->service([$this->coupon('PRO15', Coupon::TYPE_PERCENT, 15, 'professionnel')]);

        // Ne s'applique pas à un autre paquet.
        $this->assertNull($svc->meilleureRemisePublique('intermediaire', 10.0));

        // S'applique au paquet ciblé.
        $promo = $svc->meilleureRemisePublique('professionnel', 40.0);
        $this->assertNotNull($promo);
        $this->assertSame('PRO15', $promo['code']);
        $this->assertEqualsWithDelta(34.0, $promo['montantFinal'], 0.001);
        $this->assertEqualsWithDelta(6.0, $promo['remiseUsd'], 0.001);
    }

    public function testMeilleureRemisePubliqueChoisitLaPlusAvantageuse(): void
    {
        // Deux coupons applicables à tous les paquets : on garde la plus grosse remise.
        $svc = $this->service([
            $this->coupon('TEN', Coupon::TYPE_PERCENT, 10),  // -4 $ sur 40 $
            $this->coupon('HALF', Coupon::TYPE_PERCENT, 50), // -20 $ sur 40 $
        ]);

        $promo = $svc->meilleureRemisePublique('professionnel', 40.0);

        $this->assertNotNull($promo);
        $this->assertSame('HALF', $promo['code']);
        $this->assertEqualsWithDelta(20.0, $promo['remiseUsd'], 0.001);
    }
}
