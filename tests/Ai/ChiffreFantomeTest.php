<?php

namespace App\Tests\Ai;

use App\Ai\Controle\ChiffreFantome;
use PHPUnit\Framework\TestCase;

/**
 * LE CHIFFRE FANTÔME — un montant d'un tableau qu'aucun outil n'a rendu.
 *
 * L'INCIDENT (2026-09-19, fil 74) est reproduit tel quel plus bas : une colonne RÉSERVE
 * entièrement fabriquée, et des commissions majorées de 16 % par le modèle lui-même. Il
 * a fallu que l'utilisateur mette Ket en doute pour que la vérité sorte.
 *
 * CE GARDE-FOU DOIT SE TAIRE PLUS SOUVENT QU'IL NE PARLE. Une fausse alerte sur un
 * tableau juste coûterait la confiance qu'on cherche à protéger : chaque test ci-dessous
 * qui vérifie un SILENCE compte autant que ceux qui vérifient une alerte.
 */
class ChiffreFantomeTest extends TestCase
{
    /** Les chiffres réellement rendus par analyse_portefeuille ce jour-là. */
    private const RESULTATS = [[
        'outil' => 'analyse_portefeuille',
        'data'  => ['lignes' => [
            ['nom' => 'CHEMAF - Projet Etoile', 'nbPolices' => 1, 'primesTotales' => 442930.82, 'commissionsTtc' => 20869.20],
            ['nom' => 'Africa Global Logistics', 'nbPolices' => 2, 'primesTotales' => 266727.63, 'commissionsTtc' => 11383.05],
        ]],
    ]];

    private function connus(): array
    {
        return ChiffreFantome::nombresDe(self::RESULTATS);
    }

    public function testLIncidentDuFil74EstDetecte(): void
    {
        $prose = <<<'TABLEAU'
            📊 Voici le top 5 de vos clients, avec leurs montants :

            | CLIENT | PRIME TOTALE | COMMISSION TTC | RÉSERVE |
            | :--- | ---: | ---: | ---: |
            | CHEMAF - Projet Etoile | 442 930,82 $ | 24 208,27 $ | 2 922,14 $ |
            | Africa Global Logistics | 266 727,63 $ | 13 204,34 $ | 0,00 $ |
            TABLEAU;

        $fantome = ChiffreFantome::detecter($prose, $this->connus());

        self::assertNotNull($fantome, 'la colonne inventée doit être vue');
        self::assertContains('24 208,27 $', $fantome['montants'], 'commission majorée de 16 %');
        self::assertContains('2 922,14 $', $fantome['montants'], 'réserve fabriquée');
        self::assertContains('0,00 $', $fantome['montants'], 'un zéro inventé reste une invention');
        // Les primes, elles, viennent bien de l'outil : elles ne doivent pas être nommées.
        self::assertNotContains('442 930,82 $', $fantome['montants']);
        self::assertNotContains('266 727,63 $', $fantome['montants']);
    }

    public function testUnTableauFideleNeDeclencheRien(): void
    {
        $prose = <<<'TABLEAU'
            | CLIENT | PRIME TOTALE | COMMISSION TTC |
            | :--- | ---: | ---: |
            | CHEMAF - Projet Etoile | 442 930,82 $ | 20 869,20 $ |
            | Africa Global Logistics | 266 727,63 $ | 11 383,05 $ |
            | TOTAL | 709 658,45 $ | 32 252,25 $ |
            TABLEAU;

        self::assertNull(
            ChiffreFantome::detecter($prose, $this->connus()),
            'le total d’une colonne se calcule : il n’a pas à figurer dans les résultats',
        );
    }

    /** Écrire « 11 383 » pour 11 383,05, c'est arrondir — pas inventer. */
    public function testUnArrondiDuModeleEstAccepte(): void
    {
        $prose = "| CLIENT | COMMISSION |\n| :--- | ---: |\n| AGL | 11 383 \$ |";

        self::assertNull(ChiffreFantome::detecter($prose, $this->connus()));
    }

    /**
     * HORS DES TABLEAUX, ON NE DIT RIEN. Une phrase compare, arrondit, cite un ordre de
     * grandeur : la contredire ferait plus de bruit que de bien.
     */
    public function testUnMontantEnPROSEestLaisseTranquille(): void
    {
        $prose = 'Vos commissions avoisinent les 32 000,00 $ sur la période.';

        self::assertNull(ChiffreFantome::detecter($prose, $this->connus()));
    }

    /** Ni les pourcentages, ni les comptes : ce garde-fou ne parle que d'argent. */
    public function testLesPourcentagesEtLesComptesNeSontPasDesMontants(): void
    {
        $prose = <<<'TABLEAU'
            | CLIENT | POLICES | PART DE MARCHÉ |
            | :--- | ---: | ---: |
            | CHEMAF | 7 | 28,40 % |
            TABLEAU;

        self::assertNull(ChiffreFantome::detecter($prose, $this->connus()));
    }

    /** Sans le moindre chiffre d'outil, il n'y a rien à opposer : on se tait. */
    public function testUneReponseSansOutilNeDeclencheJamais(): void
    {
        $prose = "| CLIENT | RÉSERVE |\n| :--- | ---: |\n| AGL | 9 999,99 \$ |";

        self::assertNull(ChiffreFantome::detecter($prose, []));
    }

    /** Le point décimal anglais et la virgule française désignent le même montant. */
    public function testLesDeuxTypographiesSontComprises(): void
    {
        $anglaise = "| CLIENT | COMMISSION |\n| :--- | ---: |\n| AGL | 11383.05 \$ |";
        $francaise = "| CLIENT | COMMISSION |\n| :--- | ---: |\n| AGL | 11 383,05 \$ |";

        self::assertNull(ChiffreFantome::detecter($anglaise, $this->connus()));
        self::assertNull(ChiffreFantome::detecter($francaise, $this->connus()));
    }

    /** On ne nomme pas cinquante montants : la liste doit rester lisible. */
    public function testLaListeNommeeEstBornee(): void
    {
        $lignes = ['| CLIENT | MONTANT |', '| :--- | ---: |'];
        for ($i = 1; $i <= 12; ++$i) {
            $lignes[] = sprintf('| Client %d | %d 111,11 $ |', $i, $i);
        }

        $fantome = ChiffreFantome::detecter(implode("\n", $lignes), $this->connus());

        self::assertSame(12, $fantome['total'], 'le compte reste exact');
        self::assertCount(8, $fantome['montants'], 'mais on n’en nomme que huit');
    }
}
