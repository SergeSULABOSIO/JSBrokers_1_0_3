<?php

namespace App\Tests\Ai;

use App\Ai\Export\MessageMarkdownParser;
use PHPUnit\Framework\TestCase;

/**
 * Le parseur d'export est le SECOND lecteur du Markdown de Ket, à côté du
 * renderer client (assets/controllers/assistant-markdown-render.js). La spec
 * normative des deux est le prompt système (AiContextBuilder::toSystemPrompt).
 *
 * Ces tests couvrent une convention du prompt par cas, avec un corpus partagé
 * dans tests/Ai/fixtures/markdown/ — chaque fichier documente une convention et
 * sert d'exemple lisible en cas d'évolution du prompt.
 *
 * Le point le plus important est le dernier groupe : le parseur ne doit JAMAIS
 * produire de HTML, seulement du texte brut. C'est ce qui rend l'export sûr,
 * quel que soit le contenu du message.
 */
class MessageMarkdownParserTest extends TestCase
{
    private MessageMarkdownParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MessageMarkdownParser();
    }

    private function fixture(string $nom): string
    {
        $chemin = __DIR__ . '/fixtures/markdown/' . $nom . '.md';
        self::assertFileExists($chemin);

        return (string) file_get_contents($chemin);
    }

    /** @param array<int, array<string, mixed>> $blocs */
    private function types(array $blocs): array
    {
        return array_column($blocs, 'type');
    }

    /**
     * Texte visible d'une suite de jetons inline — l'équivalent du textContent
     * de la bulle rendue.
     *
     * @param array<int, array<string, mixed>> $inline
     */
    private function texte(array $inline): string
    {
        return implode('', array_map(
            static fn (array $j): string => $j['type'] === 'saut' ? "\n" : (string) $j['texte'],
            $inline
        ));
    }

    // ───────────────────────────────────────────────────────── titres ───

    public function testTitresTousNiveauxSontAplatis(): void
    {
        $blocs = $this->parser->analyser($this->fixture('titres'));

        self::assertSame(['titre', 'paragraphe', 'titre', 'paragraphe'], $this->types($blocs));
        self::assertSame('Situation du portefeuille', $this->texte($blocs[0]['inline']));
        // Miroir du renderer JS : `####` rend le même <p class="aic-md-heading">.
        self::assertSame('Détail par client', $this->texte($blocs[2]['inline']));
    }

    // ───────────────────────────────────────────────────────── inline ───

    public function testGrasItaliqueEtCodeInline(): void
    {
        $blocs = $this->parser->analyser($this->fixture('inline'));

        self::assertCount(1, $blocs);
        $jetons = $blocs[0]['inline'];
        $parType = [];
        foreach ($jetons as $jeton) {
            $parType[$jeton['type']][] = $jeton['texte'];
        }

        self::assertSame(['1 250,00 USD'], $parType['gras']);
        self::assertSame(['en cours'], $parType['italique']);
        self::assertSame(['TRN-2026-014'], $parType['code']);
        self::assertStringContainsString('La prime nette est de', $this->texte($jetons));
    }

    public function testSautDeLigneSimpleEstConserve(): void
    {
        // `breaks: true` côté marked : un retour simple est significatif.
        $blocs = $this->parser->analyser("Première ligne\nSeconde ligne");

        self::assertCount(1, $blocs);
        self::assertSame("Première ligne\nSeconde ligne", $this->texte($blocs[0]['inline']));
        self::assertContains('saut', array_column($blocs[0]['inline'], 'type'));
    }

    public function testLigneVideSepareDeuxParagraphes(): void
    {
        $blocs = $this->parser->analyser("Un\n\nDeux");

        self::assertSame(['paragraphe', 'paragraphe'], $this->types($blocs));
    }

    // ───────────────────────────────────────────────────────── listes ───

    public function testListesAPucesEtNumerotees(): void
    {
        $blocs = $this->parser->analyser($this->fixture('listes'));

        self::assertSame(['paragraphe', 'liste', 'paragraphe', 'liste'], $this->types($blocs));

        self::assertFalse($blocs[1]['ordonnee']);
        self::assertCount(3, $blocs[1]['items']);
        self::assertSame('Relancer le client SONAS sur la tranche échue', $this->texte($blocs[1]['items'][0]));
        // Le gras est bien reconnu À L'INTÉRIEUR d'un item.
        self::assertContains('gras', array_column($blocs[1]['items'][0], 'type'));

        self::assertTrue($blocs[3]['ordonnee']);
        self::assertCount(3, $blocs[3]['items']);
        self::assertSame('Clôturer la tâche', $this->texte($blocs[3]['items'][2]));
    }

    public function testChangementDeTypeFermeLaListe(): void
    {
        $blocs = $this->parser->analyser("- puce\n1. numéro");

        self::assertSame(['liste', 'liste'], $this->types($blocs));
        self::assertFalse($blocs[0]['ordonnee']);
        self::assertTrue($blocs[1]['ordonnee']);
    }

    public function testContinuationIndenteeRejointSonItem(): void
    {
        $blocs = $this->parser->analyser("- premier item\n  suite de l'item\n- second item");

        self::assertCount(2, $blocs[0]['items']);
        self::assertSame("premier item suite de l'item", $this->texte($blocs[0]['items'][0]));
    }

    // ──────────────────────────────────────────────────────── tableau ───

    public function testTableauGfmAvecAlignementsEtPastilles(): void
    {
        $blocs = $this->parser->analyser($this->fixture('tableau'));

        self::assertSame(['tableau'], $this->types($blocs));
        self::assertCount(3, $blocs[0]['entetes']);
        self::assertSame('Prime nette', $this->texte($blocs[0]['entetes'][1]));
        self::assertCount(2, $blocs[0]['lignes']);
        self::assertSame('SONAS', $this->texte($blocs[0]['lignes'][0][0]));

        // Une pastille dans une cellule reste une pastille.
        $statut = $blocs[0]['lignes'][0][2];
        self::assertSame('badge', $statut[0]['type']);
        self::assertSame('success', $statut[0]['variante']);
        self::assertSame('Payée', $statut[0]['texte']);
    }

    public function testBarresDeBordFacultatives(): void
    {
        $blocs = $this->parser->analyser("Client | Prime\n--- | ---\nSONAS | 1200");

        self::assertSame(['tableau'], $this->types($blocs));
        self::assertSame(['Client', 'Prime'], array_map(fn (array $c): string => $this->texte($c), $blocs[0]['entetes']));
    }

    public function testLigneAvecBarreSansSeparateurResteUnParagraphe(): void
    {
        $blocs = $this->parser->analyser("Prime | commission, au choix\nSuite du propos.");

        self::assertSame(['paragraphe'], $this->types($blocs));
    }

    // ─────────────────────────────────────────────────────── pastilles ───

    public function testLesCinqPastillesEtLaDegradationDesAutresLiens(): void
    {
        $blocs = $this->parser->analyser($this->fixture('pastilles'));

        $badges = [];
        foreach ($blocs[0]['inline'] as $jeton) {
            if ($jeton['type'] === 'badge') {
                $badges[$jeton['variante']] = $jeton['texte'];
            }
        }
        self::assertSame(MessageMarkdownParser::BADGE_VARIANTES, array_keys($badges));
        self::assertSame('Aucun impayé', $badges['neutral']);

        // Tout autre lien est du TEXTE : aucun lien cliquable dans ce chat.
        $second = $blocs[1]['inline'];
        self::assertNotContains('badge', array_column($second, 'type'));
        self::assertStringContainsString('le site', $this->texte($second));
        self::assertStringContainsString('une ancre', $this->texte($second));
        self::assertStringNotContainsString('https://example.com', $this->texte($second));
    }

    // ───────────────────────────────────────────────────── graphiques ───

    public function testBlocChartDevientUnBlocDeDonnees(): void
    {
        $blocs = $this->parser->analyser($this->fixture('chart'));

        self::assertSame(['paragraphe', 'chart', 'paragraphe'], $this->types($blocs));
        $chart = $blocs[1];
        self::assertSame('CA encaissé 2026', $chart['titre']);
        self::assertSame('USD', $chart['unite']);
        self::assertStringStartsWith('Commissions encaissées HT', $chart['legende']);
        self::assertSame(['Jan', 'Fév', 'Mar'], $chart['labels']);
        self::assertSame([['label' => 'HT', 'data' => [1200.0, 900.0, 1500.0]]], $chart['series']);
    }

    public function testAliasGraphique(): void
    {
        $md = "```graphique\n{\"labels\":[\"A\"],\"series\":[{\"label\":\"X\",\"data\":[1]}]}\n```";

        self::assertSame(['chart'], $this->types($this->parser->analyser($md)));
    }

    /**
     * @dataProvider chartsInexploitables
     */
    public function testChartInexploitableEstIgnoreSilencieusement(string $md, string $cas): void
    {
        // Miroir du front : construireConfigChart renvoie null et rien n'est monté.
        $blocs = $this->parser->analyser($md);

        self::assertNotContains('chart', $this->types($blocs), $cas);
    }

    public static function chartsInexploitables(): array
    {
        return [
            'JSON cassé' => ["```chart\n{\"labels\":[\"A\",}\n```", 'JSON invalide'],
            'réponse tronquée' => ["```chart\n{\"labels\":[\"A\"],\"series\":[{\"dat", 'flux coupé en plein JSON'],
            'labels vides' => ["```chart\n{\"labels\":[],\"series\":[{\"data\":[1]}]}\n```", 'aucun label'],
            'series vides' => ["```chart\n{\"labels\":[\"A\"],\"series\":[]}\n```", 'aucune série'],
            'series sans data' => ["```chart\n{\"labels\":[\"A\"],\"series\":[{\"label\":\"X\"}]}\n```", 'série sans data'],
            'JSON scalaire' => ["```chart\n42\n```", 'pas un objet'],
            'bloc vide' => ["```chart\n```", 'contenu vide'],
        ];
    }

    public function testFenceNonFermeeMaisJsonCompletRendLeGraphique(): void
    {
        // Miroir du client : marked traite une fence non fermée comme un bloc de
        // code, que le renderer `code()` transforme en hôte de graphique. Si le
        // JSON est exploitable, le document doit montrer la même chose que
        // l'écran — sinon export et bulle divergeraient.
        $md = "Voici :\n\n```chart\n{\"labels\":[\"A\",\"B\"],\"series\":[{\"label\":\"X\",\"data\":[1,2]}]}";

        $blocs = $this->parser->analyser($md);

        self::assertSame(['paragraphe', 'chart'], $this->types($blocs));
        self::assertSame(['A', 'B'], $blocs[1]['labels']);
    }

    public function testSerieTropCourteEstCompleteeAZeroEtTropLongueTronquee(): void
    {
        $md = "```chart\n" . json_encode([
            'labels' => ['A', 'B', 'C'],
            'series' => [['label' => 'X', 'data' => [5]], ['label' => 'Y', 'data' => [1, 2, 3, 4, 5]]],
        ]) . "\n```";

        $chart = $this->parser->analyser($md)[0];

        self::assertSame([5.0, 0.0, 0.0], $chart['series'][0]['data']);
        self::assertSame([1.0, 2.0, 3.0], $chart['series'][1]['data']);
    }

    public function testVirguleDecimaleEtValeursNonNumeriques(): void
    {
        $md = "```chart\n" . json_encode([
            'labels' => ['A', 'B', 'C'],
            'series' => [['label' => 'X', 'data' => ['1,5', 'zéro', null]]],
        ]) . "\n```";

        self::assertSame([1.5, 0.0, 0.0], $this->parser->analyser($md)[0]['series'][0]['data']);
    }

    public function testBornesSixSeriesEtVingtQuatrePoints(): void
    {
        $md = "```chart\n" . json_encode([
            'labels' => array_map(static fn (int $i): string => 'L' . $i, range(1, 30)),
            'series' => array_map(
                static fn (int $i): array => ['label' => 'S' . $i, 'data' => array_fill(0, 30, $i)],
                range(1, 8)
            ),
        ]) . "\n```";

        $chart = $this->parser->analyser($md)[0];

        self::assertCount(24, $chart['labels']);
        self::assertCount(6, $chart['series']);
        self::assertCount(24, $chart['series'][0]['data']);
    }

    public function testTypeInconnuRetombeSurBar(): void
    {
        $md = "```chart\n{\"type\":\"radar\",\"labels\":[\"A\"],\"series\":[{\"data\":[1]}]}\n```";

        self::assertSame('bar', $this->parser->analyser($md)[0]['typeGraphique']);
    }

    public function testSerieSansLabelRecoitUnLibelleParDefaut(): void
    {
        $md = "```chart\n{\"labels\":[\"A\"],\"series\":[{\"data\":[1]}]}\n```";

        self::assertSame('Série', $this->parser->analyser($md)[0]['series'][0]['label']);
    }

    // ────────────────────────────────────────────────── blocs de code ───

    public function testBlocDeCodeNestPasInterprete(): void
    {
        $md = "```php\n\$x = **gras**;\n// [Payée](#success)\n```";
        $blocs = $this->parser->analyser($md);

        self::assertSame(['code'], $this->types($blocs));
        self::assertSame("\$x = **gras**;\n// [Payée](#success)", $blocs[0]['texte']);
    }

    // ──────────────────────────────────────────────── cas limites ───

    public function testContenuVideOuBlancNeProduitAucunBloc(): void
    {
        self::assertSame([], $this->parser->analyser(''));
        self::assertSame([], $this->parser->analyser("\n\n   \n"));
    }

    public function testFinsDeLigneWindows(): void
    {
        $blocs = $this->parser->analyser("## Titre\r\n\r\n- item\r\n");

        self::assertSame(['titre', 'liste'], $this->types($blocs));
        self::assertSame('Titre', $this->texte($blocs[0]['inline']));
        self::assertSame('item', $this->texte($blocs[1]['items'][0]));
    }

    public function testMessageALaLongueurMaximale(): void
    {
        // MAX_MESSAGE_LENGTH du contrôleur : le parseur ne doit ni tronquer ni
        // s'effondrer sur un message plein.
        $blocs = $this->parser->analyser(str_repeat('mot ', 1000));

        self::assertSame(['paragraphe'], $this->types($blocs));
        self::assertSame(4000, mb_strlen($this->texte($blocs[0]['inline'])));
    }

    public function testMarkdownPartielResteDuTexte(): void
    {
        // Emphase non fermée : affichée telle quelle, jamais d'exception.
        $blocs = $this->parser->analyser('un **gras non fermé et un `code non fermé');

        self::assertSame(['paragraphe'], $this->types($blocs));
        self::assertSame('un **gras non fermé et un `code non fermé', $this->texte($blocs[0]['inline']));
    }

    // ────────────────────────────────────────────────────── injection ───

    public function testLeHtmlDuMessageResteDuTexteBrut(): void
    {
        $blocs = $this->parser->analyser($this->fixture('injection'));

        // Le HTML est présent, mais comme TEXTE : c'est le fragment Twig, sous
        // autoescape, qui l'échappera. Le parseur ne décide rien du rendu.
        $aplati = json_encode($blocs, JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('alert(1)', (string) $aplati);

        foreach ($this->tousLesJetons($blocs) as $jeton) {
            self::assertContains(
                $jeton['type'],
                ['texte', 'gras', 'italique', 'code', 'badge', 'saut'],
                'Type de jeton inline inattendu : ' . $jeton['type']
            );
        }
    }

    public function testAucunBlocNePorteDeHtmlGenere(): void
    {
        // Garde-fou structurel : la seule clé pouvant contenir du « < » est du
        // texte de message, jamais une balise fabriquée par le parseur.
        $blocs = $this->parser->analyser($this->fixture('injection'));

        foreach ($blocs as $bloc) {
            self::assertArrayHasKey('type', $bloc);
            self::assertNotSame('html', $bloc['type']);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $blocs
     * @return array<int, array<string, mixed>>
     */
    private function tousLesJetons(array $blocs): array
    {
        $jetons = [];
        foreach ($blocs as $bloc) {
            if (isset($bloc['inline'])) {
                $jetons = array_merge($jetons, $bloc['inline']);
            }
            foreach ($bloc['items'] ?? [] as $item) {
                $jetons = array_merge($jetons, $item);
            }
            foreach ($bloc['entetes'] ?? [] as $cellule) {
                $jetons = array_merge($jetons, $cellule);
            }
            foreach ($bloc['lignes'] ?? [] as $ligne) {
                foreach ($ligne as $cellule) {
                    $jetons = array_merge($jetons, $cellule);
                }
            }
        }

        return $jetons;
    }
}
