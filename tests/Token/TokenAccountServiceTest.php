<?php

namespace App\Tests\Token;

use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Piste;
use App\Entity\TokenConsumption;
use App\Entity\Utilisateur;
use App\Token\InsufficientTokensException;
use App\Token\TokenAccountService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de la logique de métrage des tokens : renouvellement de la
 * fenêtre gratuite, ordre de consommation (prépayé puis gratuit), blocage à
 * l'épuisement et journalisation. L'EntityManager est simulé (pas de BD).
 */
class TokenAccountServiceTest extends TestCase
{
    /** @var TokenConsumption[] */
    private array $persisted = [];

    private function makeService(): TokenAccountService
    {
        $this->persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($o): void {
            if ($o instanceof TokenConsumption) {
                $this->persisted[] = $o;
            }
        });
        $em->method('flush');

        return new TokenAccountService($em);
    }

    private function owner(): Utilisateur
    {
        return (new Utilisateur())->setEmail('owner@test.local')->setNom('Owner');
    }

    private function entreprise(Utilisateur $owner): Entreprise
    {
        $e = new Entreprise();
        $e->setUtilisateur($owner);

        return $e;
    }

    public function testNewOwnerStartsWithFreeAllowance(): void
    {
        $svc = $this->makeService();
        $owner = $this->owner();

        $balance = $svc->getBalance($owner);

        $this->assertSame(1000, $balance['free']);
        $this->assertSame(0, $balance['paid']);
        $this->assertSame(1000, $balance['total']);
        $this->assertNotNull($balance['windowStartedAt']);
    }

    public function testReadConsumesTwoTokensPerEntityAndLogs(): void
    {
        $svc = $this->makeService();
        $owner = $this->owner();
        $ent = $this->entreprise($owner);

        $svc->meterRead(Cotation::class, 20, $ent, $owner);

        // 20 entités × 2 tokens = 40 ; débités sur l'allocation gratuite.
        $this->assertSame(960, $owner->getFreeTokens());
        $this->assertCount(1, $this->persisted);
        $log = $this->persisted[0];
        $this->assertSame('sortie', $log->getSens());
        $this->assertSame(20, $log->getNombre());
        $this->assertSame(2, $log->getPoidsUnitaire());
        $this->assertSame(40, $log->getPoidsTotal());
        $this->assertSame('Cotation', $log->getEntiteNom());
    }

    public function testWriteWeightsPerEntityType(): void
    {
        $svc = $this->makeService();
        $owner = $this->owner();
        $ent = $this->entreprise($owner);

        $svc->meterWrite(new Cotation(), $ent, $owner); // 50
        $svc->meterWrite(new Piste(), $ent, $owner);    // 20

        $this->assertSame(1000 - 50 - 20, $owner->getFreeTokens());
        $this->assertSame('entree', $this->persisted[0]->getSens());
    }

    public function testPrepaidConsumedBeforeFree(): void
    {
        $svc = $this->makeService();
        $owner = $this->owner();
        $owner->setPaidTokens(100);
        $ent = $this->entreprise($owner);

        // Cotation = 50 → entièrement pris sur le prépayé.
        $svc->meterWrite(new Cotation(), $ent, $owner);
        $this->assertSame(50, $owner->getPaidTokens());
        $this->assertSame(1000, $owner->getFreeTokens());

        // 50 (reste prépayé) + 20 du gratuit pour couvrir une lecture de 35 entités (70).
        $svc->meterRead(Cotation::class, 35, $ent, $owner);
        $this->assertSame(0, $owner->getPaidTokens());
        $this->assertSame(980, $owner->getFreeTokens());
    }

    public function testBlocksWhenInsufficient(): void
    {
        $svc = $this->makeService();
        $owner = $this->owner();
        $owner->setFreeTokens(10); // < coût d'une Cotation (50)
        $owner->setFreeWindowStartedAt(new \DateTimeImmutable()); // fenêtre fraîche
        $ent = $this->entreprise($owner);

        $this->expectException(InsufficientTokensException::class);
        $svc->meterWrite(new Cotation(), $ent, $owner);
    }

    public function testNoConsumptionWhenBlocked(): void
    {
        $svc = $this->makeService();
        $owner = $this->owner();
        $owner->setFreeTokens(10);
        $owner->setFreeWindowStartedAt(new \DateTimeImmutable());
        $ent = $this->entreprise($owner);

        try {
            $svc->meterWrite(new Cotation(), $ent, $owner);
            $this->fail('Une exception était attendue.');
        } catch (InsufficientTokensException) {
            // Le solde n'a pas bougé et rien n'a été journalisé.
            $this->assertSame(10, $owner->getFreeTokens());
            $this->assertCount(0, $this->persisted);
        }
    }

    public function testFreeWindowRenewsAfterEightHours(): void
    {
        $svc = $this->makeService();
        $owner = $this->owner();
        $owner->setFreeTokens(0);
        $owner->setFreeWindowStartedAt(new \DateTimeImmutable('-9 hours'));

        $balance = $svc->getBalance($owner);

        $this->assertSame(1000, $balance['free']); // renouvellement automatique
    }

    public function testFreeWindowNotRenewedWithinEightHours(): void
    {
        $svc = $this->makeService();
        $owner = $this->owner();
        $owner->setFreeTokens(120);
        $owner->setFreeWindowStartedAt(new \DateTimeImmutable('-2 hours'));

        $balance = $svc->getBalance($owner);

        $this->assertSame(120, $balance['free']); // pas encore renouvelé
    }

    public function testReadOfZeroIsFree(): void
    {
        $svc = $this->makeService();
        $owner = $this->owner();
        $ent = $this->entreprise($owner);

        $svc->meterRead(Cotation::class, 0, $ent, $owner);

        $this->assertSame(1000, $owner->getFreeTokens());
        $this->assertCount(0, $this->persisted);
    }
}
