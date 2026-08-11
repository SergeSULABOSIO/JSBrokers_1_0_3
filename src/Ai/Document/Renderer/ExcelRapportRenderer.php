<?php

namespace App\Ai\Document\Renderer;

use App\Ai\Document\DocumentFormat;
use App\Ai\Document\PiedDePage;
use App\Ai\Document\RapportSpec;
use App\Ai\Document\ThemeDocument;
use App\Services\Bordereau\BordereauLigneNormaliseur;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Rapport en CLASSEUR EXCEL, à DEUX feuilles — décision du propriétaire.
 *
 *  - « Rapport » : le narratif (titre, objet, introduction, définitions, conclusion,
 *    pied de page). Un rapport reste un rapport, même dans un tableur.
 *  - « Données » : chaque tableau du résultat en VRAIES CELLULES.
 *
 * CE QUI FAIT LA VALEUR DE LA SECONDE FEUILLE, et sans quoi elle ne servirait à
 * rien : la coercition numérique. « 12 345,67 » recopié tel quel serait du TEXTE —
 * ni somme, ni tri, ni graphique, et un petit triangle vert dans chaque cellule.
 * On repasse donc chaque valeur par {@see BordereauLigneNormaliseur::nettoyerNombre()},
 * déjà source unique des séparateurs de milliers du projet, et on écrit un vrai
 * nombre quand c'en est un.
 */
final class ExcelRapportRenderer implements RapportRendererInterface
{
    private const COBALT = 'FF0047AB';
    private const GRIS = 'FF6B7280';
    private const FILET = 'FFDEE2E6';
    private const FORMAT_NOMBRE = '#,##0.00';

    public function __construct(
        private readonly FichierTemporaire $temporaire,
    ) {
    }

    public function format(): DocumentFormat
    {
        return DocumentFormat::Xlsx;
    }

    /**
     * Le thème est ignoré : une feuille de calcul ne teinte que ses cellules
     * remplies, et le reste du quadrillage resterait blanc — un demi-thème est pire
     * que pas de thème (cf. DocumentFormat::supporteTheme()).
     */
    public function rendre(RapportSpec $spec, array $sections, PiedDePage $pied, ThemeDocument $theme): string
    {
        $classeur = new Spreadsheet();
        $classeur->getProperties()
            ->setCreator('JS Brokers')
            ->setTitle($spec->titre)
            ->setDescription($spec->problematique);

        $this->feuilleRapport($classeur, $spec, $sections, $pied);
        $this->feuilleDonnees($classeur, $sections);
        $classeur->setActiveSheetIndex(0);

        return $this->temporaire->avec('xlsx', static function (string $chemin) use ($classeur): void {
            $writer = new Xlsx($classeur);
            $writer->save($chemin);
            unset($writer);
            $classeur->disconnectWorksheets();
        });
    }

    /**
     * @param list<array{titre: string, blocs: list<array<string, mixed>>}> $sections
     */
    private function feuilleRapport(Spreadsheet $classeur, RapportSpec $spec, array $sections, PiedDePage $pied): void
    {
        $feuille = $classeur->getActiveSheet();
        $feuille->setTitle('Rapport');
        $feuille->getColumnDimension('A')->setWidth(22);
        $feuille->getColumnDimension('B')->setWidth(96);

        $ligne = 1;

        $feuille->setCellValue('A' . $ligne, $spec->titre);
        $feuille->mergeCells('A' . $ligne . ':B' . $ligne);
        $feuille->getStyle('A' . $ligne)->getFont()->setBold(true)->setSize(16)->getColor()->setARGB(self::COBALT);
        $feuille->getRowDimension($ligne)->setRowHeight(26);
        $ligne += 2;

        $ligne = $this->paragraphe($feuille, $ligne, '1. Objet du document', $spec->problematique);
        $ligne = $this->paragraphe($feuille, $ligne, '2. Introduction', $spec->introduction);

        if ($spec->definitions !== []) {
            $ligne = $this->sousTitre($feuille, $ligne, 'Définitions des termes employés');
            foreach ($spec->definitions as $definition) {
                $feuille->setCellValue('A' . $ligne, $definition['terme']);
                $feuille->setCellValue('B' . $ligne, $definition['explication']);
                $feuille->getStyle('A' . $ligne)->getFont()->setBold(true);
                $feuille->getStyle('B' . $ligne)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
                $ligne++;
            }
            $ligne++;
        }

        // Le narratif des sections. Les TABLEAUX, eux, vont en feuille « Données » :
        // c'est tout l'intérêt d'un classeur, et les mélanger reviendrait à livrer
        // une feuille de prose où personne ne pourrait calculer.
        $ligne = $this->sousTitre($feuille, $ligne, '3. Résultat');
        foreach ($sections as $index => $section) {
            if (($section['titre'] ?? '') !== '') {
                $feuille->setCellValue('A' . $ligne, '3.' . ($index + 1) . ' ' . $section['titre']);
                $feuille->getStyle('A' . $ligne)->getFont()->setBold(true);
                $ligne++;
            }
            foreach ($section['blocs'] as $bloc) {
                if ($bloc['type'] === 'tableau') {
                    $feuille->setCellValue('B' . $ligne, '→ voir la feuille « Données »'
                        . (($bloc['titre'] ?? null) !== null ? ' : ' . $bloc['titre'] : ''));
                    $feuille->getStyle('B' . $ligne)->getFont()->setItalic(true)->getColor()->setARGB(self::GRIS);
                    $ligne++;
                    continue;
                }
                $texte = $this->texteDuBloc($bloc);
                if ($texte === '') {
                    continue;
                }
                $feuille->setCellValue('B' . $ligne, $texte);
                $feuille->getStyle('B' . $ligne)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
                $ligne++;
            }
            $ligne++;
        }

        $ligne = $this->paragraphe($feuille, $ligne, '4. Conclusion', $spec->conclusion);

        $ligne++;
        foreach ($pied->mentions() as [$libelle, $valeur]) {
            $feuille->setCellValue('A' . $ligne, $libelle);
            $feuille->setCellValue('B' . $ligne, $valeur);
            $feuille->getStyle('A' . $ligne . ':B' . $ligne)->getFont()->setSize(9)->getColor()->setARGB(self::GRIS);
            $ligne++;
        }
    }

    /**
     * @param list<array{titre: string, blocs: list<array<string, mixed>>}> $sections
     */
    private function feuilleDonnees(Spreadsheet $classeur, array $sections): void
    {
        $feuille = $classeur->createSheet();
        $feuille->setTitle('Données');

        $ligne = 1;
        $vue = false;

        foreach ($sections as $section) {
            foreach ($section['blocs'] as $bloc) {
                if ($bloc['type'] !== 'tableau') {
                    continue;
                }
                $entetes = $bloc['entetes'];
                $donnees = $bloc['lignes'];
                if ($entetes === [] && $donnees === []) {
                    continue;
                }
                $vue = true;

                $intitule = $bloc['titre'] ?? ($section['titre'] ?: 'Données');
                $feuille->setCellValue('A' . $ligne, $intitule);
                $feuille->getStyle('A' . $ligne)->getFont()->setBold(true)->setSize(12)->getColor()->setARGB(self::COBALT);
                $ligne += 1;

                $colonnes = max(count($entetes), ...array_map('count', $donnees ?: [[]]));

                if ($entetes !== []) {
                    for ($i = 0; $i < $colonnes; $i++) {
                        $cellule = Coordinate::stringFromColumnIndex($i + 1) . $ligne;
                        $feuille->setCellValueExplicit($cellule, (string) ($entetes[$i] ?? ''), DataType::TYPE_STRING);
                        $style = $feuille->getStyle($cellule);
                        $style->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
                        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COBALT);
                    }
                    // Le volet figé garde les en-têtes visibles au défilement — la
                    // première chose qu'on attend d'un tableau de données.
                    $feuille->freezePane('A' . ($ligne + 1));
                    $ligne++;
                }

                $premiere = $ligne;
                foreach ($donnees as $donnee) {
                    for ($i = 0; $i < $colonnes; $i++) {
                        $this->ecrireCellule($feuille, Coordinate::stringFromColumnIndex($i + 1) . $ligne, (string) ($donnee[$i] ?? ''));
                    }
                    $ligne++;
                }

                if ($ligne > $premiere) {
                    $derniere = Coordinate::stringFromColumnIndex($colonnes);
                    $feuille->getStyle('A' . ($entetes !== [] ? $premiere - 1 : $premiere) . ':' . $derniere . ($ligne - 1))
                        ->getBorders()->getAllBorders()
                        ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
                        ->getColor()->setARGB(self::FILET);
                }

                for ($i = 0; $i < $colonnes; $i++) {
                    $feuille->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setAutoSize(true);
                }

                $ligne += 2;
            }
        }

        if (!$vue) {
            $feuille->setCellValue('A1', 'Ce rapport ne comporte aucun tableau de données.');
            $feuille->getStyle('A1')->getFont()->setItalic(true)->getColor()->setARGB(self::GRIS);
            $feuille->getColumnDimension('A')->setWidth(60);
        }
    }

    /**
     * Écrit une cellule en NOMBRE quand c'en est un, en texte sinon. C'est ce qui
     * rend la feuille exploitable : sans cela, « 12 345,67 » resterait du texte.
     *
     * L'UNITÉ SUIT LE NOMBRE. Convertir « 3 195,16 $ » en 3195,16 faisait disparaître
     * le « $ » de la feuille : la valeur restait juste, mais la seule mention de la
     * devise avait été effacée, et le classeur devenait le seul format à ne pas la
     * porter. On la reporte donc dans le FORMAT de la cellule — le nombre reste un
     * nombre (il s'additionne, se trie, se met en graphique) et l'unité s'affiche.
     */
    private function ecrireCellule(mixed $feuille, string $coordonnee, string $valeur): void
    {
        $valeur = trim($valeur);
        if ($valeur === '') {
            return;
        }

        if ($this->estNombre($valeur)) {
            $feuille->setCellValueExplicit($coordonnee, BordereauLigneNormaliseur::nettoyerNombre($valeur), DataType::TYPE_NUMERIC);
            $feuille->getStyle($coordonnee)->getNumberFormat()->setFormatCode($this->formatAvecUnite($valeur));

            return;
        }

        $feuille->setCellValueExplicit($coordonnee, $valeur, DataType::TYPE_STRING);
        $feuille->getStyle($coordonnee)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
    }

    /**
     * Le format d'affichage d'un nombre, unité comprise quand la valeur d'origine en
     * portait une. Le symbole est mis entre guillemets : sans cela, Excel lirait « $ »
     * comme un code de format et non comme un texte à afficher.
     */
    private function formatAvecUnite(string $valeur): string
    {
        if (preg_match('/(%|€|\$|USD|EUR|CDF)\s*$/iu', $valeur, $trouve) !== 1) {
            return self::FORMAT_NOMBRE;
        }

        $unite = strtoupper($trouve[1]) === $trouve[1] ? $trouve[1] : mb_strtoupper($trouve[1]);

        // Le pourcentage se colle au nombre, une devise s'en sépare d'une espace.
        return $unite === '%'
            ? self::FORMAT_NOMBRE . '"%"'
            : self::FORMAT_NOMBRE . ' "' . $unite . '"';
    }

    /**
     * Un nombre au sens du tableur : chiffres, séparateurs de milliers, décimale
     * française ou anglaise, signe, pourcentage ou devise accolée.
     *
     * Volontairement STRICT — une référence de police « 12002-330 » ou une date
     * « 01/02/2026 » ne doivent surtout pas devenir des nombres.
     */
    private function estNombre(string $valeur): bool
    {
        $sansEspaces = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $valeur) ?? $valeur;
        $sansDevise = trim(preg_replace('/^[+\-]?|[%€$]|USD|EUR|CDF/iu', '', $sansEspaces) ?? $sansEspaces);

        return $sansDevise !== '' && preg_match('/^\d{1,3}(?:[.,]\d{3})*(?:[.,]\d+)?$|^\d+(?:[.,]\d+)?$/', $sansDevise) === 1;
    }

    private function paragraphe(mixed $feuille, int $ligne, string $titre, string $texte): int
    {
        $ligne = $this->sousTitre($feuille, $ligne, $titre);
        $feuille->setCellValue('B' . $ligne, $texte);
        $feuille->getStyle('B' . $ligne)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);

        return $ligne + 2;
    }

    private function sousTitre(mixed $feuille, int $ligne, string $titre): int
    {
        $feuille->setCellValue('A' . $ligne, $titre);
        $feuille->getStyle('A' . $ligne)->getFont()->setBold(true)->setSize(12)->getColor()->setARGB(self::COBALT);

        return $ligne + 1;
    }

    /** @param array<string, mixed> $bloc */
    private function texteDuBloc(array $bloc): string
    {
        return match ($bloc['type']) {
            'titre', 'paragraphe', 'code' => (string) $bloc['texte'],
            'liste' => implode("\n", array_map(static fn (string $i) => '• ' . $i, $bloc['items'])),
            default => '',
        };
    }
}
