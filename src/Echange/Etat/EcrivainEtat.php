<?php

namespace App\Echange\Etat;

use App\Ai\Finance\EconomieTranche;
use App\Ai\Presentation\Colonnes;
use App\Echange\Classeur\EcrivainJsbx;
use App\Echange\Classeur\Manifeste;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * ÉCRIT L'ÉTAT DU PORTEFEUILLE : trois feuilles, une seule table.
 *
 * `_MANIFESTE` (qui, quand, quel périmètre), `_DICTIONNAIRE` (ce que chaque colonne veut
 * dire), et `DONNEES` — une ligne par tranche.
 *
 * ── CE QUI LE DISTINGUE DU CLASSEUR D'ÉCHANGE ───────────────────────────────────────
 * Pas de `_LISTES` : un état en lecture seule n'a aucune liste déroulante à proposer.
 * Pas de ligne de codes techniques masquée : elle n'existe que pour permettre la
 * relecture, qui n'a pas lieu ici. L'en-tête redevient ce qu'il paraît être.
 *
 * ── LE DICTIONNAIRE N'EST PAS UNE POLITESSE ─────────────────────────────────────────
 * ⚠ Il porte la note de `EconomieTranche` — assiette des taxes, définition du TTC,
 * interdiction de proratiser une commission sur un règlement partiel. Ces trois erreurs
 * de lecture ont déjà été commises sur ces mêmes chiffres. Un fichier qui sort du
 * cabinet et qui circule doit les désamorcer, faute de quoi il les propage.
 */
final class EcrivainEtat
{
    /** Cobalt de la marque, comme les autres classeurs de la maison. */
    private const COBALT = EcrivainJsbx::COBALT;

    /** Première ligne de données : l'en-tête n'en occupe qu'une. */
    private const LIGNE_DONNEES = 2;

    /**
     * @param array<string, ColonneEtat>              $colonnes
     * @param iterable<int, array<string, mixed>>     $lignes
     */
    public function ecrire(Manifeste $manifeste, array $colonnes, iterable $lignes): Spreadsheet
    {
        $classeur = new Spreadsheet();
        $classeur->removeSheetByIndex(0);

        $this->ecrireManifeste($classeur, $manifeste);
        $this->ecrireDictionnaire($classeur, $colonnes);
        $this->ecrireDonnees($classeur, $colonnes, $lignes);

        $classeur->setActiveSheetIndex(0);

        return $classeur;
    }

    private function ecrireManifeste(Spreadsheet $classeur, Manifeste $manifeste): void
    {
        $feuille = $classeur->createSheet();
        $feuille->setTitle(EcrivainJsbx::FEUILLE_MANIFESTE);

        $feuille->fromArray(['Clé', 'Information', 'Valeur'], null, 'A1');
        $this->styleEntete($feuille, 'A1:C1');

        $numero = 2;
        foreach ($manifeste->lignes() as [$cle, $libelle, $valeur]) {
            $feuille->fromArray([$cle, $libelle, $valeur], null, 'A' . $numero);
            ++$numero;
        }

        // ⚠ CE FICHIER NE SE REDÉPOSE PAS, et il doit le dire lui-même : quelqu'un qui le
        // retrouve dans six mois n'aura pas l'écran sous les yeux pour l'apprendre.
        $feuille->fromArray(
            ['lecture_seule', 'Nature du fichier', 'État de lecture. Il ne peut pas être réimporté : '
                . 'ses colonnes sont des résultats (soldes, encaissements), pas des champs.'],
            null,
            'A' . $numero,
        );

        $this->ajusterColonnes($feuille, 3);
    }

    /** @param array<string, ColonneEtat> $colonnes */
    private function ecrireDictionnaire(Spreadsheet $classeur, array $colonnes): void
    {
        $feuille = $classeur->createSheet();
        $feuille->setTitle(EcrivainJsbx::FEUILLE_DICTIONNAIRE);

        $feuille->fromArray(['Colonne', 'Nature', 'Ce qu\'elle veut dire'], null, 'A1');
        $this->styleEntete($feuille, 'A1:C1');

        $numero = 2;
        foreach ($colonnes as $colonne) {
            $feuille->fromArray(
                [$colonne->libelle, $colonne->role, $colonne->explication],
                null,
                'A' . $numero,
            );
            ++$numero;
        }

        // La règle du métier, à la fin, en toutes lettres.
        ++$numero;
        $feuille->setCellValue('A' . $numero, 'RÈGLE DU MÉTIER');
        $feuille->getStyle('A' . $numero)->getFont()->setBold(true);
        $feuille->setCellValue('C' . $numero, EconomieTranche::NOTE);
        $feuille->getStyle('C' . $numero)->getAlignment()->setWrapText(true);

        $feuille->getColumnDimension('A')->setWidth(46);
        $feuille->getColumnDimension('B')->setWidth(16);
        $feuille->getColumnDimension('C')->setWidth(110);
    }

    /**
     * @param array<string, ColonneEtat>          $colonnes
     * @param iterable<int, array<string, mixed>> $lignes
     */
    private function ecrireDonnees(Spreadsheet $classeur, array $colonnes, iterable $lignes): void
    {
        $feuille = $classeur->createSheet();
        $feuille->setTitle(EtatDuPortefeuille::FEUILLE);

        $codes = array_keys($colonnes);
        $feuille->fromArray(array_map(static fn (ColonneEtat $c) => $c->libelle, array_values($colonnes)), null, 'A1');

        $derniereLettre = Coordinate::stringFromColumnIndex(\count($codes));
        $this->styleEntete($feuille, 'A1:' . $derniereLettre . '1');

        $numero = self::LIGNE_DONNEES;
        foreach ($lignes as $ligne) {
            foreach ($codes as $index => $code) {
                $valeur = $ligne[$code] ?? null;

                // ⚠ NULL RESTE VIDE. Une tranche non hydratée, une taxe non paramétrée,
                // une affaire sans partenaire : la case reste blanche. Écrire 0 ferait
                // passer une absence pour une valeur, et le total de la colonne serait
                // juste tout en racontant une histoire fausse.
                if ($valeur === null || $valeur === '') {
                    continue;
                }

                $cellule = Coordinate::stringFromColumnIndex($index + 1) . $numero;

                if ($valeur instanceof \DateTimeInterface) {
                    $feuille->setCellValue(
                        $cellule,
                        \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($valeur),
                    );
                    continue;
                }

                // Les références et les identifiants partent en TEXTE explicite : une
                // référence purement numérique deviendrait un nombre, perdrait ses zéros
                // de tête, et ne se retrouverait plus dans le classeur du cabinet.
                if (\is_string($valeur)) {
                    $feuille->setCellValueExplicit(
                        $cellule,
                        $valeur,
                        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING,
                    );
                    continue;
                }

                $feuille->setCellValue($cellule, $valeur);
            }
            ++$numero;
        }

        $this->appliquerFormats($feuille, $colonnes, max($numero - 1, self::LIGNE_DONNEES));
        $this->ajusterColonnes($feuille, \count($codes));

        // Le volet fige l'en-tête ET la colonne d'identifiant : sans elle, on perd la
        // ligne qu'on lit dès qu'on fait défiler vers la droite — et il y a cinquante
        // colonnes à parcourir.
        $feuille->freezePane('B2');
        $feuille->setAutoFilter('A1:' . $derniereLettre . ($numero - 1));
    }

    /**
     * Formats de cellule, dérivés du RÔLE de chaque colonne — jamais devinés d'après son
     * nom. Un montant s'affiche comme un montant, une date comme une date native.
     *
     * @param array<string, ColonneEtat> $colonnes
     */
    private function appliquerFormats(Worksheet $feuille, array $colonnes, int $derniereLigne): void
    {
        $index = 0;
        foreach ($colonnes as $colonne) {
            ++$index;
            $lettre = Coordinate::stringFromColumnIndex($index);
            $plage = $lettre . self::LIGNE_DONNEES . ':' . $lettre . $derniereLigne;

            $format = match ($colonne->role) {
                Colonnes::MONTANT => '#,##0.00',
                Colonnes::DATE => NumberFormat::FORMAT_DATE_DDMMYYYY,
                // ⚠ LE TAUX EST EN POINTS (16 = 16 %), convention unique du projet. Le
                // format « 0.00\\% » AFFICHE le signe sans multiplier par cent : un vrai
                // format pourcentage lirait 16 comme 1 600 %.
                Colonnes::POURCENTAGE => '0.00\\%',
                default => null,
            };

            if ($format !== null) {
                $feuille->getStyle($plage)->getNumberFormat()->setFormatCode($format);
            }

            if ($colonne->aligneeADroite()) {
                $feuille->getStyle($plage)->getAlignment()->setHorizontal('right');
            }
        }
    }

    private function styleEntete(Worksheet $feuille, string $plage): void
    {
        $style = $feuille->getStyle($plage);
        $style->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $style->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF' . self::COBALT);
        $style->getAlignment()->setVertical('center')->setWrapText(true);
        $feuille->getRowDimension(1)->setRowHeight(30);
    }

    /**
     * ⚠ JAMAIS D'AUTO-DIMENSIONNEMENT SUR UNE LARGE TABLE. PhpSpreadsheet mesure alors
     * chaque cellule de chaque colonne : sur cinquante colonnes et mille lignes, c'est
     * cinquante mille mesures, et l'export bascule de deux secondes à plusieurs minutes.
     * Au-delà du seuil, largeur fixe.
     */
    private function ajusterColonnes(Worksheet $feuille, int $nombre): void
    {
        $auto = $nombre <= 12;

        for ($i = 1; $i <= $nombre; ++$i) {
            $dimension = $feuille->getColumnDimension(Coordinate::stringFromColumnIndex($i));
            if ($auto) {
                $dimension->setAutoSize(true);
            } else {
                $dimension->setWidth(20);
            }
        }
    }
}
