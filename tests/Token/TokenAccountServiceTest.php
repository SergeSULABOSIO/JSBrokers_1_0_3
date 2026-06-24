<?php

namespace App\Tests\Token;

use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Piste;
use App\Entity\TokenConsumption;
use App\Entity\PlateformeParametres;
use App\Entity\Utilisateur;
use App\Repository\PlateformeParametresRepository;
use App\Token\InsufficientTokensException;
use App\Token\ParametresTokenService;
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

        // Repository renvoyant un singleton « vide » (champs nuls) : ParametresTokenService
        // retombe alors sur les constantes TokenPricing → mêmes valeurs qu'avant.
        $repo = $this->createMock(PlateformeParametresRepository::class);
        $repo->method('getSingleton')->willReturn(new PlateformeParametres());

        return new TokenAccountService($em, new ParametresTokenService($repo));
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

    public function testWriteWeightReflectsConsoleEdit(): void
    {
        $this->persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($o): void {
            if ($o instanceof TokenConsumption) {
                $this->persisted[] = $o;
            }
        });
        $em->method('flush');

        // Plan tarifaire édité en Console : poids d'écriture de Cotation porté à 500
        // (au lieu de la constante 50). Le métrage doit appliquer la valeur ÉDITÉE.
        $params = (new PlateformeParametres())->setWriteWeights([Cotation::class => 500]);
        $repo = $this->createMock(PlateformeParametresRepository::class);
        $repo->method('getSingleton')->willReturn($params);
        $svc = new TokenAccountService($em, new ParametresTokenService($repo));

        $owner = $this->owner();
        $owner->setPaidTokens(1000);
        $ent = $this->entreprise($owner);

        $svc->meterWrite(new Cotation(), $ent, $owner);

        // La facturation lit la même source que la Console : 500 tokens débités, journalisés.
        $this->assertSame(500, $owner->getPaidTokens());
        $this->assertSame(500, $this->persisted[0]->getPoidsUnitaire());
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
