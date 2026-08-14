<?php

namespace App\Tests\Ai;

use App\Ai\AiText;
use PHPUnit\Framework\TestCase;

/**
 * LA CLÉ DE COMPARAISON DES LIBELLÉS — et le défaut qu'elle répare.
 *
 * Trois normalisations coexistaient dans le module : celle d'AiText, correcte, et
 * deux copies fondées sur `iconv('UTF-8', 'ASCII//TRANSLIT')`. Sous Windows, iconv ne
 * translittère pas : il DÉCOMPOSE. « Société Générale » devenait « soci`et`e
 * g`en`erale », puis « soci et e g en erale » une fois la ponctuation filtrée.
 *
 * Le défaut était invisible parce que deux libellés issus de la BASE continuaient de
 * s'égaler — les artefacts s'annulent des deux côtés. Mais un nom saisi SANS ses
 * accents ne pouvait plus jamais égaler le nom accentué stocké. Conséquences en
 * cascade : la correspondance exacte de ResolveurDeReferences (celle qui empêche
 * « SUNU » de devenir ambigu face à « SUNU IARD RDC ») était morte sur tout nom
 * accentué, et un enregistrement existant pouvait être déclaré introuvable — donc
 * recréé en double.
 */
class AiTextCleTest extends TestCase
{
    /**
     * LE CAS QUI RÉVÈLE LE BUG. Les deux orthographes d'un même client doivent
     * produire la même clé : c'est ce qui permet de dire « il existe déjà ».
     */
    public function testUnNomAccentueEtLeMemeSansAccentsDonnentLaMemeCle(): void
    {
        $this->assertSame(AiText::cle('Societe Generale'), AiText::cle('Société Générale'));
        $this->assertSame('societe generale', AiText::cle('Société Générale'));
    }

    /** Aucun artefact de décomposition : la clé ne contient que des mots. */
    public function testLaCleNeFabriqueAucunMotParasite(): void
    {
        $this->assertSame('societe generale', AiText::cle('SOCIÉTÉ GÉNÉRALE'));
        $this->assertStringNotContainsString(' et e ', AiText::cle('Société Générale'));
    }

    /** Casse, ponctuation et espaces multiples n'entrent pas dans la comparaison. */
    public function testCassePonctuationEtEspacesSontNeutralises(): void
    {
        $this->assertSame(AiText::cle('SUNU IARD RDC'), AiText::cle('sunu-iard-rdc'));
        $this->assertSame(AiText::cle('Police N° 12'), AiText::cle('police  n  12'));
        $this->assertSame('mr jean de dieu', AiText::cle('  Mr. jean de dieu  '));
    }

    /** Deux noms RÉELLEMENT différents ne doivent pas fusionner. */
    public function testDeuxNomsDistinctsGardentDesClesDistinctes(): void
    {
        $this->assertNotSame(AiText::cle('Mbusa Jean de Dieu'), AiText::cle('Mr. jean de dieu'));
        $this->assertNotSame(AiText::cle('SUNU'), AiText::cle('SUNU IARD RDC'));
    }

    /** normalize() garde son contrat d'origine (matching par mots-clés du moteur simulé). */
    public function testNormalizeRetireLesAccentsSansToucherALaPonctuation(): void
    {
        $this->assertSame("l'echeance", AiText::normalize("L'échéance"));
    }
}
