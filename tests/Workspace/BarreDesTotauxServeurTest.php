<?php

namespace App\Tests\Workspace;

use App\Services\Canvas\BarreDesTotaux;
use App\Services\Canvas\Provider\Numeric\GrandeurTotalisable;
use App\Services\ServiceMonnaies;
use App\Services\ServiceNombres;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * LES TOTAUX DE LA PAGE SONT CALCULÉS ET FORMATÉS PAR LE SERVEUR (chantier 4, commit 2).
 *
 * Le navigateur additionnait et formatait lui-même, en `fr-FR` même en anglais, et dans un
 * format autre que celui des cellules (« 1 200,00 $US » sous « USD 1 200,00 »). Le serveur
 * rend désormais la barre entière : ses options (libellé, nature), le total de chaque grandeur
 * DÉJÀ FORMATÉ — comme les cellules : code de la monnaie de travail, nombre dans la langue
 * active —, et les valeurs par ligne.
 */
class BarreDesTotauxServeurTest extends KernelTestCase
{
    private function barre(?string $monnaie = 'USD'): BarreDesTotaux
    {
        static::bootKernel();
        $monnaies = $this->createMock(ServiceMonnaies::class);
        $monnaies->method('getCodeMonnaieAffichage')->willReturn($monnaie);

        return new BarreDesTotaux(static::getContainer()->get(ServiceNombres::class), $monnaies);
    }

    private function langue(string $locale): void
    {
        static::getContainer()->get(LocaleSwitcher::class)->setLocale($locale);
    }

    private const PAGE = [
        7 => ['primeTotale' => ['description' => 'Prime Totale', 'value' => 120000, 'nature' => 'montant'],
              'nombrePistes' => ['description' => 'Nb. Pistes', 'value' => 2, 'nature' => 'nombre']],
        9 => ['primeTotale' => ['description' => 'Prime Totale', 'value' => 34567, 'nature' => 'montant'],
              'nombrePistes' => ['description' => 'Nb. Pistes', 'value' => 1001, 'nature' => 'nombre']],
    ];

    public function testLaBarreDitSesOptionsDansLOrdreDesGrandeurs(): void
    {
        $barre = $this->barre()->composer(self::PAGE);

        self::assertSame([
            'primeTotale' => ['libelle' => 'Prime Totale', 'nature' => GrandeurTotalisable::MONTANT],
            'nombrePistes' => ['libelle' => 'Nb. Pistes', 'nature' => GrandeurTotalisable::NOMBRE],
        ], $barre['attributs']);
        self::assertSame([7 => ['primeTotale' => 120000, 'nombrePistes' => 2], 9 => ['primeTotale' => 34567, 'nombrePistes' => 1001]], $barre['valeurs']);
    }

    public function testLesTotauxSontFormatesCommeLesCellulesEnFrancais(): void
    {
        $outil = $this->barre();
        $this->langue('fr');
        $barre = $outil->composer(self::PAGE);

        // 1 200,00 + 345,67 = 1 545,67 — le code de la monnaie devant, comme dans la cellule.
        self::assertSame('USD 1 545,67', $barre['totaux']['primeTotale']);
        // Un compte : entier, sans monnaie.
        self::assertSame('1 003', $barre['totaux']['nombrePistes']);
    }

    public function testEtEnAnglaisDansLaLangueActive(): void
    {
        $outil = $this->barre();
        $this->langue('en');
        $barre = $outil->composer(self::PAGE);

        self::assertSame('USD 1,545.67', $barre['totaux']['primeTotale']);
        self::assertSame('1,003', $barre['totaux']['nombrePistes']);
        $this->langue('fr');
    }

    public function testSansMonnaieConfigureeLeMontantResteLisible(): void
    {
        $outil = $this->barre(null);
        $this->langue('fr');
        self::assertSame('1 545,67', $outil->composer(self::PAGE)['totaux']['primeTotale']);
    }

    public function testUnePageVideNaRienATotaliser(): void
    {
        $barre = $this->barre()->composer([]);
        self::assertSame(['attributs' => [], 'totaux' => [], 'valeurs' => []], $barre);
    }

    /**
     * LE TOTAL DE LA SÉLECTION, ET CE QU'IL COUVRE (chantier 4, commit 3). Des lignes cochées
     * peuvent ne plus faire partie de la liste recalculée (un collègue a modifié une fiche) :
     * le total le DIT, au lieu d'un chiffre partiel présenté comme entier.
     */
    public function testLeTotalDeLaSelectionDitSilEstPartiel(): void
    {
        $outil = $this->barre();
        $this->langue('fr');

        $entiere = $outil->selection(self::PAGE, 2);
        self::assertSame('USD 1 545,67', $entiere['totaux']['primeTotale']);
        self::assertSame(2, $entiere['lignes']);
        self::assertSame('', $entiere['suffixe']);
        self::assertSame('', $entiere['note']);

        $partielle = $outil->selection([7 => self::PAGE[7]], 3);
        self::assertSame('USD 1 200,00', $partielle['totaux']['primeTotale']);
        self::assertSame(' (1 sur 3)', $partielle['suffixe']);
        self::assertStringContainsString('2 lignes cochées ne font plus partie de la liste', $partielle['note']);

        $aucune = $outil->selection([], 1);
        self::assertSame([], $aucune['totaux']);
        self::assertSame(' (0 sur 1)', $aucune['suffixe']);
    }

    /** Un total exact au centime : aucune somme flottante. */
    public function testLaSommeEstExacteAuCentime(): void
    {
        $page = [];
        for ($i = 1; $i <= 20; ++$i) {
            $page[$i] = ['montant' => ['description' => 'Montant', 'value' => 10, 'nature' => 'montant']];
        }
        $this->langue('fr');
        self::assertSame('USD 2,00', $this->barre()->composer($page)['totaux']['montant']);
    }
}
