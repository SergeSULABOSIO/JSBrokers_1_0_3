<?php

namespace App\Tests\Ai;

use App\Ai\Proposition\RevenuCourtierPrescrit;
use App\Entity\Chargement;
use App\Entity\ChargementPourPrime;
use App\Entity\Client;
use App\Entity\Cotation;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Risque;
use App\Entity\TypeRevenu;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * CE QUE KET ANNONCE DOIT ÊTRE CE QUE LES ÉCRANS FACTURENT.
 *
 * ── POURQUOI CE TEST EXISTE ─────────────────────────────────────────────────────────
 * `RevenuCourtierPrescrit::prescription()` ANNONCE la rémunération du courtier avant
 * écriture (« "Commission Ordinaire" — 10,00 % (taux prescrit par le risque) ») ; les
 * écrans, la facturation, les notes et la comptabilité la CALCULENT par
 * `IndicatorCalculationHelper::getRevenuMontantHt()`.
 *
 * ⚠ CE TEST COMPARAIT KET À LA CASCADE DE `Constante`, PAS AUX ÉCRANS — et les
 * deux cascades n'avaient pas le même ordre : `Constante` (et Ket) plaçaient le taux du
 * RISQUE avant le pourcentage du TYPE, les écrans l'inverse. Un type qui portait les deux
 * facturait 10 % pendant que Ket en annonçait 15 %, sans que rien ne le voie. Décision du
 * 2026-10-15 (chantier « Assiette et source unique de la commission ») : l'ordre des
 * ÉCRANS fait foi, c'est celui qui a été facturé ; une seule règle, `TarifDuRevenu`.
 *
 * ⚠ LA PREMIÈRE BRANCHE (taux DICTÉ) vit dans `deriver()`, qui l'écrit en
 * `tauxExceptionel` sur le revenu : le dernier cas vérifie que les écrans le retiennent.
 */
class RevenuCourtierPrescritPariteTest extends KernelTestCase
{
    private const PRIME_NETTE = 10000.0;

    /**
     * @dataProvider configurations
     */
    public function testLeMontantAnnonceParKetEstCeluiQueLesEcransFacturent(
        bool $adosseAuRisque,
        float $tauxDuRisque,
        ?float $pourcentageDuType,
        ?float $forfaitDuType,
        float $montantAttendu,
    ): void {
        $revenu = $this->revenu($adosseAuRisque, $tauxDuRisque, $pourcentageDuType, $forfaitDuType);

        $facture = $this->ecrans()->getRevenuMontantHt($revenu);
        self::assertEqualsWithDelta($montantAttendu, $facture, 0.005, 'Ce que les écrans facturent.');

        self::assertEqualsWithDelta($facture, $this->annonceDeKet($revenu), 0.005,
            'Ce que Ket annonce avant l’écriture doit être ce que les écrans factureront.');
    }

    public static function configurations(): iterable
    {
        // Le taux du risque, quand le type s'y adosse et ne porte rien d'autre.
        yield 'taux du risque' => [true, 10.0, null, null, 1000.0];

        // Le taux propre au type, quand il ne s'adosse pas au risque.
        yield 'taux du type' => [false, 0.0, 12.0, null, 1200.0];

        // ⚠ LE FORFAIT D'UN TYPE S'AJOUTE, IL NE SE MULTIPLIE PAS.
        yield 'forfait du type' => [false, 0.0, null, 5000.0, 5000.0];

        // ⚠ LE DRAPEAU ET UN POURCENTAGE : le pourcentage du TYPE l'emporte (ordre des
        // écrans). Ket annonçait le taux du risque, 15 %, pour une facture à 10 %.
        yield 'drapeau et pourcentage du type' => [true, 15.0, 10.0, null, 1000.0];

        // Le drapeau et un forfait : le forfait du type l'emporte.
        yield 'drapeau et forfait du type' => [true, 15.0, null, 5000.0, 5000.0];

        // Le drapeau, un risque sans taux, rien sur le type : rien n'est prescrit.
        yield 'risque sans taux' => [true, 0.0, null, null, 0.0];
    }

    public function testUnTauxDicteParKetEstCeluiQueLesEcransFacturent(): void
    {
        $revenu = $this->revenu(adosseAuRisque: true, tauxDuRisque: 10.0, pourcentageDuType: 12.0);
        $revenu->setTauxExceptionel(17.5);

        self::assertEqualsWithDelta(1750.0, $this->ecrans()->getRevenuMontantHt($revenu), 0.005,
            'Un taux dicté par l’assistant (taux exceptionnel du revenu) l’emporte sur tout.');
    }

    /**
     * Ce que Ket annonce pour ce type et ce risque (méthode privée de l'annonce : elle n'a
     * pas d'autre entrée hors de la base), rapporté à l'assiette.
     */
    private function annonceDeKet(RevenuPourCourtier $revenu): float
    {
        $prescrit = static::getContainer()->get(RevenuCourtierPrescrit::class);
        $methode = new \ReflectionMethod($prescrit, 'prescription');
        $prescription = $methode->invoke($prescrit, $revenu->getTypeRevenu(), $revenu->getCotation()->getPiste()->getRisque());

        if ($prescription === null) {
            return 0.0;
        }
        if ($prescription['taux'] !== null) {
            return self::PRIME_NETTE * $prescription['taux']->fraction();
        }

        // Un forfait : l'annonce le nomme ; c'est le forfait du type.
        return (float) $revenu->getTypeRevenu()->getMontantflat();
    }

    /** Une affaire montée en mémoire, assise sur une prime nette de 10 000. */
    private function revenu(
        bool $adosseAuRisque,
        float $tauxDuRisque,
        ?float $pourcentageDuType = null,
        ?float $forfaitDuType = null,
    ): RevenuPourCourtier {
        // Les écrans apparient le chargement cible PAR IDENTIFIANT : il en faut un.
        $primeNette = (new Chargement())->setNom('Prime nette')->setFonction(Chargement::FONCTION_PRIME_NETTE);
        (new \ReflectionProperty($primeNette, 'id'))->setValue($primeNette, 1);

        $type = (new TypeRevenu())->setNom('Commission Ordinaire')->setTypeChargement($primeNette)
            ->setAppliquerPourcentageDuRisque($adosseAuRisque);
        if ($pourcentageDuType !== null) {
            $type->setPourcentage($pourcentageDuType);
        }
        if ($forfaitDuType !== null) {
            $type->setMontantflat($forfaitDuType);
        }

        $risque = (new Risque())->setNomComplet('RC Aviation')->setCode('RCA')->setImposable(true);
        $risque->setPourcentageCommissionSpecifiqueHT($tauxDuRisque);

        $cotation = new Cotation();
        $cotation->setPiste((new Piste())->setRisque($risque)->setClient((new Client())->setNom('KIN AVIA')));
        $cotation->addChargement(
            (new ChargementPourPrime())->setNom('Prime nette')->setType($primeNette)
                ->setMontantFlatExceptionel(self::PRIME_NETTE),
        );

        $revenu = (new RevenuPourCourtier())->setNom('Commission')->setTypeRevenu($type);
        $cotation->addRevenu($revenu);

        return $revenu;
    }

    private function ecrans(): IndicatorCalculationHelper
    {
        self::bootKernel();

        return static::getContainer()->get(IndicatorCalculationHelper::class);
    }
}
