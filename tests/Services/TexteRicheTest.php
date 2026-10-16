<?php

namespace App\Tests\Services;

use App\Service\TexteRiche\TexteRiche;
use PHPUnit\Framework\TestCase;

/**
 * UN TEXTE RICHE, LU COMME DU TEXTE.
 *
 * Les champs saisis dans l'éditeur riche (Quill) stockent du HTML. Là où il se lit comme
 * du texte (e-mail, libellé, aperçu), on en garde la substance : ni balises — que Twig
 * afficherait telles quelles —, ni entités affichées deux fois (`&amp;amp;`).
 */
class TexteRicheTest extends TestCase
{
    public function testLesBalisesDisparaissentSansCollerLesMots(): void
    {
        self::assertSame('Un deux trois', TexteRiche::enTexte('<p>Un</p><p>deux<br>trois</p>'));
    }

    public function testLesEntitesSontDecodees(): void
    {
        self::assertSame('R&D « ok »', TexteRiche::enTexte('<p>R&amp;D&nbsp;«&nbsp;ok&nbsp;»</p>'));
    }

    public function testUnTexteSimpleResteInchange(): void
    {
        self::assertSame('a < b et c > d', TexteRiche::enTexte('a < b et c > d'));
    }

    public function testUneChargeNeLaissePlusDeBalise(): void
    {
        $texte = TexteRiche::enTexte('<p>Motif <img src=x onerror=alert(1)></p>');
        self::assertSame('Motif', $texte);
    }

    public function testLeVideResteVide(): void
    {
        self::assertSame('', TexteRiche::enTexte(null));
        self::assertSame('', TexteRiche::enTexte('<p><br></p>'));
    }
}
