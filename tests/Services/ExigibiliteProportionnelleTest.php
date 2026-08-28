<?php

namespace App\Tests\Services;

use App\Service\Partage\Exigibilite;
use PHPUnit\Framework\TestCase;

/**
 * CE QUI EST RENTRÉ DOIT RESSORTIR — À DUE PROPORTION.
 *
 * La règle exigeait l'encaissement INTÉGRAL d'une échéance avant que la rétrocommission ne
 * devienne réclamable. Un courtier ayant perçu 60 % de sa commission gardait donc 100 % de
 * la rétro, alors que 60 % de la créance qui la justifie était recouvrée.
 *
 * La formule est éprouvée ICI, sans base ni noyau : c'est une décision de calcul, elle se
 * tient seule. Les deux camps l'appliquent — ils en portaient chacun une copie, et ces
 * copies avaient déjà divergé sur ce qu'elles appelaient « encaissé ».
 */
class ExigibiliteProportionnelleTest extends TestCase
{
    /** Rien d'encaissé, rien de réclamable : la dette n'est pas née. */
    public function testSansEncaissementRienNEstReclamable(): void
    {
        self::assertSame(0.0, Exigibilite::reclamable(35.92, 141.71, 0.0));
        self::assertSame(0.0, Exigibilite::exigible(35.92, 141.71, 0.0, 0.0));
    }

    /**
     * L'ÉCHÉANCE INTÉGRALEMENT ENCAISSÉE REND LA RÉTRO ENTIÈRE.
     *
     * C'est le cas de la capture qui a motivé le lot : commission 141,71 encaissée, solde à
     * zéro, rétro partenaire 35,92. Elle doit être exigible — « il faut payer ».
     */
    public function testLEcheanceSoldeeRendLaRetroEntiere(): void
    {
        self::assertSame(35.92, Exigibilite::reclamable(35.92, 141.71, 141.71));
        self::assertSame(35.92, Exigibilite::exigible(35.92, 141.71, 141.71, 0.0));
    }

    /** À 60 % encaissé, 60 % de la dette est née — ni plus, ni moins. */
    public function testLEncaissementPartielRendUnePartProportionnelle(): void
    {
        // 141,71 × 60 % = 85,03 encaissés ; 35,92 × 60 % = 21,55.
        self::assertSame(21.55, Exigibilite::reclamable(35.92, 141.71, 85.03));
        self::assertSame(21.55, Exigibilite::exigible(35.92, 141.71, 85.03, 0.0));
    }

    /** Ce qui est déjà parti se déduit : l'exigible est ce qui RESTE à sortir. */
    public function testLeDejaVerseSeDeduit(): void
    {
        self::assertSame(15.92, Exigibilite::exigible(35.92, 141.71, 141.71, 20.0));
        // Et le solde suivant devient exigible à mesure que l'argent rentre.
        self::assertSame(1.55, Exigibilite::exigible(35.92, 141.71, 85.03, 20.0));
    }

    /**
     * UN TROP-VERSÉ N'EST PAS UNE CRÉANCE DU CABINET.
     *
     * L'exigible ne descend pas sous zéro : l'afficher en négatif inviterait à
     * « récupérer » un virement déjà parti et comptabilisé.
     */
    public function testUnTropVerseNeRendPasLExigibleNegatif(): void
    {
        self::assertSame(0.0, Exigibilite::exigible(35.92, 141.71, 141.71, 50.0));
    }

    /**
     * SANS COMMISSION ATTENDUE, LA DETTE EST NÉE D'OFFICE.
     *
     * Une affaire à honoraires purs, une échéance sans revenu de l'assureur : il n'y a rien
     * à percevoir. Rendre un ratio de zéro aurait rendu ces rétros éternellement
     * inexigibles — jamais payées, sans que rien ne l'explique.
     */
    public function testSansCommissionAttendueLaDetteEstNeeDOffice(): void
    {
        self::assertSame(1.0, Exigibilite::ratio(0.0, 0.0));
        self::assertSame(35.92, Exigibilite::exigible(35.92, 0.0, 0.0, 0.0));
    }

    /**
     * UN ENCAISSEMENT SUPÉRIEUR AU DÛ NE REND PAS PLUS QUE LA DETTE.
     *
     * Un arrondi, une régularisation : le ratio est plafonné à 1, sans quoi la rétro
     * exigible dépasserait la rétro due.
     */
    public function testLeRatioEstPlafonneAUn(): void
    {
        self::assertSame(1.0, Exigibilite::ratio(141.71, 200.0));
        self::assertSame(35.92, Exigibilite::reclamable(35.92, 141.71, 200.0));
    }

    /** Une rétro nulle ou négative ne devient jamais exigible. */
    public function testUneRetroNulleNeDevientPasExigible(): void
    {
        self::assertSame(0.0, Exigibilite::reclamable(0.0, 141.71, 141.71));
        self::assertSame(0.0, Exigibilite::exigible(-10.0, 141.71, 141.71, 0.0));
    }

    /**
     * LA FORMULE DÉGRADE VERS L'ANCIENNE AUX DEUX BOUTS.
     *
     * C'est ce qui rend le changement sûr : là où le tout-ou-rien répondait, la nouvelle
     * règle répond la même chose. Elle ne diffère qu'entre les deux — là où l'ancienne
     * gardait 100 % d'une dette dont une part était déjà recouvrée.
     */
    public function testAuxDeuxBoutsElleRedonneLAncienComportement(): void
    {
        $due = 141.71;
        $retro = 35.92;

        // Ancien : encaissée >= due ⇒ solde entier.
        self::assertSame($retro, Exigibilite::exigible($retro, $due, $due, 0.0));
        // Ancien : encaissée < due ⇒ zéro… mais seulement à ZÉRO encaissé.
        self::assertSame(0.0, Exigibilite::exigible($retro, $due, 0.0, 0.0));
        // Entre les deux, l'ancienne règle rendait 0 ; la nouvelle rend la part née.
        self::assertGreaterThan(0.0, Exigibilite::exigible($retro, $due, $due / 2, 0.0));
    }
}
