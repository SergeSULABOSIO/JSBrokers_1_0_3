<?php

namespace App\Tests\Services;

use App\Entity\TaxeVente;
use App\Services\ServiceTaxesVente;
use App\Twig\Extension\TaxeVenteExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Calcul de fiscalité sur les ventes JS Brokers : revenu hors taxe (additif sur
 * base commune), montant des taxes et ventilation par autorité.
 */
class ServiceTaxesVenteTest extends KernelTestCase
{
    private const CODES = ['PHPUNIT-TVA', 'PHPUNIT-CONTRIB'];

    private EntityManagerInterface $em;
    private ServiceTaxesVente $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(ServiceTaxesVente::class);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $this->em->getConnection()->executeStatement(
            'DELETE FROM taxe_vente WHERE code IN (:c)',
            ['c' => self::CODES],
            ['c' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }

    private function creerTaxe(string $code, string $taux, bool $actif = true): void
    {
        $taxe = (new TaxeVente())
            ->setCode($code)
            ->setLibelle('Taxe de test ' . $code)
            ->setAutoriteNom('Autorité de test')
            ->setAutoriteAbreviation('AT')
            ->setTaux($taux)
            ->setActif($actif);
        $this->em->persist($taxe);
        $this->em->flush();
    }

    public function testRevenuHorsTaxeAvecUneTaxe(): void
    {
        $this->creerTaxe(self::CODES[0], '16');

        $this->assertEqualsWithDelta(16.0, $this->service->tauxGlobal(), 0.0001);
        $this->assertEqualsWithDelta(100.0, $this->service->revenuHorsTaxe(116.0), 0.0001);
        $this->assertEqualsWithDelta(16.0, $this->service->montantTaxes(116.0), 0.0001);
    }

    public function testTaxesMultiplesAdditivesSurBaseCommune(): void
    {
        $this->creerTaxe(self::CODES[0], '16');
        $this->creerTaxe(self::CODES[1], '5');

        $this->assertEqualsWithDelta(21.0, $this->service->tauxGlobal(), 0.0001);
        // 121 / 1,21 = 100 hors taxe ; total des taxes = 21.
        $this->assertEqualsWithDelta(100.0, $this->service->revenuHorsTaxe(121.0), 0.0001);
        $this->assertEqualsWithDelta(21.0, $this->service->montantTaxes(121.0), 0.0001);

        // La somme de la ventilation égale le montant total des taxes.
        $somme = array_sum(array_map(static fn ($l) => $l['montant'], $this->service->ventilation(121.0)));
        $this->assertEqualsWithDelta($this->service->montantTaxes(121.0), $somme, 0.0001);
    }

    public function testMemoisationRendUnResultatStable(): void
    {
        $this->creerTaxe(self::CODES[0], '16');

        // Deux appels successifs renvoient la même valeur (le cache n'altère rien).
        $this->assertSame($this->service->tauxGlobal(), $this->service->tauxGlobal());
        $this->assertEqualsWithDelta(
            $this->service->revenuHorsTaxe(116.0),
            $this->service->revenuHorsTaxe(116.0),
            0.0001,
        );
        $this->assertEqualsWithDelta(100.0, $this->service->revenuHorsTaxe(116.0), 0.0001);
    }

    public function testTwigExtensionDelegueAuService(): void
    {
        $this->creerTaxe(self::CODES[0], '16');

        $ext = static::getContainer()->get(TaxeVenteExtension::class);
        $this->assertEqualsWithDelta(100.0, $ext->prixHorsTaxe(116.0), 0.0001);
        $this->assertEqualsWithDelta(16.0, $ext->taxeDue(116.0), 0.0001);
        $this->assertEqualsWithDelta(16.0, $ext->tauxGlobal(), 0.0001);
    }

    public function testTaxeInactiveEstIgnoree(): void
    {
        $this->creerTaxe(self::CODES[0], '16', actif: false);

        $this->assertSame(0.0, $this->service->tauxGlobal());
        // Aucune taxe active : le revenu hors taxe est égal au montant.
        $this->assertEqualsWithDelta(150.0, $this->service->revenuHorsTaxe(150.0), 0.0001);
        $this->assertSame([], $this->service->ventilation(150.0));
    }
}
