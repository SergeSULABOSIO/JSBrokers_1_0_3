<?php

namespace App\Comptabilite;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @file Export Excel (XLSX) des documents comptables de JS Brokers.
 * @description Produit, depuis les documents calculés par EcritureComptableService,
 * soit une feuille unique (un document), soit un classeur complet (les 7 documents
 * de l'exercice, un onglet chacun). Réutilise PhpSpreadsheet (déjà présent). DRY :
 * chaque type de document a une unique méthode de remplissage, partagée entre
 * l'export unitaire et l'export global.
 */
class ComptaExportService
{
    private const COBALT = '0047AB';

    /** Libellés des onglets / documents (clé d'URL → libellé). */
    public const DOCUMENTS = [
        'journal'     => 'Journal',
        'grand-livre' => 'Grand livre',
        'balance'     => 'Balance générale',
        'resultat'    => 'Compte de résultat',
        'tfr'         => 'Formation du résultat',
        'bilan'       => 'Bilan comparatif',
        'tft'         => 'Flux de trésorerie',
    ];

    public function __construct(private EcritureComptableService $ecritures)
    {
    }

    /**
     * Construit la réponse XLSX : un seul document (`$doc` ∈ clés de DOCUMENTS) ou
     * le classeur complet (`$doc === 'all'`).
     */
    public function export(string $doc, int $exercice): Response
    {
        $documents = $this->ecritures->documents($exercice);
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        if ($doc === 'all') {
            foreach (array_keys(self::DOCUMENTS) as $cle) {
                $this->ajouterFeuille($spreadsheet, $cle, $documents);
            }
            $nom = sprintf('JSBrokers_comptabilite_%d.xlsx', $exercice);
        } else {
            $cle = isset(self::DOCUMENTS[$doc]) ? $doc : 'journal';
            $this->ajouterFeuille($spreadsheet, $cle, $documents);
            $nom = sprintf('JSBrokers_%s_%d.xlsx', $cle, $exercice);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $this->reponse($spreadsheet, $nom);
    }

    /** Ajoute et remplit l'onglet correspondant à un document. */
    private function ajouterFeuille(Spreadsheet $spreadsheet, string $doc, array $documents): void
    {
        $sheet = $spreadsheet->createSheet();
        // Le titre d'onglet Excel est limité à 31 caractères et interdit certains signes.
        $sheet->setTitle(mb_substr(self::DOCUMENTS[$doc], 0, 31));

        match ($doc) {
            'journal'     => $this->feuilleJournal($sheet, $documents),
            'grand-livre' => $this->feuilleGrandLivre($sheet, $documents),
            'balance'     => $this->feuilleBalance($sheet, $documents),
            'resultat'    => $this->feuilleResultat($sheet, $documents),
            'tfr'         => $this->feuilleTfr($sheet, $documents),
            'bilan'       => $this->feuilleBilan($sheet, $documents),
            'tft'         => $this->feuilleTft($sheet, $documents),
        };
    }

    // ===================== Remplissage par document =====================

    private function feuilleJournal(Worksheet $sheet, array $documents): void
    {
        $this->titre($sheet, sprintf('Journal — exercice %d', $documents['exercice']), 6);
        $ligne = $this->entetes($sheet, ['Date', 'Pièce', 'Libellé', 'Compte', 'Débit', 'Crédit'], 3);

        foreach ($documents['journal']['ecritures'] as $e) {
            foreach ($e['lignes'] as $i => $l) {
                $sheet->fromArray([
                    $i === 0 ? $e['date']->format('d/m/Y') : '',
                    $i === 0 ? $e['piece'] : '',
                    $i === 0 ? $e['libelle'] : '',
                    $l['compte'] . ' ' . $l['libelle'],
                    $l['debit'] ?: '',
                    $l['credit'] ?: '',
                ], null, 'A' . $ligne++);
            }
        }

        $this->ligneTotaux($sheet, $ligne, ['', '', '', 'TOTAUX', $documents['journal']['totalDebit'], $documents['journal']['totalCredit']]);
        $this->finaliser($sheet, ['E', 'F']);
    }

    private function feuilleGrandLivre(Worksheet $sheet, array $documents): void
    {
        $this->titre($sheet, sprintf('Grand livre — exercice %d', $documents['exercice']), 6);
        $ligne = 3;

        foreach ($documents['grandLivre'] as $compte) {
            $sheet->setCellValue('A' . $ligne, $compte['compte'] . ' — ' . $compte['libelle']);
            $sheet->getStyle('A' . $ligne)->getFont()->setBold(true);
            $ligne++;

            $ligne = $this->entetes($sheet, ['Date', 'Pièce', 'Libellé', 'Débit', 'Crédit', 'Solde'], $ligne);
            $sheet->fromArray(['', '', 'Report à-nouveau', '', '', $compte['aNouveau']], null, 'A' . $ligne++);
            foreach ($compte['lignes'] as $l) {
                $sheet->fromArray([
                    $l['date']->format('d/m/Y'), $l['piece'], $l['libelle'],
                    $l['debit'] ?: '', $l['credit'] ?: '', $l['solde'],
                ], null, 'A' . $ligne++);
            }
            $this->ligneTotaux($sheet, $ligne, ['', '', 'Totaux / solde', $compte['totalDebit'], $compte['totalCredit'], $compte['solde']]);
            $ligne += 2;
        }

        $this->finaliser($sheet, ['D', 'E', 'F']);
    }

    private function feuilleBalance(Worksheet $sheet, array $documents): void
    {
        $this->titre($sheet, sprintf('Balance générale — exercice %d', $documents['exercice']), 8);
        $ligne = $this->entetes($sheet, ['Compte', 'Libellé', 'Ouv. débit', 'Ouv. crédit', 'Mvt débit', 'Mvt crédit', 'Solde débit', 'Solde crédit'], 3);

        foreach ($documents['balance']['lignes'] as $l) {
            $sheet->fromArray([
                $l['compte'], $l['libelle'],
                $l['ouvD'] ?: '', $l['ouvC'] ?: '', $l['mvtD'] ?: '', $l['mvtC'] ?: '', $l['cloD'] ?: '', $l['cloC'] ?: '',
            ], null, 'A' . $ligne++);
        }

        $t = $documents['balance']['totaux'];
        $this->ligneTotaux($sheet, $ligne, ['', 'TOTAUX', $t['ouvD'], $t['ouvC'], $t['mvtD'], $t['mvtC'], $t['cloD'], $t['cloC']]);
        $this->finaliser($sheet, ['C', 'D', 'E', 'F', 'G', 'H']);
    }

    private function feuilleResultat(Worksheet $sheet, array $documents): void
    {
        $r = $documents['resultat'];
        $this->titre($sheet, sprintf('Compte de résultat — exercice %d', $documents['exercice']), 3);
        $ligne = $this->entetes($sheet, ['Compte', 'Libellé', 'Montant'], 3);

        $sheet->setCellValue('B' . $ligne, 'PRODUITS');
        $sheet->getStyle('B' . $ligne++)->getFont()->setBold(true);
        foreach ($r['produits'] as $p) {
            $sheet->fromArray([$p['compte'], $p['libelle'], $p['montant']], null, 'A' . $ligne++);
        }
        $this->ligneTotaux($sheet, $ligne++, ['', 'Total produits', $r['totalProduits']]);

        $sheet->setCellValue('B' . $ligne, 'CHARGES');
        $sheet->getStyle('B' . $ligne++)->getFont()->setBold(true);
        foreach ($r['charges'] as $c) {
            $sheet->fromArray([$c['compte'], $c['libelle'], $c['montant']], null, 'A' . $ligne++);
        }
        $this->ligneTotaux($sheet, $ligne++, ['', 'Total charges', $r['totalCharges']]);

        $this->ligneTotaux($sheet, $ligne, ['', 'RÉSULTAT NET', $r['resultat']]);
        $this->finaliser($sheet, ['C']);
    }

    private function feuilleTfr(Worksheet $sheet, array $documents): void
    {
        $this->titre($sheet, sprintf('Tableau de formation du résultat — exercice %d', $documents['exercice']), 2);
        $ligne = $this->entetes($sheet, ['Solde intermédiaire de gestion', 'Montant'], 3);

        foreach ($documents['tfr'] as $l) {
            $sheet->fromArray([$l['libelle'], $l['montant']], null, 'A' . $ligne);
            if ($l['solde']) {
                $sheet->getStyle('A' . $ligne . ':B' . $ligne)->getFont()->setBold(true);
            }
            $ligne++;
        }

        $this->finaliser($sheet, ['B']);
    }

    private function feuilleBilan(Worksheet $sheet, array $documents): void
    {
        $this->titre($sheet, sprintf('Bilan comparatif — exercice %d', $documents['exercice']), 3);
        $ligne = $this->entetes($sheet, ['Poste', 'Ouverture', 'Clôture'], 3);

        $sheet->setCellValue('A' . $ligne, 'ACTIF');
        $sheet->getStyle('A' . $ligne++)->getFont()->setBold(true);
        foreach ($documents['bilan']['actif'] as $p) {
            $sheet->fromArray([$p['libelle'], $p['ouverture'], $p['cloture']], null, 'A' . $ligne);
            if ($p['total'] ?? false) {
                $sheet->getStyle('A' . $ligne . ':C' . $ligne)->getFont()->setBold(true);
            }
            $ligne++;
        }

        $ligne++;
        $sheet->setCellValue('A' . $ligne, 'PASSIF');
        $sheet->getStyle('A' . $ligne++)->getFont()->setBold(true);
        foreach ($documents['bilan']['passif'] as $p) {
            $sheet->fromArray([$p['libelle'], $p['ouverture'], $p['cloture']], null, 'A' . $ligne);
            if ($p['total'] ?? false) {
                $sheet->getStyle('A' . $ligne . ':C' . $ligne)->getFont()->setBold(true);
            }
            $ligne++;
        }

        $this->finaliser($sheet, ['B', 'C']);
    }

    private function feuilleTft(Worksheet $sheet, array $documents): void
    {
        $t = $documents['tft'];
        $this->titre($sheet, sprintf('Tableau de flux de trésorerie — exercice %d', $documents['exercice']), 2);
        $ligne = $this->entetes($sheet, ['Poste', 'Montant'], 3);

        $rangs = [
            ['Trésorerie d\'ouverture', $t['ouverture'], true],
            ['Encaissements clients', $t['encaissements'], false],
            ['Décaissements', -$t['decaissements'], false],
            ['Flux de trésorerie d\'exploitation', $t['fluxExploitation'], true],
            ['Flux de trésorerie de financement (apports en capital)', $t['fluxFinancement'], true],
            ['Variation de trésorerie', $t['variation'], true],
            ['Trésorerie de clôture', $t['cloture'], true],
        ];
        foreach ($rangs as [$libelle, $montant, $gras]) {
            $sheet->fromArray([$libelle, $montant], null, 'A' . $ligne);
            if ($gras) {
                $sheet->getStyle('A' . $ligne . ':B' . $ligne)->getFont()->setBold(true);
            }
            $ligne++;
        }

        $this->finaliser($sheet, ['B']);
    }

    // ===================== Helpers de mise en forme =====================

    /** Écrit le titre du document (ligne 1, fusionnée sur `$colonnes` colonnes). */
    private function titre(Worksheet $sheet, string $titre, int $colonnes): void
    {
        $derniere = $this->lettreColonne($colonnes);
        $sheet->setCellValue('A1', $titre);
        $sheet->mergeCells('A1:' . $derniere . '1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB(self::COBALT);
    }

    /**
     * Écrit une ligne d'en-têtes (fond cobalt, texte blanc) et renvoie le numéro de
     * la première ligne de données.
     *
     * @param string[] $entetes
     */
    private function entetes(Worksheet $sheet, array $entetes, int $ligne): int
    {
        $sheet->fromArray($entetes, null, 'A' . $ligne);
        $plage = 'A' . $ligne . ':' . $this->lettreColonne(count($entetes)) . $ligne;
        $style = $sheet->getStyle($plage);
        $style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COBALT);

        return $ligne + 1;
    }

    /** @param array<int, string|float> $valeurs */
    private function ligneTotaux(Worksheet $sheet, int $ligne, array $valeurs): void
    {
        $sheet->fromArray($valeurs, null, 'A' . $ligne);
        $plage = 'A' . $ligne . ':' . $this->lettreColonne(count($valeurs)) . $ligne;
        $sheet->getStyle($plage)->getFont()->setBold(true);
    }

    /** Auto-dimensionne les colonnes et aligne à droite les colonnes de montants. */
    private function finaliser(Worksheet $sheet, array $colonnesMontant): void
    {
        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        foreach ($colonnesMontant as $col) {
            $sheet->getStyle($col . '1:' . $col . $sheet->getHighestRow())
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
    }

    /** Lettre de colonne Excel pour un index 1-based (1 → A, 2 → B…). */
    private function lettreColonne(int $index): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);
    }

    /** Construit la StreamedResponse de téléchargement XLSX. */
    private function reponse(Spreadsheet $spreadsheet, string $nom): Response
    {
        $response = new StreamedResponse(static function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $nom)
        );
        $response->headers->set('Cache-Control', 'max-age=0');

        return $response;
    }
}
