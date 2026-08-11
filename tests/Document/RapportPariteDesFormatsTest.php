<?php

namespace App\Tests\Document;

use App\Ai\Document\DocumentFormat;
use App\Ai\Document\PiedDePage;
use App\Ai\Document\RapportAssembleur;
use App\Ai\Document\RapportSpec;
use App\Ai\Document\Renderer\RapportRendererResolver;
use App\Ai\Document\ThemeDocument;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * PARITÉ DES SIX FORMATS : le même rapport, les mêmes données, partout.
 *
 * ── Pourquoi ce test existe ─────────────────────────────────────────────────────
 * Un rapport téléchargé en Word, en PDF, en Excel ou en texte n'est pas « une
 * variante » : c'est LE MÊME DOCUMENT. Un chiffre présent dans l'un et absent de
 * l'autre transforme le choix du format en loterie — et c'est invisible, puisque
 * personne ne télécharge les six pour comparer.
 *
 * L'audit du 11/08/2026 a trouvé quatre divergences, toutes nées du même endroit :
 * {@see RapportAssembleur} aplatissait les tableaux en ne gardant que les en-têtes
 * et les lignes, jetant au passage l'ALIGNEMENT des colonnes et le marquage de la
 * LIGNE DE TOTAUX que le parseur avait pourtant lus. S'y ajoutaient deux pertes
 * sèches de données : le markdown tronquait les cellules au-delà de la largeur de
 * l'en-tête, le texte brut coupait à quarante caractères.
 *
 * ── Ce que ce test compare, et ce qu'il ne compare pas ──────────────────────────
 * Il compare les DONNÉES, pas la mise en forme : « 3 195,16 $ » devient le nombre
 * 3195,16 dans une cellule Excel, et c'est voulu — c'est même tout l'intérêt du
 * classeur. On vérifie donc la valeur, pas sa graphie.
 *
 * Le PDF n'est pas fouillé : sa sortie est un flux compressé. Il partage son
 * gabarit avec le HTML ({@see \App\Ai\Document\Renderer\PdfRapportRenderer} appelle
 * HtmlRapportRenderer::corps()), donc le contrôle du HTML le couvre par
 * construction ; on ne lui demande ici que d'être un PDF non vide.
 */
class RapportPariteDesFormatsTest extends KernelTestCase
{
    /**
     * Un tableau qui porte TOUS les pièges d'un vrai tableau de Ket :
     * une colonne de montants alignée à droite, une ligne de totaux, une ligne
     * incomplète, une ligne trop longue, et une cellule dépassant quarante
     * caractères.
     */
    private const TABLEAU = <<<'MD'
        | Date | Client | Prime signalée | Commission |
        | --- | :--- | ---: | ---: |
        | 10/08/2026 | Kibali Goldmines SA | 3 195,16 $ | 141,71 $ |
        | 07/08/2026 | CASH MANAGEMENT SOLUTIONS (CMS) SARL BUKAVU | 758 579,10 $ | 88 921,25 $ |
        | 28/07/2026 | Orange RDC SA | 1 080,00 $ |
        | 16/07/2026 | KIN AVIA | 81 392,14 $ | 3 809,44 $ | mention surnuméraire |
        | **TOTAL** | — | 844 246,40 $ | 92 872,40 $ |
        MD;

    /** Les données qui doivent survivre à TOUS les rendus, sans exception. */
    private const DONNEES = [
        'Kibali Goldmines SA',
        'CASH MANAGEMENT SOLUTIONS (CMS) SARL BUKAVU',
        'Orange RDC SA',
        'KIN AVIA',
        'mention surnuméraire',
        '10/08/2026',
        '28/07/2026',
    ];

    /** Les montants, à retrouver en toutes lettres ou en valeur numérique. */
    private const MONTANTS = ['3 195,16', '758 579,10', '88 921,25', '1 080,00', '81 392,14', '844 246,40'];

    private function spec(): RapportSpec
    {
        return RapportSpec::fromArray([
            'titre'         => 'Rapport des signalements de paiements de primes',
            'problematique' => 'Quels paiements ont été signalés sur la période ?',
            'introduction'  => 'Synthèse des règlements de primes enregistrés.',
            'definitions'   => [['terme' => 'Prime signalée', 'explication' => 'Montant versé par l\'assuré.']],
            'sections'      => [
                ['titre' => 'Détail des paiements', 'corps' => "Reprise exhaustive des signalements.\n\n" . self::TABLEAU],
                ['titre' => 'Points de vigilance', 'corps' => "### Sous-titre de section\n\n- Premier point de vigilance\n- Second point de vigilance\n\n```\nECRITURE 6061 / 512\n```"],
            ],
            'conclusion'    => 'Ce rapport récapitule les paiements signalés.',
        ]);
    }

    private function pied(): PiedDePage
    {
        return new PiedDePage(
            'Sté COURTAGE RDC Sarl',
            'Serge SULA BOSIO',
            'Rapport des signalements de paiements de primes',
            new \DateTimeImmutable('2026-08-11 16:57'),
            'Ket',
        );
    }

    private function rendre(DocumentFormat $format): string
    {
        static::bootKernel();
        $conteneur = static::getContainer();
        $spec = $this->spec();

        return $conteneur->get(RapportRendererResolver::class)->pour($format)->rendre(
            $spec,
            $conteneur->get(RapportAssembleur::class)->sections($spec),
            $this->pied(),
            ThemeDocument::Clair,
        );
    }

    /**
     * Le TEXTE d'un rendu, quel que soit son emballage : balises retirées, entités
     * décodées, espaces normalisés — y compris l'espace insécable étroit, qui sépare
     * les milliers et qui ferait échouer une comparaison naïve.
     */
    private function texte(DocumentFormat $format): string
    {
        $octets = $this->rendre($format);

        $brut = match ($format) {
            // Le pied de page d'un .docx vit dans SA propre pièce d'archive : le
            // chercher dans document.xml seul le déclarerait perdu à tort.
            DocumentFormat::Docx => $this->xml($octets, 'word/document.xml') . ' ' . $this->xml($octets, 'word/footer1.xml'),
            DocumentFormat::Xlsx => $this->xml($octets, 'xl/sharedStrings.xml') . ' ' . $this->feuillesXlsx($octets),
            default              => $octets,
        };

        $sansBalises = html_entity_decode(strip_tags($brut), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $sansBalises) ?? $sansBalises;
    }

    /** Le contenu d'une entrée d'archive OOXML, ou '' si elle n'existe pas. */
    private function xml(string $octets, string $entree): string
    {
        $chemin = tempnam(sys_get_temp_dir(), 'parite');
        file_put_contents($chemin, $octets);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($chemin) === true, 'L’archive OOXML doit s’ouvrir.');
        $contenu = $zip->getFromName($entree);
        $zip->close();
        @unlink($chemin);

        return $contenu === false ? '' : $contenu;
    }

    /** Toutes les feuilles d'un classeur concaténées (les nombres y vivent, pas dans sharedStrings). */
    private function feuillesXlsx(string $octets): string
    {
        return $this->xml($octets, 'xl/worksheets/sheet1.xml') . ' ' . $this->xml($octets, 'xl/worksheets/sheet2.xml');
    }

    /**
     * Les valeurs NUMÉRIQUES d'un classeur, arrondies au centime.
     *
     * Un tableur stocke des flottants binaires : 3 195,16 s'écrit
     * « 3195.15999999999985 » dans le XML. Une comparaison de chaînes déclarerait
     * donc le montant perdu alors qu'Excel l'affiche juste — c'est la valeur qu'on
     * compare, pas sa graphie.
     *
     * @return list<string>
     */
    private function nombres(DocumentFormat $format): array
    {
        if ($format !== DocumentFormat::Xlsx) {
            return [];
        }

        $xml = $this->feuillesXlsx($this->rendre($format));
        if (preg_match_all('/<v>(-?\d+(?:\.\d+)?)<\/v>/', $xml, $trouves) < 1) {
            return [];
        }

        return array_map(static fn (string $v) => number_format(round((float) $v, 2), 2, '.', ''), $trouves[1]);
    }

    /** @return iterable<string, array{0: DocumentFormat}> */
    public static function formatsFouillables(): iterable
    {
        yield 'html' => [DocumentFormat::Html];
        yield 'docx' => [DocumentFormat::Docx];
        yield 'xlsx' => [DocumentFormat::Xlsx];
        yield 'md'   => [DocumentFormat::Md];
        yield 'txt'  => [DocumentFormat::Txt];
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * LE TEST CENTRAL : aucune donnée ne disparaît, quel que soit le format choisi.
     *
     * @dataProvider formatsFouillables
     */
    public function testChaqueFormatPorteToutesLesDonnees(DocumentFormat $format): void
    {
        $texte = $this->texte($format);

        foreach (self::DONNEES as $donnee) {
            $attendu = preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $donnee) ?? $donnee;
            self::assertStringContainsString($attendu, $texte, sprintf(
                'Le format %s a perdu « %s ».', $format->value, $donnee,
            ));
        }
    }

    /**
     * Les MONTANTS survivent aussi — en toutes lettres, ou en valeur numérique pour
     * le classeur, qui les convertit à dessein pour qu'ils s'additionnent.
     *
     * @dataProvider formatsFouillables
     */
    public function testChaqueFormatPorteTousLesMontants(DocumentFormat $format): void
    {
        $texte = $this->texte($format);

        $nombres = $this->nombres($format);

        foreach (self::MONTANTS as $montant) {
            $litteral = preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $montant) ?? $montant;
            $numerique = number_format((float) str_replace([' ', ','], ['', '.'], $litteral), 2, '.', '');

            self::assertTrue(
                str_contains($texte, $litteral) || in_array($numerique, $nombres, true),
                sprintf('Le format %s a perdu le montant « %s ».', $format->value, $montant),
            );
        }
    }

    /**
     * La structure narrative imposée est présente PARTOUT : c'est elle qui fait du
     * fichier un rapport et non un tableau exporté.
     *
     * @dataProvider formatsFouillables
     */
    public function testChaqueFormatPorteLaStructureNarrative(DocumentFormat $format): void
    {
        $texte = $this->texte($format);

        foreach ([
            'Rapport des signalements de paiements de primes',
            'Quels paiements ont été signalés sur la période ?',
            'Synthèse des règlements de primes enregistrés.',
            'Prime signalée',
            'Détail des paiements',
            'Points de vigilance',
            'Sous-titre de section',
            'Premier point de vigilance',
            'ECRITURE 6061 / 512',
            'Ce rapport récapitule les paiements signalés.',
            'Serge SULA BOSIO',
        ] as $attendu) {
            self::assertStringContainsString($attendu, $texte, sprintf(
                'Le format %s a perdu « %s ».', $format->value, $attendu,
            ));
        }
    }

    /**
     * L'ALIGNEMENT des colonnes est une DONNÉE, pas une décoration : dans un tableau
     * de montants, c'est ce qui permet de comparer deux chiffres d'un coup d'œil. Il
     * est écrit dans le markdown (`---:`), lu par le parseur, honoré par le chat et
     * par l'export de bulle — le rapport était le seul à le jeter.
     */
    public function testLAlignementDesColonnesSurvitDansLesFormatsQuiSaventLExprimer(): void
    {
        // HTML : un style d'alignement par cellule, à droite pour les deux colonnes
        // de montants (et donc le PDF, qui partage le gabarit).
        $html = $this->rendre(DocumentFormat::Html);
        self::assertStringContainsString('text-align:right', $html);
        self::assertStringContainsString('text-align:left', $html);

        // Markdown : l'alignement vit dans la ligne de séparation. Sans « ---: », le
        // fichier rouvert ailleurs perd l'information pour de bon.
        $md = $this->rendre(DocumentFormat::Md);
        self::assertStringContainsString('---:', $md, 'Le markdown doit réécrire l’alignement GFM.');

        // Texte brut : un montant se cale à DROITE de sa colonne, sinon la colonne de
        // chiffres est illisible — c'est précisément ce qu'on vient y chercher.
        $txt = $this->rendre(DocumentFormat::Txt);
        self::assertMatchesRegularExpression('/ 3 195,16 \$/u', $txt,
            'Un montant doit être précédé de son rembourrage, donc calé à droite.');
    }

    /**
     * La LIGNE DE TOTAUX se distingue. Le parseur la repère (« **TOTAL** »), le chat
     * la met en évidence, l'export de bulle aussi : un chiffre qui résume tout le
     * tableau ne doit pas se lire comme une ligne de données de plus.
     */
    public function testLaLigneDeTotauxEstMiseEnEvidenceLaOuCEstExprimable(): void
    {
        $html = $this->rendre(DocumentFormat::Html);
        self::assertStringContainsString('font-weight:700', $html, 'La ligne de totaux doit être en gras.');

        // Et le mot TOTAL survit partout, sans ses astérisques de markdown.
        foreach ([DocumentFormat::Html, DocumentFormat::Docx, DocumentFormat::Xlsx, DocumentFormat::Md, DocumentFormat::Txt] as $format) {
            self::assertStringContainsString('TOTAL', $this->texte($format), $format->value . ' a perdu la ligne de totaux.');
        }
    }

    /**
     * Une ligne INCOMPLÈTE ne décale pas les colonnes. Le HTML n'ajoutait pas les
     * cellules manquantes : la ligne « Orange RDC SA », qui n'a pas de commission,
     * remontait sa dernière valeur d'une colonne — un montant lu sous le mauvais
     * en-tête, soit le pire défaut possible dans un document comptable.
     */
    public function testUneLigneIncompleteNeDecalePasLesColonnes(): void
    {
        // Le tableau des paiements, et pas celui des définitions qui le précède.
        $tableau = '';
        foreach (explode('<table', $this->rendre(DocumentFormat::Html)) as $morceau) {
            if (str_contains($morceau, 'Kibali Goldmines SA')) {
                $tableau = $morceau;
                break;
            }
        }
        self::assertNotSame('', $tableau, 'Le tableau des paiements doit être rendu.');

        // Autant de cellules que d'en-têtes sur CHAQUE ligne du corps. La largeur
        // retenue est la PLUS GRANDE observée — cinq ici, à cause de la ligne qui
        // porte une cellule de trop : on complète, on ne coupe pas.
        preg_match('/<thead>.*?<\/thead>/su', $tableau, $entete);
        $colonnes = substr_count($entete[0] ?? '', '<th ');
        self::assertSame(5, $colonnes, 'L’en-tête doit être complété à la largeur du tableau.');

        preg_match('/<tbody>.*?<\/tbody>/su', $tableau, $corps);
        foreach (explode('<tr>', $corps[0] ?? '') as $ligne) {
            if (!str_contains($ligne, '<td')) {
                continue;
            }
            self::assertSame($colonnes, substr_count($ligne, '<td'),
                'Chaque ligne doit porter autant de cellules que l’en-tête.');
        }
    }

    /** Le PDF reste un PDF : le contrôle de son contenu passe par le gabarit HTML. */
    public function testLePdfEstProduitEtNonVide(): void
    {
        $octets = $this->rendre(DocumentFormat::Pdf);

        self::assertStringStartsWith('%PDF', $octets);
        self::assertGreaterThan(2000, strlen($octets));
    }
}
