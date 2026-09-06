<?php

namespace App\Echange\Etat;

use App\Ai\Finance\EconomieTranche;
use App\Ai\Presentation\Colonnes;
use App\Echange\Classeur\EcrivainJsbx;
use App\Echange\Classeur\Manifeste;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
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
    public function ecrire(Manifeste $manifeste, array $colonnes, iterable $lignes, string $validite = ValiditeDesTranches::TOUTES, string $exercice = ExerciceDesTranches::TOUS): Spreadsheet
    {
        $classeur = new Spreadsheet();
        $classeur->removeSheetByIndex(0);

        // ⚠ PAS DE FEUILLE `_MANIFESTE`. L'état ne se relit pas : il n'a besoin ni
        // d'empreinte ni de périmètre déclaré pour être reconnu. Le manifeste reste
        // CONSTRUIT — son empreinte alimente l'occurrence facturée — mais il n'est plus
        // écrit. Ce qu'il portait d'utile au lecteur (« ce fichier ne se redépose pas »)
        // ouvre désormais le dictionnaire.
        $this->ecrireDictionnaire($classeur, $colonnes, $validite, $exercice);
        $this->ecrireDonnees($classeur, $colonnes, $lignes);
        $this->ecrireSynthese($classeur, $colonnes, $lignes);

        $classeur->setActiveSheetIndex(0);

        return $classeur;
    }

    /** @param array<string, ColonneEtat> $colonnes */
    private function ecrireDictionnaire(Spreadsheet $classeur, array $colonnes, string $validite, string $exercice): void
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

        // ⚠ QUELLES TRANCHES CE FICHIER PORTE. Un état des seuls PROJETS ressemble trait
        // pour trait à un état de polices : mêmes colonnes, mêmes montants d'allure. Le
        // confondre avec le portefeuille réel, c'est annoncer un chiffre d'affaires qu'on
        // n'a pas. Le fichier doit donc le dire lui-même, et en tête.
        $feuille->fromArray([
            'PÉRIMÈTRE',
            ValiditeDesTranches::libelle($validite),
            ValiditeDesTranches::explication($validite),
        ], null, 'A3');
        $feuille->getStyle('A3:C3')->getFont()->setBold(true);
        $feuille->getStyle('C3')->getAlignment()->setWrapText(true);

        // Même raison que le périmètre : un état d'un seul exercice a exactement l'allure
        // d'un état complet, en plus court.
        $feuille->fromArray([
            'EXERCICE',
            ExerciceDesTranches::libelle($exercice),
            ExerciceDesTranches::explication($exercice),
        ], null, 'A4');
        $feuille->getStyle('A4:C4')->getFont()->setBold(true);
        $feuille->getStyle('C4')->getAlignment()->setWrapText(true);

        $numero = 6;
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
     * LA SYNTHÈSE : mois en lignes, assureurs en sous-lignes, sommes en colonnes.
     *
     * ── POURQUOI DES FORMULES, ET NON UN TABLEAU CROISÉ ─────────────────────────────
     * ⚠ UN VRAI TCD A ÉTÉ TENTÉ, ET RETIRÉ — 06/09/2026. PhpSpreadsheet ne sait pas en
     * écrire ; les parties OOXML posées à la main ont d'abord fait refuser le fichier
     * (« problème dans le contenu »), puis PLANTER Excel. La cause de fond n'était pas le
     * XML mais la vérification : ce poste n'a aucun tableur pour juger, si bien que chaque
     * correctif se validait chez l'utilisateur. `InjecteurDeTcd` reste en place pour le
     * jour où ce sera vérifiable.
     *
     * ── CE QUE CETTE FEUILLE EST, ET CE QU'ELLE N'EST PAS ──────────────────────────
     * ⚠ AUCUN CHIFFRE N'EST CALCULÉ EN PHP. Chaque cellule porte un SOMME.SI.ENS qui
     * pointe sur DONNEES : le tableau se recalcule si l'on corrige une ligne, et l'on
     * vérifie d'un clic d'où sort un montant. Des valeurs figées mentiraient dès la
     * première retouche, sans que rien ne le signale.
     *
     * En contrepartie : pas de champs déplaçables ni de repli natif. La plage de DONNEES
     * est donc posée en TABLEAU EXCEL nommé, pour que « Insertion › Tableau croisé
     * dynamique » propose la source d'un clic à qui en veut un vrai.
     *
     * @param array<string, ColonneEtat>          $colonnes
     * @param array<int, array<string, mixed>>    $lignes
     */
    private function ecrireSynthese(Spreadsheet $classeur, array $colonnes, array $lignes): void
    {
        $codes = array_keys($colonnes);
        $lettre = static function (string $code) use ($codes): ?string {
            $rang = array_search($code, $codes, true);

            return $rang === false ? null : Coordinate::stringFromColumnIndex((int) $rang + 1);
        };

        $colMois = $lettre('policeMoisEffet');
        $colAssureur = $lettre('assureur');

        // Les sommes de la capture, dans son ordre. Une colonne retirée par l'utilisateur
        // disparaît d'elle-même : on ne somme jamais ce que le fichier ne porte pas.
        $mesures = [];
        foreach ([
            'primeTotale', 'primePayee', 'primeSolde',
            'commissionTtc', 'commissionEncaissee', 'commissionSolde', 'commissionExigible',
        ] as $code) {
            $col = $lettre($code);
            if ($col !== null) {
                $mesures[$code] = ['lettre' => $col, 'titre' => 'Somme de ' . $colonnes[$code]->libelle];
            }
        }

        // Sans axe ni mesure, il n'y a rien à synthétiser : on n'ajoute pas une feuille
        // vide qui laisserait croire à un défaut.
        if (($colMois === null && $colAssureur === null) || $mesures === [] || $lignes === []) {
            return;
        }

        $feuille = $classeur->createSheet();
        $feuille->setTitle(InjecteurDeTcd::FEUILLE);

        $derniereDonnee = self::LIGNE_DONNEES + \count($lignes) - 1;

        $feuille->setCellValue('A1', 'Synthèse du portefeuille');
        $feuille->getStyle('A1')->getFont()->setBold(true)->setSize(14)
            ->getColor()->setARGB('FF' . self::COBALT);

        // ── L'en-tête ───────────────────────────────────────────────────────────────
        $ligne = 3;
        $feuille->setCellValue('A' . $ligne, 'Étiquettes de lignes');
        $rang = 2;
        foreach ($mesures as $mesure) {
            $feuille->setCellValue(Coordinate::stringFromColumnIndex($rang) . $ligne, $mesure['titre']);
            ++$rang;
        }
        $derniereColonne = Coordinate::stringFromColumnIndex(\count($mesures) + 1);
        $this->styleEntete($feuille, 'A3:' . $derniereColonne . '3');

        // ── Les groupes, dans l'ordre du calendrier puis de l'alphabet ──────────────
        $groupes = $this->grouper($lignes, $colMois === null ? null : 'policeMoisEffet', $colAssureur === null ? null : 'assureur');

        ++$ligne;
        foreach ($groupes as $mois => $assureurs) {
            $ligneDuMois = $ligne;
            $feuille->setCellValue('A' . $ligne, $mois);
            $this->poserLesSommes($feuille, $ligne, $mesures, $derniereDonnee, [
                [$colMois, 'A' . $ligneDuMois],
            ]);
            $feuille->getStyle('A' . $ligne . ':' . $derniereColonne . $ligne)->getFont()->setBold(true);
            $feuille->getStyle('A' . $ligne . ':' . $derniereColonne . $ligne)->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE8F0FB');
            ++$ligne;

            foreach ($assureurs as $assureur) {
                // L'indentation dit la hiérarchie sans qu'on ait à la répéter en mots.
                $feuille->setCellValue('A' . $ligne, $assureur);
                $feuille->getStyle('A' . $ligne)->getAlignment()->setIndent(2);
                $this->poserLesSommes($feuille, $ligne, $mesures, $derniereDonnee, [
                    [$colMois, 'A' . $ligneDuMois],
                    [$colAssureur, 'A' . $ligne],
                ]);
                ++$ligne;
            }
        }

        // ── Le total général ────────────────────────────────────────────────────────
        $feuille->setCellValue('A' . $ligne, 'Total général');
        $rang = 2;
        foreach ($mesures as $mesure) {
            $cellule = Coordinate::stringFromColumnIndex($rang) . $ligne;
            // ⚠ LA PLAGE S'ARRÊTE AVANT LA LIGNE DE TOTAUX DE `DONNEES` : l'y inclure
            // ferait compter chaque montant deux fois, et le total afficherait le double.
            $feuille->setCellValue($cellule, sprintf(
                '=SUM(%s!%s%d:%s%d)',
                EtatDuPortefeuille::FEUILLE,
                $mesure['lettre'],
                self::LIGNE_DONNEES,
                $mesure['lettre'],
                $derniereDonnee,
            ));
            ++$rang;
        }
        $plageTotal = 'A' . $ligne . ':' . $derniereColonne . $ligne;
        $feuille->getStyle($plageTotal)->getFont()->setBold(true);
        $feuille->getStyle($plageTotal)->getBorders()->getTop()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM);

        $feuille->getStyle('B4:' . $derniereColonne . $ligne)->getNumberFormat()->setFormatCode('#,##0.00');
        $feuille->getStyle('B4:' . $derniereColonne . $ligne)->getAlignment()->setHorizontal('right');

        $feuille->getColumnDimension('A')->setWidth(34);
        for ($i = 2; $i <= \count($mesures) + 1; ++$i) {
            $feuille->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setWidth(22);
        }
        $feuille->freezePane('B4');
    }

    /**
     * Pose une somme conditionnelle par mesure.
     *
     * ⚠ LES CRITÈRES POINTENT SUR DES CELLULES, jamais sur du texte recopié : le libellé
     * du mois vit en colonne A, et la formule le lit là. Écrire « Janvier » en dur dans
     * sept formules par groupe aurait rendu la feuille infalsifiable — corriger un libellé
     * n'aurait plus rien recalculé.
     *
     * @param array<string, array{lettre: string, titre: string}> $mesures
     * @param array<int, array{0: ?string, 1: string}>            $criteres
     */
    private function poserLesSommes(Worksheet $feuille, int $ligne, array $mesures, int $derniereDonnee, array $criteres): void
    {
        $conditions = '';
        foreach ($criteres as [$colonne, $cellule]) {
            if ($colonne === null) {
                continue;
            }
            $conditions .= sprintf(
                ',%s!$%s$%d:$%s$%d,$%s',
                EtatDuPortefeuille::FEUILLE,
                $colonne,
                self::LIGNE_DONNEES,
                $colonne,
                $derniereDonnee,
                ltrim($cellule, '$'),
            );
        }

        $rang = 2;
        foreach ($mesures as $mesure) {
            $feuille->setCellValue(
                Coordinate::stringFromColumnIndex($rang) . $ligne,
                sprintf(
                    '=SUMIFS(%s!$%s$%d:$%s$%d%s)',
                    EtatDuPortefeuille::FEUILLE,
                    $mesure['lettre'],
                    self::LIGNE_DONNEES,
                    $mesure['lettre'],
                    $derniereDonnee,
                    $conditions,
                ),
            );
            ++$rang;
        }
    }

    /**
     * Les groupes de la synthèse : mois => assureurs, dans l'ordre d'affichage.
     *
     * ⚠ LE MOIS NE SE TRIE PAS TOUT SEUL. Son libellé ne porte que son nom — « Janvier » —,
     * parce qu'un rang collé devant le rendait lisible comme une DATE par les moteurs de
     * formules, et faisait ressortir janvier et mars à zéro. L'ordre du calendrier est donc
     * porté ICI, par le rang dans `EtatDuPortefeuille::MOIS`. Un `ksort` remettrait août en
     * tête et septembre en queue.
     *
     * Les assureurs suivent l'alphabet. Une valeur absente devient « (sans) » plutôt que de
     * disparaître : une ligne qui n'entre dans aucun groupe est une ligne qu'on ne verrait
     * plus — et son montant manquerait au total sans que rien ne le signale.
     *
     * @param array<int, array<string, mixed>> $lignes
     *
     * @return array<string, string[]>
     */
    private function grouper(array $lignes, ?string $cleMois, ?string $cleAssureur): array
    {
        $groupes = [];
        foreach ($lignes as $ligne) {
            // ⚠ ON REPREND LA VALEUR TELLE QUELLE, sans jamais lui substituer un libellé de
            // remplacement : le critère de la somme cherche dans les DONNÉES, et ne trouverait
            // pas un nom qui n'y figure pas. C'est le défaut qu'a eu cette feuille : le groupe
            // des tranches sans date d'effet affichait 0,00 quand elles pesaient 4 952,50, et
            // les sous-lignes ne totalisaient plus le total général. `SANS_MOIS` est donc écrit
            // dans la colonne elle-même.
            $mois = $cleMois === null ? 'Toutes périodes' : (string) ($ligne[$cleMois] ?? EtatDuPortefeuille::SANS_MOIS);
            $assureur = $cleAssureur === null ? null : (string) ($ligne[$cleAssureur] ?? '(sans assureur)');

            $groupes[$mois] ??= [];
            if ($assureur !== null) {
                $groupes[$mois][$assureur] = true;
            }
        }

        // Ce qui n'est pas un mois — « (sans date d'effet) » — passe en queue plutôt que de
        // s'intercaler au hasard d'un rang introuvable.
        uksort($groupes, static function (string $a, string $b): int {
            $rangA = array_search($a, EtatDuPortefeuille::MOIS, true);
            $rangB = array_search($b, EtatDuPortefeuille::MOIS, true);

            if ($rangA === false || $rangB === false) {
                return $rangA === $rangB ? strcmp($a, $b) : ($rangA === false ? 1 : -1);
            }

            return $rangA <=> $rangB;
        });

        foreach ($groupes as $mois => $assureurs) {
            $noms = array_keys($assureurs);
            sort($noms);
            $groupes[$mois] = $noms;
        }

        return $groupes;
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
