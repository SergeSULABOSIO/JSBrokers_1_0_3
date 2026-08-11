<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\NormaliseurDeDates;
use PHPUnit\Framework\TestCase;

/**
 * LES DATES DICTÉES PAR L'UTILISATEUR doivent atteindre le formulaire dans le
 * format qu'il attend — sans quoi le plan n'est jamais prêt et aucun bouton
 * n'apparaît (incident du 2026-08-11 : « Le 11/08/2026, 150 $, entretien du
 * véhicule » a produit trois messages sans le moindre enregistrement).
 */
class NormaliseurDeDatesTest extends TestCase
{
    private NormaliseurDeDates $normaliseur;

    protected function setUp(): void
    {
        $this->normaliseur = new NormaliseurDeDates();
    }

    /**
     * @dataProvider datesFrancaises
     */
    public function testUneDateFrancaiseDevientLeFormatDuFormulaire(string $dicte, string $attendu): void
    {
        $this->assertSame($attendu, $this->normaliseur->normaliser($dicte, 'date_immutable'));
    }

    public static function datesFrancaises(): iterable
    {
        // LE CAS DE L'INCIDENT, en premier.
        yield 'jour/mois/année' => ['11/08/2026', '2026-08-11'];
        yield 'avec des tirets' => ['11-08-2026', '2026-08-11'];
        yield 'avec des points' => ['11.08.2026', '2026-08-11'];
        yield 'année sur deux chiffres' => ['11/08/26', '2026-08-11'];
        yield 'mois en clair' => ['11 août 2026', '2026-08-11'];
        yield 'mois en clair abrégé' => ['1 sept 2026', '2026-09-01'];
        yield 'premier du mois' => ['1er septembre 2026', '2026-09-01'];
        yield 'déjà au bon format' => ['2026-08-11', '2026-08-11'];
        yield 'ISO avec heure' => ['2026-08-11T09:30', '2026-08-11'];
    }

    /**
     * LE PIÈGE À NE PAS TOMBER DEDANS. « 11/08/2026 » vaut le 11 AOÛT : la
     * plateforme est francophone, et `new DateTime('11/08/2026')` lirait le
     * 8 novembre — une date fausse écrite en silence, le pire des résultats.
     */
    public function testLaConventionEstJourMoisEtJamaisMoisJour(): void
    {
        $this->assertSame('2026-08-11', $this->normaliseur->normaliser('11/08/2026', 'date'));
        // Le 25 ne peut pas être un mois : la lecture jour d'abord est la seule possible.
        $this->assertSame('2026-12-25', $this->normaliseur->normaliser('25/12/2026', 'date'));
    }

    /**
     * UN HORODATAGE EXIGE L'HEURE. C'est le GOTCHA déjà payé sur
     * `Tranche.payableAt` : en « Y-m-d », le DateTimeType répond « Veuillez saisir
     * une date et une heure valides » et le plan reste bloqué.
     */
    public function testUnChampHorodateRecoitLHeure(): void
    {
        $this->assertSame('2026-08-11T00:00', $this->normaliseur->normaliser('11/08/2026', 'datetime_immutable'));
        $this->assertSame('2026-08-11T09:30', $this->normaliseur->normaliser('11/08/2026 09:30', 'datetime'));
        // Une date nue passée à un champ horodaté est complétée, pas rejetée.
        $this->assertSame('2026-08-11T00:00', $this->normaliseur->normaliser('2026-08-11', 'datetime'));
    }

    /**
     * ON NE DEVINE JAMAIS. Ce qui n'est pas une date reconnaissable repart TEL QUEL :
     * le formulaire refusera en nommant le champ, ce qui est infiniment préférable à
     * une date inventée par report (PHP transforme volontiers le 32/13 en 1er février).
     */
    public function testCeQuiNEstPasUneDateNEstPasTouche(): void
    {
        $this->assertSame('la semaine prochaine', $this->normaliseur->normaliser('la semaine prochaine', 'date'));
        $this->assertSame('32/13/2026', $this->normaliseur->normaliser('32/13/2026', 'date'));
        $this->assertSame('', $this->normaliseur->normaliser('', 'date'));
        $this->assertSame(42, $this->normaliseur->normaliser(42, 'date'));
        $this->assertNull($this->normaliseur->normaliser(null, 'date'));
    }

    /** Seuls les champs déclarés temporels sont touchés : un libellé reste un libellé. */
    public function testSeulsLesChampsTemporelsSontNormalises(): void
    {
        $champs = $this->normaliseur->normaliserChamps(
            ['dateDepense' => '11/08/2026', 'reference' => '11/08/2026', 'montant' => 150],
            ['dateDepense' => 'date_immutable'],
        );

        $this->assertSame('2026-08-11', $champs['dateDepense']);
        $this->assertSame('11/08/2026', $champs['reference'], 'Un champ texte ne doit pas être réécrit.');
        $this->assertSame(150, $champs['montant']);
    }
}
