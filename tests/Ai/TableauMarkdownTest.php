<?php

namespace App\Tests\Ai;

use App\Ai\Presentation\Colonnes;
use App\Ai\Presentation\TableauMarkdown;
use App\Services\ServiceNombres;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * LE TABLEAU DE CHAT, ET LE CONTRAT QU'IL INCARNE.
 *
 * CE QUE CE FICHIER VERROUILLE, ET POURQUOI. La capture du 2026-08-10 montrait un
 * tableau de primes signalées où RIEN de ce qui aide l'œil n'était en place : montants
 * alignés à gauche comme du texte, dates au format d'échange (« 2026-08-05 »), ligne
 * de totaux indiscernable d'une ligne de données. Trois défauts, une seule cause : la
 * mise en forme d'un tableau n'était écrite nulle part — ni comme code, ni comme règle.
 *
 * Ce rendu est désormais la référence commune : le repli PHP l'utilise (coût nul), et
 * le contrat de présentation du prompt demande à Ket de produire exactement la même
 * chose. Chaque assertion ci-dessous vaut donc pour les deux.
 */
class TableauMarkdownTest extends TestCase
{
    private function rendu(): TableauMarkdown
    {
        return new TableauMarkdown(new ServiceNombres(new LocaleSwitcher('fr', [])));
    }

    /** Rien à présenter : pas d'en-tête orphelin. */
    public function testAucuneLigneNeRendAucunTableau(): void
    {
        $this->assertNull($this->rendu()->rendre([]));
    }

    /**
     * LE CŒUR DU CORRECTIF. Une colonne de montants s'aligne à DROITE (« ---: » en
     * GFM) ; une colonne de texte reste à gauche. Sans cette ligne d'alignement, le
     * navigateur n'a rien à honorer et le CSS aligne tout à gauche.
     */
    public function testLesColonnesNumeriquesSAlignentADroite(): void
    {
        $texte = $this->rendu()->rendre(
            [['client' => 'CHEMAF SA', 'montant' => 1381.48]],
            Colonnes::de(['client' => Colonnes::TEXTE, 'montant' => Colonnes::MONTANT]),
        );

        $this->assertStringContainsString('| --- | ---: |', $texte);
    }

    /**
     * Les trois formats que la capture avait tous ratés : la date se lit, le montant
     * porte sa monnaie, et le total se distingue.
     */
    public function testDatesMontantsEtTotalSuiventLeContrat(): void
    {
        $texte = $this->rendu()->rendre(
            [
                ['date' => '2026-08-05', 'client' => 'Fracht Trading Mauritius', 'montant' => 3195.16],
                ['date' => '2026-07-28', 'client' => 'MIC-RC',                   'montant' => 1080.0],
            ],
            Colonnes::de([
                'date'    => Colonnes::DATE,
                'client'  => Colonnes::TEXTE,
                'montant' => Colonnes::MONTANT,
            ]),
        );

        $this->assertStringContainsString('05/08/2026', $texte, 'L’ISO est un format d’échange, pas d’affichage.');
        $this->assertStringNotContainsString('2026-08-05', $texte);
        $this->assertStringContainsString('3 195,16 $', $texte);
        $this->assertStringContainsString('| **TOTAL** |', $texte);
        $this->assertStringContainsString('**4 275,16 $**', $texte, 'Le total porte la même unité que sa colonne.');
    }

    /**
     * L'ORDRE et le CHOIX des colonnes appartiennent à l'outil : il sait ce qui
     * compte, et le rendu n'a pas à en juger. Une clé présente dans les lignes mais
     * absente de la déclaration ne s'affiche pas.
     */
    public function testSeulesLesColonnesDeclareesSontRenduesEtDansLeurOrdre(): void
    {
        $texte = $this->rendu()->rendre(
            [['montant' => 10.0, 'client' => 'ACME', 'description' => 'bruit interne']],
            Colonnes::de(['client' => Colonnes::TEXTE, 'montant' => Colonnes::MONTANT]),
        );

        $lignes = explode("\n", $texte);
        $this->assertSame('| Client | Montant |', $lignes[0]);
        $this->assertStringNotContainsString('bruit interne', $texte);
    }

    /**
     * Un TAUX ne s'additionne jamais : la somme de deux pourcentages n'est pas un
     * pourcentage, et l'afficher ferait douter du reste du tableau. Il s'aligne
     * pourtant à droite, comme tout ce qui se compare chiffre à chiffre.
     */
    public function testUnPourcentageSAligneMaisNeSAdditionneJamais(): void
    {
        $texte = $this->rendu()->rendre(
            [['police' => 'P-1', 'taux' => 16.0], ['police' => 'P-2', 'taux' => 2.0]],
            Colonnes::de(['police' => Colonnes::TEXTE, 'taux' => Colonnes::POURCENTAGE]),
        );

        $this->assertStringContainsString('| --- | ---: |', $texte);
        $this->assertStringContainsString('16 %', $texte);
        $this->assertStringNotContainsString('TOTAL', $texte, 'Aucune colonne sommable : pas de ligne de totaux.');
        $this->assertStringNotContainsString('18 %', $texte);
    }

    /**
     * Un total ne se promet que sur une colonne qui existe ET qui se somme. Déclarer
     * « totaliser » sur un taux ou sur une clé absente ne doit produire aucune ligne
     * vide — une ligne de totaux sans chiffre est pire qu'une absence de totaux.
     */
    public function testUnTotalPromisSurUneColonneNonSommableEstIgnore(): void
    {
        $declaration = Colonnes::de(
            ['police' => Colonnes::TEXTE, 'taux' => Colonnes::POURCENTAGE],
            ['taux', 'colonneInexistante'],
        );

        $this->assertSame([], $declaration['totaliser']);
        $this->assertStringNotContainsString(
            'TOTAL',
            (string) $this->rendu()->rendre([['police' => 'P-1', 'taux' => 16.0]], $declaration),
        );
    }

    /**
     * Sans déclaration, le rendu DÉDUIT — mais timidement : il ne devine jamais qu'un
     * nombre est de l'argent. Coller « $ » à un « soldePrime » afficherait une monnaie
     * que l'outil n'a jamais nommée. Un nombre bien aligné sans unité est honnête.
     */
    public function testLaDeductionNInventeJamaisUneMonnaie(): void
    {
        $texte = $this->rendu()->rendre([
            ['id' => 136, 'soldePrime' => 1200.0],
            ['id' => 137, 'soldePrime' => 800.5],
        ]);

        $this->assertStringContainsString('| Solde prime |', $texte);
        $this->assertStringContainsString('**2 000,50**', $texte);
        $this->assertStringNotContainsString('$', $texte);
        $this->assertStringContainsString('| 136 |', $texte, 'Un identifiant reste brut.');
        $this->assertStringNotContainsString('273', $texte, 'La somme des identifiants n’a aucun sens.');
    }

    /**
     * Le pipe est le séparateur de colonnes : non échappé, une référence qui en
     * contient casse la ligne et décale tout le tableau.
     */
    public function testUnPipeDansUneValeurNeCassePasLaLigne(): void
    {
        $texte = $this->rendu()->rendre(
            [['reference' => 'PRIME|2026|001']],
            Colonnes::de(['reference' => Colonnes::TEXTE]),
        );

        $corps = explode("\n", $texte)[2];
        $this->assertSame('| PRIME/2026/001 |', $corps);
    }

    /**
     * Une valeur structurée n'a pas de rendu de cellule honnête. L'outil qui déclare
     * une telle colonne doit l'aplatir : ici on rend un tiret plutôt qu'« Array ».
     */
    public function testUneValeurStructureeNeDevientJamaisDuTexte(): void
    {
        $texte = $this->rendu()->rendre(
            [['tranche' => ['id' => 7, 'nom' => 'Tranche unique']]],
            Colonnes::de(['tranche' => Colonnes::TEXTE]),
        );

        $this->assertStringNotContainsString('Array', $texte);
        $this->assertStringContainsString('| — |', $texte);
    }

    /** Un tableau de chat ne dépasse pas ce qu'un œil lit : 25 lignes, 7 colonnes. */
    public function testLeTableauEstBorneEnLignesEtEnColonnes(): void
    {
        $lignes = [];
        for ($i = 1; $i <= 40; $i++) {
            $lignes[] = ['id' => $i, 'a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5, 'f' => 6, 'g' => 7, 'h' => 8];
        }

        $rendu = $this->rendu();
        $texte = (string) $rendu->rendre($lignes);

        $this->assertSame(25, $rendu->lignesRendues($lignes));
        $this->assertSame(7, substr_count(explode("\n", $texte)[0], '|') - 1);
        $this->assertStringNotContainsString('| 26 |', $texte);
    }
}
