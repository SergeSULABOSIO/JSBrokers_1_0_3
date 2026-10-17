<?php

namespace App\Tests\Services;

use App\Service\TexteRiche\AttributsDeLaBarreQuill;
use App\Service\TexteRiche\TexteRiche;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * L'ASSAINISSEMENT DU TEXTE RICHE : ce que la barre d'outils de Quill produit, rien d'autre.
 */
class TexteRicheAssainissementTest extends KernelTestCase
{
    private function texteRiche(): TexteRiche
    {
        self::bootKernel();

        return static::getContainer()->get(TexteRiche::class);
    }

    public function testLeGrasResteLaChargeDisparait(): void
    {
        $sortie = $this->texteRiche()->assainir('<p>Clients <strong>prioritaires</strong> <img src=x onerror="alert(1)"><a href="javascript:x">lien</a><script>alert(2)</script></p>');

        self::assertStringContainsString('<strong>prioritaires</strong>', $sortie);
        self::assertStringNotContainsString('<img', $sortie);
        self::assertStringNotContainsString('<a ', $sortie);
        self::assertStringNotContainsString('<script', $sortie);
        self::assertStringNotContainsString('onerror', $sortie);
    }

    public function testLesAttributsDeLaBarreSurviventFiltres(): void
    {
        $sortie = $this->texteRiche()->assainir(
            '<p class="ql-align-center modal"><span style="color: rgb(230, 0, 0); position: fixed">rouge</span></p>'
            . '<ol><li data-list="bullet" onclick="x"><span class="ql-ui" contenteditable="true"></span>a</li></ol>',
        );

        self::assertStringContainsString('<p class="ql-align-center">', $sortie, 'Seules les classes ql-* restent.');
        self::assertStringContainsString('style="color: rgb(230, 0, 0)"', $sortie, 'La couleur reste, le positionnement part.');
        self::assertStringContainsString('data-list="bullet"', $sortie);
        self::assertStringContainsString('contenteditable="false"', $sortie);
        self::assertStringNotContainsString('onclick', $sortie);
        self::assertStringNotContainsString('position', $sortie);
    }

    public function testUnTexteNeGonflePas(): void
    {
        $sortie = $this->texteRiche()->assainir('<p>a "b" c@d.com 3+4=7 &amp; x &lt; y</p>');

        self::assertSame('<p>a "b" c@d.com 3+4=7 &amp; x &lt; y</p>', $sortie, 'Les caractères inoffensifs ne sont pas encodés ; & et < le restent.');
    }

    public function testLaReconnaissanceDuTexteRiche(): void
    {
        self::assertTrue(TexteRiche::estRiche('<p>a</p>'));
        self::assertTrue(TexteRiche::estRiche(' <ol><li>a</li></ol>'));
        self::assertFalse(TexteRiche::estRiche('Nom <img src=x onerror=alert(1)>'), 'Un texte simple n\'est jamais lu comme du HTML.');
        self::assertFalse(TexteRiche::estRiche('<img src=x onerror=alert(1)>'));
        self::assertFalse(TexteRiche::estRiche(null));
    }

    /** Revue C6 : une apostrophe coûtait 5 caractères (`&#039;`) — 422 sur un texte sans mise en forme. */
    public function testUneApostropheNeGonflePas(): void
    {
        self::assertSame("<p>l'assuré d'Alice</p>", $this->texteRiche()->assainir("<p>l'assuré d'Alice</p>"));
    }

    /**
     * Revue C6 : un élément refusé partait AVEC son texte. Une valeur existante contenant un
     * lien collé perdait « le contrat » au premier flush venu (changement de statut, Ket).
     */
    public function testUneBaliseRefuseeLaisseSonTexte(): void
    {
        $sortie = $this->texteRiche()->assainir('<p>Voir <a href="https://x.cd">le contrat</a> ici <code>art. 5</code></p><div>suite importante</div>');

        self::assertStringNotContainsString('<a', $sortie);
        self::assertStringNotContainsString('<div', $sortie);
        foreach (['le contrat', 'art. 5', 'suite importante'] as $texte) {
            self::assertStringContainsString($texte, $sortie);
        }
        // Le contenu d'un script, lui, n'est pas du texte à garder.
        self::assertStringNotContainsString('alert', $this->texteRiche()->assainir('<p>a<script>alert(1)</script></p>'));
    }

    /** Revue C6 : au-delà de 20 000 octets, l'entrée était tronquée — ou vidée si la coupe tombait dans un « é ». */
    public function testUnLongTexteNEstNiTronqueNiVide(): void
    {
        $long = '<p>' . str_repeat('é', 15000) . ' fin</p>';

        self::assertSame($long, $this->texteRiche()->assainir($long));
    }

    /** Revue C6 : une espace insécable en tête — reconnue par le navigateur, pas par le serveur. */
    public function testUneEspaceInsecableEnTeteResteDuTexteRiche(): void
    {
        self::assertTrue(TexteRiche::estRiche("\u{00A0}<p>a</p>"));
        self::assertTrue(TexteRiche::estRiche("\u{FEFF}<p>a</p>"));
    }

    public function testLesFiltresDeValeurs(): void
    {
        self::assertSame('ql-align-center ql-ui', AttributsDeLaBarreQuill::classes('ql-align-center modal ql-ui'));
        self::assertSame('background-color: #ffff00', AttributsDeLaBarreQuill::style('background-color: #ffff00; background: url(javascript:x)'));
        self::assertSame('', AttributsDeLaBarreQuill::style('color: red'), 'Un nom de couleur n\'est pas produit par la barre.');
    }
}
