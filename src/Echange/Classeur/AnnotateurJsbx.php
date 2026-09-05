<?php

namespace App\Echange\Classeur;

use App\Echange\Service\Anomalie;
use App\Echange\Service\RapportDeControle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * REND À L'UTILISATEUR SON PROPRE CLASSEUR, AVEC LES FAUTES MONTRÉES DU DOIGT.
 *
 * ── LE PROBLÈME QU'IL RÉSOUT ────────────────────────────────────────────────────────
 * L'écran sait dire « feuille Clients, ligne 47, colonne M — cette valeur n'est pas
 * acceptée ». Il reste alors à rouvrir le classeur, retrouver la ligne 47, la colonne M,
 * corriger, et recommencer pour chacune des anomalies. Sur trois cents lignes, c'est un
 * travail de copiste — et c'est là que l'utilisateur abandonne, non à l'erreur elle-même.
 *
 * On lui rend donc SON fichier, celui qu'il connaît, avec les cellules fautives colorées
 * et un commentaire portant le motif à l'endroit exact. Il corrige sur place et redépose.
 *
 * ── ⚠ ON ANNOTE UNE COPIE, JAMAIS LE DÉPÔT ─────────────────────────────────────────
 * Le dépôt est la source que la passe 3 relira au moment de confirmer. Le modifier ferait
 * diverger ce qui a été contrôlé de ce qui sera écrit — l'utilisateur validerait un
 * rapport portant sur un fichier qui n'existe plus. La copie est produite à la demande,
 * remise, et oubliée.
 *
 * ── ⚠ TOUTE ANOMALIE N'A PAS D'ADRESSE ──────────────────────────────────────────────
 * Un fichier illisible, un manifeste absent, une feuille entière hors droits : ces
 * refus-là ne visent aucune cellule. Ils n'ont rien à colorer, mais ils ont tout à dire —
 * ils figurent dans la feuille de rapport, en tête, plutôt que d'être silencieusement
 * sautés.
 */
final class AnnotateurJsbx
{
    /** Fond des cellules en erreur — --danger-bg de la charte. */
    private const FOND_ERREUR = 'FFF8D7DA';

    /** Fond des cellules signalées sans blocage — --warning-bg. */
    private const FOND_AVERTISSEMENT = 'FFFFF3CD';

    /** En-tête de la feuille de rapport — cobalt de la marque. */
    private const COBALT = 'FF0047AB';

    public function __construct(
        private readonly LecteurJsbx $lecteur,
    ) {
    }

    /**
     * Ouvre le dépôt, y pose les annotations, et rend le classeur — sans avoir touché au
     * fichier d'origine.
     *
     * @param array<string, mixed> $rapport la forme persistée sur le contrôle
     *
     * @throws ClasseurIllisibleException si le dépôt n'est plus lisible
     */
    public function annoter(string $cheminDepot, array $rapport): Spreadsheet
    {
        $classeur = $this->lecteur->ouvrir($cheminDepot);
        $anomalies = $this->anomalies($rapport);

        $orphelines = [];
        foreach ($anomalies as $anomalie) {
            if (!$this->poser($classeur, $anomalie)) {
                $orphelines[] = $anomalie;
            }
        }

        $this->ecrireRapport($classeur, $rapport, $anomalies, $orphelines);

        // Le rapport s'ouvre en premier : c'est ce qu'on vient lire.
        $classeur->setActiveSheetIndexByName(EcrivainJsbx::FEUILLE_RAPPORT);

        return $classeur;
    }

    /**
     * Pose la couleur et le commentaire sur la cellule visée.
     *
     * @return bool false quand l'anomalie ne désigne aucune cellule
     */
    private function poser(Spreadsheet $classeur, Anomalie $anomalie): bool
    {
        if ($anomalie->feuille === null || $anomalie->ligne === null || $anomalie->colonne === null) {
            return false;
        }

        $feuille = $classeur->getSheetByName($anomalie->feuille);
        if ($feuille === null) {
            return false;
        }

        $cellule = $anomalie->colonne . $anomalie->ligne;

        $feuille->getStyle($cellule)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB($anomalie->bloque() ? self::FOND_ERREUR : self::FOND_AVERTISSEMENT);

        // Le commentaire porte le motif COMPLET. Le tronquer obligerait à revenir à
        // l'écran pour comprendre, ce qui annulerait tout l'intérêt de l'annotation.
        $commentaire = $feuille->getComment($cellule);
        $commentaire->setWidth('320pt');
        $commentaire->setHeight('90pt');
        $commentaire->getText()->createTextRun(
            ($anomalie->bloque() ? 'À corriger — ' : 'À vérifier — ') . $anomalie->message,
        );

        return true;
    }

    /**
     * Feuille `_RAPPORT` : la synthèse d'abord, le détail ensuite, et les anomalies sans
     * adresse en évidence — ce sont celles qu'aucune cellule colorée ne signalera.
     *
     * @param array<string, mixed> $rapport
     * @param Anomalie[]           $anomalies
     * @param Anomalie[]           $orphelines
     */
    private function ecrireRapport(Spreadsheet $classeur, array $rapport, array $anomalies, array $orphelines): void
    {
        // Un rapport précédent serait trompeur : on repart d'une feuille propre.
        $existante = $classeur->getSheetByName(EcrivainJsbx::FEUILLE_RAPPORT);
        if ($existante !== null) {
            $classeur->removeSheetByIndex($classeur->getIndex($existante));
        }

        $feuille = $classeur->createSheet(0);
        $feuille->setTitle(EcrivainJsbx::FEUILLE_RAPPORT);

        $ligne = 1;
        $feuille->setCellValue('A' . $ligne, 'Rapport de contrôle');
        $feuille->getStyle('A' . $ligne)->getFont()->setBold(true)->setSize(14);
        $ligne += 2;

        $confirmable = (bool) ($rapport['confirmable'] ?? false);
        $feuille->setCellValue('A' . $ligne, $confirmable
            ? 'Aucune erreur bloquante : ce fichier peut être importé.'
            : 'Des erreurs empêchent l\'importation. Les cellules concernées sont surlignées dans les feuilles de données.');
        $feuille->getStyle('A' . $ligne)->getFont()->setBold(true);
        $ligne += 2;

        foreach ([
            'Lignes lues'    => $rapport['lignes_lues'] ?? 0,
            'Créations'      => $rapport['creations'] ?? 0,
            'Mises à jour'   => $rapport['modifications'] ?? 0,
            'Suppressions'   => $rapport['suppressions'] ?? 0,
            'Erreurs'        => $rapport['nb_erreurs'] ?? 0,
        ] as $libelle => $valeur) {
            $feuille->setCellValue('A' . $ligne, $libelle);
            $feuille->setCellValue('B' . $ligne, $valeur);
            ++$ligne;
        }
        ++$ligne;

        // Les anomalies SANS adresse en premier : aucune cellule colorée ne les portera,
        // et ce sont souvent les plus graves — un fichier qu'on n'a pas su ouvrir.
        if ($orphelines !== []) {
            $feuille->setCellValue('A' . $ligne, 'Ce qui ne vise aucune cellule en particulier');
            $feuille->getStyle('A' . $ligne)->getFont()->setBold(true);
            ++$ligne;
            foreach ($orphelines as $anomalie) {
                $feuille->setCellValue('A' . $ligne, $anomalie->bloque() ? 'Erreur' : 'Avertissement');
                $feuille->setCellValue('B' . $ligne, $anomalie->message);
                ++$ligne;
            }
            ++$ligne;
        }

        $feuille->fromArray(['Gravité', 'Feuille', 'Ligne', 'Colonne', 'Ce qu\'il faut corriger'], null, 'A' . $ligne);
        $this->styleEntete($feuille, sprintf('A%d:E%d', $ligne, $ligne));
        ++$ligne;

        foreach ($anomalies as $anomalie) {
            $feuille->fromArray([
                $anomalie->bloque() ? 'Erreur' : 'Avertissement',
                $anomalie->feuille ?? '—',
                $anomalie->ligne ?? '—',
                $anomalie->colonne ?? '—',
                $anomalie->message,
            ], null, 'A' . $ligne);
            ++$ligne;
        }

        // Synthèse par donnée, à la suite : elle dit ce que l'import ferait, feuille par
        // feuille — un total seul ne permet pas de savoir où porter son attention.
        if (($rapport['synthese'] ?? []) !== []) {
            ++$ligne;
            $feuille->setCellValue('A' . $ligne, 'Par donnée');
            $feuille->getStyle('A' . $ligne)->getFont()->setBold(true);
            ++$ligne;

            $feuille->fromArray(['Donnée', 'Créations', 'Mises à jour', 'Suppressions', 'Erreurs'], null, 'A' . $ligne);
            $this->styleEntete($feuille, sprintf('A%d:E%d', $ligne, $ligne));
            ++$ligne;

            foreach ($rapport['synthese'] as $entree) {
                $feuille->fromArray([
                    $entree['libelle'] ?? ($entree['code'] ?? ''),
                    $entree['creations'] ?? 0,
                    $entree['modifications'] ?? 0,
                    $entree['suppressions'] ?? 0,
                    $entree['erreurs'] ?? 0,
                ], null, 'A' . $ligne);
                ++$ligne;
            }
        }

        foreach (['A', 'B', 'C', 'D'] as $lettre) {
            $feuille->getColumnDimension($lettre)->setAutoSize(true);
        }
        // La colonne des messages ne s'auto-dimensionne pas : elle deviendrait plus large
        // que l'écran. On la fixe et on laisse le texte se replier.
        $feuille->getColumnDimension('E')->setWidth(90);
        $feuille->getStyle('E1:E' . $ligne)->getAlignment()->setWrapText(true);
    }

    /**
     * @param array<string, mixed> $rapport
     *
     * @return Anomalie[]
     */
    private function anomalies(array $rapport): array
    {
        // Le rapport est DÉJÀ persisté sur le contrôle : on le relit, on ne le recalcule
        // pas. Recalculer produirait un second verdict, qui pourrait différer du premier.
        return RapportDeControle::depuisArray($rapport)->anomalies();
    }

    private function styleEntete(Worksheet $feuille, string $plage): void
    {
        $style = $feuille->getStyle($plage);
        $style->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COBALT);
    }
}
