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

        // ⚠ PAS DE FEUILLE `_MANIFESTE`. L'état ne se relit pas : il n'a besoin ni
        // d'empreinte ni de périmètre déclaré pour être reconnu. Le manifeste reste
        // CONSTRUIT — son empreinte alimente l'occurrence facturée — mais il n'est plus
        // écrit. Ce qu'il portait d'utile au lecteur (« ce fichier ne se redépose pas »)
        // ouvre désormais le dictionnaire.
        $this->ecrireDictionnaire($classeur, $colonnes);
        $this->ecrireDonnees($classeur, $colonnes, $lignes);

        $classeur->setActiveSheetIndex(0);

        return $classeur;
    }

    /** @param array<string, ColonneEtat> $colonnes */
    private function ecrireDictionnaire(Spreadsheet $classeur, array $colonnes): void
    {
        $feuille = $classeur->createSheet();
        $feuille->setTitle(EcrivainJsbx::FEUILLE_DICTIONNAIRE);

        $feuille->fromArray(['Colonne', 'Nature', 'Ce qu\'elle veut dire'], null, 'A1');
        $this->styleEntete($feuille, 'A1:C1');

        // ⚠ EN TÊTE, ET PAS AILLEURS : c'est la première chose que doit lire celui qui
        // retrouve ce fichier dans six mois, sans l'écran sous les yeux.
        $feuille->fromArray([
            'LECTURE SEULE',
            'Nature du fichier',
            'Cet état ne peut pas être réimporté : ses colonnes sont des RÉSULTATS '
            . '(soldes, encaissements, exigibilités), pas des champs. Pour préparer des '
            . 'données à importer, utilisez le gabarit vierge de l\'onglet Importer.',
        ], null, 'A2');
        $feuille->getStyle('A2:C2')->getFont()->setBold(true);
        $feuille->getStyle('C2')->getAlignment()->setWrapText(true);

        $numero = 4;
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

        $derniereDonnee = max($numero - 1, self::LIGNE_DONNEES);
        $this->appliquerFormats($feuille, $colonnes, $derniereDonnee);

        // ⚠ LE FILTRE S'ARRÊTE AVANT LES TOTAUX. Les inclure ferait voyager cette ligne
        // au milieu des données au premier tri — un total posé entre deux tranches.
        $feuille->setAutoFilter('A1:' . $derniereLettre . $derniereDonnee);

        $this->ecrireTotaux($feuille, $colonnes, $derniereDonnee, $derniereLettre);
        $this->ajusterColonnes($feuille, \count($codes));

        // Le volet fige l'en-tête ET la colonne d'identifiant : sans elle, on perd la
        // ligne qu'on lit dès qu'on fait défiler vers la droite — et il y a cinquante
        // colonnes à parcourir.
        $feuille->freezePane('B2');
    }

    /**
     * LA LIGNE DE TOTAUX — une FORMULE, jamais un nombre écrit.
     *
     * ⚠ UN TOTAL FIGÉ MENT DÈS QU'ON TOUCHE AU FICHIER. On corrige une cellule, on
     * supprime une ligne, et le nombre du bas continue d'afficher l'ancien, sans que rien
     * ne le signale. Une formule, elle, garde le classeur cohérent avec lui-même — et le
     * lecteur peut vérifier d'un clic d'où sort le chiffre.
     *
     * ⚠ `SUBTOTAL(109;…)` ET NON `SUM(…)` : le code 109 ne somme que les lignes VISIBLES.
     * L'en-tête portant un filtre automatique, filtrer sur un assureur ou sur les seules
     * tranches impayées fait SUIVRE les totaux. Avec `SUM`, ils resteraient ceux du
     * portefeuille entier en face de dix lignes filtrées — le genre de contresens qu'on ne
     * remarque qu'après l'avoir cité en réunion.
     *
     * ⚠ ON NE TOTALISE QUE CE QUI S'ADDITIONNE, et le rôle de la colonne le dit déjà :
     * montants et nombres. Sommer des taux ou des identifiants de tranche produirait un
     * nombre parfaitement calculé et parfaitement absurde.
     *
     * ⚠ ET CE TOTAL N'EST HONNÊTE QUE PARCE QU'UNE LIGNE EST UNE TRANCHE : chacune
     * n'apparaît qu'une fois, rien n'est compté deux fois. C'est la raison même pour
     * laquelle les sinistres, qui vivent à la maille police, ont été écartés de cette
     * feuille.
     *
     * @param array<string, ColonneEtat> $colonnes
     */
    private function ecrireTotaux(Worksheet $feuille, array $colonnes, int $derniereDonnee, string $derniereLettre): void
    {
        $ligne = $derniereDonnee + 1;

        $feuille->setCellValue('A' . $ligne, 'TOTAUX');

        $index = 0;
        foreach ($colonnes as $colonne) {
            ++$index;
            if (!\in_array($colonne->role, Colonnes::ROLES_SOMMABLES, true)) {
                continue;
            }

            $lettre = Coordinate::stringFromColumnIndex($index);
            $feuille->setCellValue(
                $lettre . $ligne,
                sprintf('=SUBTOTAL(109,%s%d:%s%d)', $lettre, self::LIGNE_DONNEES, $lettre, $derniereDonnee),
            );
            // Le format de la colonne s'applique au total : une somme de montants
            // s'affiche comme un montant.
            $feuille->getStyle($lettre . $ligne)->getNumberFormat()->setFormatCode('#,##0.00');
            $feuille->getStyle($lettre . $ligne)->getAlignment()->setHorizontal('right');
        }

        $plage = 'A' . $ligne . ':' . $derniereLettre . $ligne;
        $feuille->getStyle($plage)->getFont()->setBold(true);
        $feuille->getStyle($plage)->getBorders()->getTop()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM);
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
