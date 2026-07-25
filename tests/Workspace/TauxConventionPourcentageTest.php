<?php

namespace App\Tests\Workspace;

use App\Entity\ConditionPartage;
use App\Entity\Partenaire;
use App\Entity\Risque;
use App\Entity\RevenuPourCourtier;
use App\Entity\TypeRevenu;
use App\Form\RisqueType;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * CONVENTION UNIQUE des taux : tous stockés en POINTS (16 = 16 %), calculs via
 * getFraction() (16 → 0,16). Ce test verrouille les 5 entités converties —
 * Risque, Partenaire, ConditionPartage, RevenuPourCourtier, TypeRevenu — pour
 * qu'aucune ne retombe en fraction.
 */
class TauxConventionPourcentageTest extends WebTestCase
{
    /** getFraction() dérive la fraction depuis les points, pour CHAQUE entité de taux. */
    public function testGetFractionDeriveLaFractionSurToutesLesEntites(): void
    {
        self::bootKernel();

        $this->assertSame(0.16, (new Risque())->setPourcentageCommissionSpecifiqueHT(16.0)->getFraction());
        $this->assertSame(0.35, (new Partenaire())->setPart(35.0)->getFraction());
        $this->assertSame(0.30, (new ConditionPartage())->setTaux(30.0)->getFraction());
        $this->assertSame(0.16, (new RevenuPourCourtier())->setTauxExceptionel(16.0)->getFraction());
        $this->assertSame(0.05, (new TypeRevenu())->setPourcentage(5.0)->getFraction());

        // Non défini → fraction 0 (aucune division sur null).
        $this->assertSame(0.0, (new Risque())->getFraction());
        $this->assertSame(0.0, (new Partenaire())->getFraction());
    }

    /** Le formulaire (PercentType integer) stocke le taux SAISI en points, sans division. */
    public function testFormulaireStockeLeTauxEnPoints(): void
    {
        self::bootKernel();
        /** @var FormFactoryInterface $factory */
        $factory = static::getContainer()->get('form.factory');

        $risque = new Risque();
        // clearMissing=false : on ne lie que le champ de taux (les autres champs
        // requis ne concernent pas cette vérification d'unité).
        $factory->create(RisqueType::class, $risque)->submit(['pourcentageCommissionSpecifiqueHT' => '16'], false);

        $this->assertSame(16.0, $risque->getPourcentageCommissionSpecifiqueHT(), 'Saisir 16 stocke 16 (points), PAS 0,16 (fraction).');
        $this->assertSame(0.16, $risque->getFraction(), 'La fraction dérivée pour les calculs vaut 0,16.');
    }
}
