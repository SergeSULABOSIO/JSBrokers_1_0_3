<?php

namespace App\Tests\Echange;

use App\Echange\Reprise\ValeursMultiples;
use PHPUnit\Framework\TestCase;

/**
 * LA CELLULE QUI PORTE PLUSIEURS TERMES — « Prime nette = 10000 ; Accessoires = 500 ».
 *
 * ⚠ CETTE CLASSE EST LE PIVOT DE L'ALLER-RETOUR. Elle écrit la composition d'une prime à
 * l'export et la relit à l'import : si les deux sens divergent d'un iota, une reprise
 * rend une prime fausse sans que rien ne le signale. Elle se teste donc dans les DEUX
 * SENS, et sur la typographie que les gens tapent réellement.
 */
final class ValeursMultiplesTest extends TestCase
{
    /**
     * ⚠ CE QUE NOUS ÉCRIVONS, NOUS DEVONS LE RELIRE À L'IDENTIQUE.
     *
     * C'est la seule propriété qui garantisse qu'un export réimporté rend les mêmes
     * chiffres. Un formatage « joli » — séparateur de milliers, virgule décimale — la
     * casserait, et l'écart serait invisible : la prime resterait plausible.
     *
     * @dataProvider compositions
     */
    public function testCeQuiEstEcritSeRelitALIdentique(array $termes): void
    {
        $refus = [];
        $relu = ValeursMultiples::lire(ValeursMultiples::ecrire($termes), $refus);

        self::assertSame([], $refus, 'Notre propre écriture doit se relire sans refus.');
        self::assertEquals($termes, $relu);
    }

    public static function compositions(): iterable
    {
        yield 'des montants' => [[
            'Prime nette' => ValeursMultiples::montant(10000.0),
            'Accessoires' => ValeursMultiples::montant(500.5),
        ]];
        yield 'un défaut et un taux' => [[
            'Commission' => ValeursMultiples::defaut(),
            'Frais de gestion' => ValeursMultiples::taux(2.5),
        ]];
        yield 'un montant et un taux mêlés' => [[
            'Commission' => ValeursMultiples::montant(5000.0),
            'Consultance' => ValeursMultiples::taux(12.0),
        ]];
        yield 'un grand nombre' => [['Prime nette' => ValeursMultiples::montant(1234567.89)]];
        yield 'un zéro, qui est une valeur' => [['Frais accessoires' => ValeursMultiples::montant(0.0)]];
        yield 'un négatif — un ajustement en déduit' => [['Écart' => ValeursMultiples::montant(-0.52)]];
        yield 'rien' => [[]];
    }

    /**
     * ⚠ LE SIGNE « % » PORTE LA NATURE, ET RIEN D'AUTRE NE PEUT LA PORTER.
     *
     * Un revenu déroge soit par son taux, soit par un montant forfaitaire, et le nombre
     * seul ne dit pas lequel : « 12 » est un taux plausible autant qu'un montant. Sur les
     * données réelles du cabinet, la dérogation par MONTANT est même le cas dominant —
     * 85 revenus sur 150, contre un seul par taux. Deviner d'après l'ordre de grandeur
     * aurait donc échoué sur la majorité des lignes, et silencieusement.
     */
    public function testLeSigneDePourcentageDistingueUnTauxDUnMontant(): void
    {
        $refus = [];
        $lu = ValeursMultiples::lire('Commission = 12% ; Consultance = 12', $refus);

        self::assertSame([], $refus);
        self::assertTrue($lu['Commission']['estTaux'], '« 12% » est un taux.');
        self::assertFalse($lu['Consultance']['estTaux'], '« 12 » est un montant.');
        self::assertSame(12.0, $lu['Commission']['valeur']);
        self::assertSame(12.0, $lu['Consultance']['valeur']);
    }

    /**
     * Un terme SANS valeur n'est pas une omission : c'est « applique le défaut ».
     *
     * ⚠ ET CE N'EST PAS UN DÉTAIL. Le taux d'un revenu se résout à la lecture, en cascade,
     * un type marqué « pourcentage du risque » allant chercher celui du risque de
     * l'affaire. L'écrire dans le fichier le FIGERAIT : la commission cesserait de suivre
     * le risque le jour où son taux change.
     */
    public function testUnTermeSansValeurDemandeLeDefaut(): void
    {
        $refus = [];
        $lu = ValeursMultiples::lire('Commission', $refus);

        self::assertSame([], $refus);
        self::assertNull($lu['Commission']['valeur']);
    }

    /**
     * La typographie que les gens tapent réellement, copiée d'Excel ou d'un PDF.
     *
     * Le nettoyage vient de `BordereauLigneNormaliseur::nettoyerNombre()`, qui se déclare
     * source unique de cette interprétation. Ce test vérifie qu'on l'emprunte, et qu'on
     * n'en a pas réécrit une seconde.
     *
     * @dataProvider typographies
     */
    public function testLaTypographieHumaineEstAdmise(string $cellule, float $attendu): void
    {
        $refus = [];
        $lu = ValeursMultiples::lire($cellule, $refus);

        self::assertSame([], $refus, $cellule);
        self::assertSame($attendu, $lu['Prime nette']['valeur']);
    }

    public static function typographies(): iterable
    {
        yield 'points de mille et virgule décimale' => ['Prime nette = 1.234.567,89', 1234567.89];
        yield 'espaces de mille' => ['Prime nette = 9 000', 9000.0];
        yield 'espace insécable' => ["Prime nette = 9\u{00A0}000", 9000.0];
        yield 'virgule décimale seule' => ['Prime nette = 500,5', 500.5];
        yield 'sans espaces autour du signe' => ['Prime nette=500', 500.0];
    }

    /**
     * ⚠ UN TERME ILLISIBLE EST UN REFUS NOMMÉ, JAMAIS UN SILENCE NI UN ZÉRO.
     *
     * `nettoyerNombre` rend 0.0 pour « abc ». Accepter cette valeur écrirait une cotation
     * à laquelle il manque un chargement : une prime fausse, d'aspect parfaitement normal,
     * et le total se recalculerait sans erreur. Le motif doit nommer le terme fautif —
     * « corrigez la cellule » n'aide personne devant soixante colonnes.
     *
     * @dataProvider illisibles
     */
    public function testUnTermeIllisibleEstRefuseEtNomme(string $cellule, string $attenduDansLeMotif): void
    {
        $refus = [];
        $lu = ValeursMultiples::lire($cellule, $refus);

        self::assertCount(1, $refus, 'Un seul motif, et il doit y en avoir un.');
        self::assertStringContainsString($attenduDansLeMotif, $refus[0]);
        self::assertArrayNotHasKey('Commission', $lu, 'Le terme fautif ne doit pas être retenu à zéro.');
    }

    public static function illisibles(): iterable
    {
        yield 'du texte au lieu d\'un nombre' => ['Commission = abc', 'Commission'];
        yield 'un nom manquant' => [' = 500', 'avant le signe'];
        // Le doublon a son propre test : là, le PREMIER terme survit légitimement.
    }

    /**
     * ⚠ LE DOUBLON EST UNE FAUTE DE FRAPPE, ET LE PREMIER TERME SURVIT.
     *
     * « Prime nette = 100 ; Prime nette = 200 », c'est deux primes pour une. Laisser le
     * second écraser le premier en silence donnerait un total faux ; retenir le premier et
     * refuser bruyamment laisse une trace de ce qui a été ignoré.
     */
    public function testLeDoublonNEcrasePasSilencieusementLePremierTerme(): void
    {
        $refus = [];
        $lu = ValeursMultiples::lire('Prime nette = 100 ; Prime nette = 200', $refus);

        self::assertSame(100.0, $lu['Prime nette']['valeur']);
        self::assertNotSame([], $refus);
    }

    /** Une cellule vide n'est pas une erreur : c'est une composition absente. */
    public function testUneCelluleVideNEstPasUneErreur(): void
    {
        foreach ([null, '', '   ', ' ; ; '] as $cellule) {
            $refus = [];
            self::assertSame([], ValeursMultiples::lire($cellule, $refus));
            self::assertSame([], $refus);
        }
    }
}
