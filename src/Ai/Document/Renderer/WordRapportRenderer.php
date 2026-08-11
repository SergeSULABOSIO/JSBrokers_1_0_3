<?php

namespace App\Ai\Document\Renderer;

use App\Ai\Document\DocumentFormat;
use App\Ai\Document\PiedDePage;
use App\Ai\Document\RapportSpec;
use App\Ai\Document\ThemeDocument;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Language;

/**
 * Rapport en VRAI .docx (OOXML), via PHPWord — et non du HTML servi en
 * `application/msword`, comme le fait l'export de bulle.
 *
 * La différence n'est pas cosmétique. Un vrai .docx apporte ce qu'un courtier vient
 * y chercher : des STYLES DE TITRE (donc un volet de navigation et un sommaire
 * automatique), de VRAIES TABLES (qu'on trie, qu'on élargit, qu'on recopie dans un
 * tableur), un PIED DE PAGE RÉPÉTÉ avec la pagination, et l'ouverture sans
 * avertissement de format dans Word, Google Docs et LibreOffice.
 *
 * GARDE-FOU INDISPENSABLE : `setOutputEscapingEnabled(true)`. Sans lui, un « & » ou
 * un « < » dans le texte du modèle produit un fichier CORROMPU — et silencieusement :
 * PHPWord n'émet aucune erreur, c'est Word qui refuse d'ouvrir.
 */
final class WordRapportRenderer implements RapportRendererInterface
{
    private const COBALT = '0047AB';
    private const ENCRE = '1F2937';
    private const GRIS = '6B7280';
    private const FILET = 'DEE2E6';
    /** Fond de la ligne de totaux — le même gris que dans le chat et l'export de bulle. */
    private const TOTAUX_FOND = 'F1F3F5';

    public function __construct(
        private readonly FichierTemporaire $temporaire,
    ) {
    }

    public function format(): DocumentFormat
    {
        return DocumentFormat::Docx;
    }

    /**
     * Le thème est ignoré : PHPWord n'écrit pas de fond de page OOXML, si bien
     * qu'une encre claire donnerait un .docx blanc au texte illisible. Mieux vaut
     * un document clair honnête (cf. DocumentFormat::supporteTheme()).
     */
    public function rendre(RapportSpec $spec, array $sections, PiedDePage $pied, ThemeDocument $theme): string
    {
        // Toute chaîne écrite est échappée à la sérialisation. À poser AVANT de
        // construire le document : c'est un réglage global, lu à l'écriture.
        Settings::setOutputEscapingEnabled(true);

        $word = new PhpWord();
        $word->getSettings()->setThemeFontLang(new Language(Language::FR_FR));
        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(11);

        $this->declarerStyles($word);

        $section = $word->addSection([
            'marginTop' => 1134, 'marginBottom' => 1134,
            'marginLeft' => 1134, 'marginRight' => 1134,
        ]);

        // ── Pied de page RÉPÉTÉ sur chaque feuille, avec pagination.
        $piedDePage = $section->addFooter();
        $piedDePage->addPreserveText(
            $pied->ligne() . ' — page {PAGE} / {NUMPAGES}',
            ['size' => 7, 'color' => self::GRIS],
            ['alignment' => Jc::CENTER, 'spaceBefore' => 0, 'spaceAfter' => 0],
        );

        // ── En-tête du document.
        $section->addText($spec->titre, 'titreDoc', ['spaceAfter' => 60]);
        $section->addText(
            $pied->entreprise . ' — produit le ' . $pied->produitLe->format('d/m/Y à H:i'),
            ['size' => 9, 'color' => self::GRIS],
            ['spaceAfter' => 240],
        );

        $section->addTitle('1. Objet du document', 1);
        $this->paragraphes($section, $spec->problematique);

        $section->addTitle('2. Introduction', 1);
        $this->paragraphes($section, $spec->introduction);

        if ($spec->definitions !== []) {
            $section->addTitle('Définitions des termes employés', 2);
            $table = $section->addTable('tableauRapport');
            foreach ($spec->definitions as $definition) {
                $table->addRow();
                $cellule = $table->addCell(2800, ['bgColor' => 'F6F8FB']);
                $cellule->addText($definition['terme'], ['bold' => true, 'size' => 9.5], 'cellule');
                $table->addCell(6200)->addText($definition['explication'], ['size' => 9.5], 'cellule');
            }
            $section->addTextBreak(1);
        }

        $section->addTitle('3. Résultat', 1);
        foreach ($sections as $index => $bloc) {
            if (($bloc['titre'] ?? '') !== '') {
                $section->addTitle('3.' . ($index + 1) . ' ' . $bloc['titre'], 2);
            }
            foreach ($bloc['blocs'] as $element) {
                $this->bloc($section, $element);
            }
        }

        $section->addTitle('4. Conclusion', 1);
        $this->paragraphes($section, $spec->conclusion);

        return $this->octets($word);
    }

    private function declarerStyles(PhpWord $word): void
    {
        $word->addFontStyle('titreDoc', ['size' => 19, 'bold' => true, 'color' => self::COBALT]);

        // Les styles de TITRE (et non un simple gras) : c'est ce qui alimente le
        // volet de navigation de Word et permet un sommaire automatique.
        $word->addTitleStyle(1, ['size' => 14, 'bold' => true, 'color' => self::COBALT], ['spaceBefore' => 240, 'spaceAfter' => 120]);
        $word->addTitleStyle(2, ['size' => 12, 'bold' => true, 'color' => self::ENCRE], ['spaceBefore' => 180, 'spaceAfter' => 90]);
        $word->addTitleStyle(3, ['size' => 11, 'bold' => true, 'color' => self::COBALT], ['spaceBefore' => 140, 'spaceAfter' => 70]);

        $word->addParagraphStyle('corps', ['spaceAfter' => 120, 'lineHeight' => 1.4, 'alignment' => Jc::BOTH]);
        $word->addParagraphStyle('cellule', ['spaceAfter' => 0, 'spaceBefore' => 0]);
        // Les deux variantes d'alignement des cellules. Un montant calé à droite est
        // ce qui rend une colonne de chiffres comparable d'un coup d'œil : c'est une
        // donnée du tableau, écrite dans son markdown (`---:`), pas un ornement.
        $word->addParagraphStyle('celluleDroite', ['spaceAfter' => 0, 'spaceBefore' => 0, 'alignment' => Jc::END]);
        $word->addParagraphStyle('celluleCentre', ['spaceAfter' => 0, 'spaceBefore' => 0, 'alignment' => Jc::CENTER]);
        $word->addFontStyle('codeMono', ['name' => 'Consolas', 'size' => 9]);
        $word->addParagraphStyle('codeBloc', ['spaceAfter' => 120, 'indentation' => ['left' => 280]]);

        $word->addTableStyle('tableauRapport', [
            'borderColor' => self::FILET,
            'borderSize'  => 6,
            'cellMargin'  => 60,
        ]);
    }

    /** Un paragraphe par ligne : un texte multi-ligne ne doit pas devenir un pavé. */
    private function paragraphes(mixed $section, string $texte): void
    {
        foreach (preg_split('/\n{1,}/', $texte) ?: [] as $ligne) {
            $ligne = trim($ligne);
            if ($ligne !== '') {
                $section->addText($ligne, ['size' => 10.5, 'color' => self::ENCRE], 'corps');
            }
        }
    }

    /** @param array<string, mixed> $bloc */
    private function bloc(mixed $section, array $bloc): void
    {
        switch ($bloc['type']) {
            case 'titre':
                $section->addTitle($bloc['texte'], 3);
                break;

            case 'paragraphe':
                $this->paragraphes($section, $bloc['texte']);
                break;

            case 'code':
                foreach (explode("\n", $bloc['texte']) as $ligne) {
                    $section->addText($ligne, 'codeMono', 'codeBloc');
                }
                break;

            case 'liste':
                foreach ($bloc['items'] as $item) {
                    $section->addListItem($item, 0, ['size' => 10.5, 'color' => self::ENCRE], $bloc['ordonnee'] ? 'multilevel' : null);
                }
                $section->addTextBreak(1);
                break;

            case 'tableau':
                $this->tableau($section, $bloc);
                break;
        }
    }

    /**
     * Un tableau Word. Les lignes arrivent DÉJÀ rectangulaires et accompagnées de
     * leurs alignements et de leur marquage de totaux (cf. RapportAssembleur) : ce
     * qui se joue ici est de les honorer, pas de les recalculer.
     *
     * @param array<string, mixed> $bloc
     */
    private function tableau(mixed $section, array $bloc): void
    {
        $lignes = $bloc['lignes'];
        $entetes = $bloc['entetes'];
        if ($lignes === [] && $entetes === []) {
            return;
        }

        if (($bloc['titre'] ?? null) !== null) {
            $section->addText($bloc['titre'], ['bold' => true, 'size' => 9.5, 'color' => self::GRIS], ['spaceAfter' => 60]);
        }

        $colonnes = max(count($entetes), 0, ...array_map('count', $lignes));
        if ($colonnes < 1) {
            return;
        }
        $largeur = (int) floor(9000 / $colonnes);
        $alignements = (array) ($bloc['alignements'] ?? []);
        $totaux = (array) ($bloc['totaux'] ?? []);

        $table = $section->addTable('tableauRapport');

        if ($entetes !== []) {
            // `tblHeader` : la ligne d'en-tête se répète en haut de chaque page si
            // le tableau se coupe — sans quoi la seconde page devient illisible.
            $table->addRow(null, ['tblHeader' => true]);
            for ($i = 0; $i < $colonnes; $i++) {
                $table->addCell($largeur, ['bgColor' => self::COBALT])->addText(
                    (string) ($entetes[$i] ?? ''),
                    ['bold' => true, 'size' => 9.5, 'color' => 'FFFFFF'],
                    $this->styleCellule($alignements[$i] ?? 'left'),
                );
            }
        }

        foreach ($lignes as $rang => $ligne) {
            $estTotaux = (bool) ($totaux[$rang] ?? false);
            $table->addRow();
            for ($i = 0; $i < $colonnes; $i++) {
                $table->addCell($largeur, $estTotaux ? ['bgColor' => self::TOTAUX_FOND] : null)->addText(
                    (string) ($ligne[$i] ?? ''),
                    ['size' => 9.5, 'color' => self::ENCRE, 'bold' => $estTotaux],
                    $this->styleCellule($alignements[$i] ?? 'left'),
                );
            }
        }

        $section->addTextBreak(1);
    }

    /**
     * Le style de paragraphe d'une cellule selon l'alignement de sa colonne. Les
     * trois styles sont déclarés au démarrage plutôt que passés en tableau inline :
     * PHPWord ne fusionne pas un style nommé avec un style ad hoc, et « cellule »
     * porte déjà les espacements sans lesquels les lignes se décollent.
     */
    private function styleCellule(string $alignement): string
    {
        return match ($alignement) {
            'right'  => 'celluleDroite',
            'center' => 'celluleCentre',
            default  => 'cellule',
        };
    }

    /**
     * PHPWord écrit dans un FICHIER (il assemble une archive ZIP), jamais en
     * mémoire. Le détour par un temporaire est donc obligatoire ; le writer est
     * détruit avant la lecture, faute de quoi le handle ZIP reste ouvert sous
     * Windows et on relit une archive tronquée.
     */
    private function octets(PhpWord $word): string
    {
        return $this->temporaire->avec('docx', static function (string $chemin) use ($word): void {
            $writer = IOFactory::createWriter($word, 'Word2007');
            $writer->save($chemin);
            unset($writer);
        });
    }
}
