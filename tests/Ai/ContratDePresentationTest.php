<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Ai\AiRequest;
use App\Ai\Presentation\Colonnes;
use App\Ai\Presentation\TableauMarkdown;
use App\Ai\Scope\AiScope;
use App\Entity\Entreprise;
use App\Entity\Invite;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LE CONTRAT DE PRÉSENTATION EST TENU PAR TROIS APPLICATIONS, ET RIEN NE LES RELIAIT.
 *
 * La grammaire de sortie de Ket est une spec fermée dont trois fichiers dépendent :
 *  1. le PROMPT (AiContextBuilder::reglesDeMiseEnForme) — ce que Ket doit écrire ;
 *  2. le RENDU DU CHAT (assets/controllers/assistant-markdown-table.js) — ce que le
 *     navigateur en fait, plus la feuille de style de templates/components/
 *     _assistant_ia_chat.html.twig qui donne un sens visuel aux classes ;
 *  3. l'EXPORT (App\Ai\Export\MessageMarkdownParser) — ce qu'en font le PDF, le Word
 *     et l'e-mail.
 *
 * Jusqu'ici la synchronisation reposait sur une phrase de docblock (« mettre à jour les
 * deux implémentations »). Le défaut du 2026-08-10 montre ce que ça vaut : l'alignement
 * GFM était écrit dans la grammaire, documenté dans la fixture, compris par le parseur —
 * et jeté par la sanitisation, sans que rien n'échoue. Ce test rend cette classe de
 * dérive impossible : une valeur d'alignement produite par PHP sans classe côté JS, ou
 * une classe sans style, échoue ici.
 *
 * Le pendant côté navigateur est tests/js/assistant-markdown-table.test.mjs.
 */
class ContratDePresentationTest extends KernelTestCase
{
    private const MODULE_JS = __DIR__ . '/../../assets/controllers/assistant-markdown-table.js';
    private const FEUILLE_CHAT = __DIR__ . '/../../templates/components/_assistant_ia_chat.html.twig';

    /** Les classes que le module JS émet, lues dans sa table d'alignement. */
    private function classesDuModuleJs(): array
    {
        $source = (string) file_get_contents(self::MODULE_JS);
        self::assertNotSame('', $source, 'Le module de rendu des tableaux est introuvable.');

        preg_match_all("/(\w+):\s*'([\w-]+)'/", $source, $m, PREG_SET_ORDER);
        $classes = [];
        foreach ($m as $paire) {
            $classes[$paire[1]] = $paire[2];
        }

        return $classes;
    }

    /**
     * Tout alignement que le parseur d'export sait produire doit avoir une traduction
     * côté chat — sinon un tableau s'aligne dans le PDF et reste plat dans le chat, ou
     * l'inverse. « left » est le défaut : il ne porte volontairement aucune classe.
     */
    public function testChaqueAlignementProduitParLeParseurALaSaClasseDansLeChat(): void
    {
        $classes = $this->classesDuModuleJs();

        foreach (['right', 'center'] as $alignement) {
            $this->assertArrayHasKey(
                $alignement,
                $classes,
                sprintf('Le parseur d’export produit l’alignement « %s » : le chat doit savoir le rendre.', $alignement),
            );
        }
        $this->assertArrayNotHasKey('left', $classes, 'L’alignement à gauche est le défaut : aucune classe.');
    }

    /**
     * Une classe émise mais non stylée est un alignement silencieusement perdu — le
     * défaut exact que ce chantier corrige, une couche plus bas.
     */
    public function testChaqueClasseEmiseEstStyleeDansLaFeuilleDuChat(): void
    {
        $feuille = (string) file_get_contents(self::FEUILLE_CHAT);

        $attendues = array_values($this->classesDuModuleJs());
        $attendues[] = 'aic-md-total'; // la ligne de totaux, marquée par ligneTableau()

        foreach ($attendues as $classe) {
            $this->assertStringContainsString(
                '.' . $classe,
                $feuille,
                sprintf('La classe « %s » est émise par le rendu mais n’a aucun style : l’effet serait nul.', $classe),
            );
        }
    }

    /**
     * L'allowlist de sanitisation ne doit PAS s'être élargie pour faire passer
     * l'alignement : c'est précisément pour cela qu'on traduit en classe. Un `align`
     * autorisé rouvrirait une surface d'attributs sur du contenu produit par un modèle.
     */
    public function testLaSanitisationNAutorisePasDAutreAttributQueClass(): void
    {
        $renderer = (string) file_get_contents(__DIR__ . '/../../assets/controllers/assistant-markdown-render.js');

        $this->assertMatchesRegularExpression(
            "/ALLOWED_ATTR\s*=\s*\['class'\]/",
            $renderer,
            'L’alignement passe par une classe justement pour ne PAS élargir l’allowlist.',
        );
    }

    /**
     * Le PROMPT et le RENDU décrivent la même chose. On vérifie que le contrat enseigne
     * bien les deux marqueurs dont le rendu dépend : la syntaxe d'alignement GFM et la
     * première cellule « **TOTAL** » qui déclenche le marquage de la ligne de totaux.
     */
    public function testLePromptEnseigneExactementCeQueLeRenduSaitHonorer(): void
    {
        static::bootKernel();
        $prompt = static::getContainer()->get(AiContextBuilder::class)->toSystemPrompt(new AiRequest(
            systemContext: [
                'assistantNom'   => 'Ket',
                'entrepriseNom'  => 'PHPUnit Contrat SARL',
                'perimetre'      => [],
                'date'           => '2026-08-11',
                'objetsAttaches' => [],
            ],
            messages: [],
            scope: new AiScope(new Entreprise(), new Invite()),
        ));

        $this->assertStringContainsString('---:', $prompt, 'Sans la syntaxe, le rendu n’a rien à aligner.');
        $this->assertStringContainsString('**TOTAL**', $prompt, 'C’est ce marqueur que ligneTableau() reconnaît.');
    }

    /**
     * Le rendu serveur produit lui aussi ces marqueurs : c'est ce qui rend le repli PHP
     * (coût nul) indiscernable d'une réponse rédigée par le modèle. Vérifié de bout en
     * bout, du tableau markdown jusqu'aux blocs de l'export.
     */
    public function testLeRenduServeurProduitUnTableauQueLExportSaitRelire(): void
    {
        static::bootKernel();
        $markdown = static::getContainer()->get(TableauMarkdown::class)->rendre(
            [
                ['date' => '2026-08-05', 'client' => 'Fracht Trading Mauritius', 'montant' => 3195.16],
                ['date' => '2026-07-28', 'client' => 'CHEMAF SA', 'montant' => 1381.48],
            ],
            Colonnes::de([
                'date'    => Colonnes::DATE,
                'client'  => Colonnes::TEXTE,
                'montant' => Colonnes::MONTANT,
            ]),
        );

        $blocs = static::getContainer()->get(\App\Ai\Export\MessageMarkdownParser::class)->analyser((string) $markdown);

        $this->assertCount(1, $blocs);
        $this->assertSame('tableau', $blocs[0]['type']);
        // La colonne de montants est alignée à droite, et l'export le sait.
        $this->assertSame(['left', 'left', 'right'], $blocs[0]['alignements']);
        // La dernière ligne est la ligne de totaux, reconnue comme telle.
        $this->assertSame([false, false, true], $blocs[0]['totaux']);
    }
}
