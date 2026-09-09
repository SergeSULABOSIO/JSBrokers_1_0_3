<?php

namespace App\Tests\Echange;

use App\Echange\Etat\EcrivainEtat;
use App\Echange\Etat\EtatDuPortefeuille;
use App\Entity\Entreprise;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * FABRIQUE UN CLASSEUR DE REPRISE pour les tests.
 *
 * ── POURQUOI UN TRAIT PARTAGÉ ───────────────────────────────────────────────────────
 * ⚠ IL N'Y A PLUS QU'UN SEUL FORMAT D'IMPORT, donc plus qu'une seule façon de fabriquer
 * un fichier d'essai. Quatre fichiers de tests montaient chacun le leur, au format
 * « normalisé » qui n'est plus accepté : les laisser diverger reviendrait à tester la
 * lecture contre quatre idées différentes de ce qu'est un classeur.
 *
 * ⚠ LES LIBELLÉS VIENNENT DU CATALOGUE, ILS NE SONT PAS RECOPIÉS. La feuille `DONNEES`
 * se lit par LIBELLÉ — elle porte un filtre automatique, dont la plage doit être
 * contiguë, et une ligne de codes techniques y entrerait. Écrire les libellés à la main
 * ici, ce serait tester la lecture contre une copie du catalogue plutôt que contre le
 * catalogue lui-même : un renommage passerait inaperçu jusqu'au premier fichier réel.
 */
trait ClasseurDeRepriseTrait
{
    /** @var string[] fichiers temporaires à effacer */
    private array $classeursTemporaires = [];

    /**
     * Écrit un classeur de reprise et rend son chemin.
     *
     * @param array<int, array<string, string|int|float>> $lignes  une entrée par échéance,
     *                                                             indexée par CODE de colonne
     * @param array<string, string>                       $entetes valeurs du dictionnaire
     *                                                             (`CABINET` pour l'identité)
     */
    private function classeurDeReprise(Entreprise $entreprise, array $lignes, array $entetes = []): string
    {
        $colonnes = static::getContainer()->get(EtatDuPortefeuille::class)->colonnes($entreprise);
        $codes = array_keys($colonnes);

        $classeur = new Spreadsheet();
        $feuille = $classeur->getActiveSheet();
        $feuille->setTitle(EtatDuPortefeuille::FEUILLE);

        foreach ($codes as $index => $code) {
            $feuille->setCellValue([$index + 1, 1], $colonnes[$code]->libelle);
        }

        foreach ($lignes as $rang => $valeurs) {
            foreach ($valeurs as $code => $valeur) {
                $colonne = array_search($code, $codes, true);
                self::assertNotFalse($colonne, sprintf('La colonne « %s » n\'existe pas au catalogue.', $code));
                $feuille->setCellValue([$colonne + 1, $rang + 2], $valeur);
            }
        }

        // ⚠ LE DICTIONNAIRE PORTE L'IDENTITÉ DU CABINET. C'est par cette clé que l'import
        // reconnaît un fichier venu d'ailleurs — un cas qu'il faut confirmer explicitement,
        // parce que les identifiants qu'il contient ne désignent rien ici.
        if ($entetes !== []) {
            $dico = $classeur->createSheet();
            $dico->setTitle(\App\Echange\Classeur\EcrivainJsbx::FEUILLE_DICTIONNAIRE);
            $rang = 1;
            foreach ($entetes as $cle => $valeur) {
                $dico->setCellValue([1, $rang], $cle);
                $dico->setCellValue([2, $rang], $valeur);
                ++$rang;
            }
        }

        return $this->deposerClasseur($classeur);
    }

    /** La clé sous laquelle le cabinet d'origine est inscrit au dictionnaire. */
    private function cleDuCabinet(): string
    {
        return EcrivainEtat::CLE_CABINET;
    }

    /**
     * REMPLIT UN GABARIT RÉELLEMENT PRODUIT par la rubrique.
     *
     * ⚠ CE N'EST PAS LA MÊME CHOSE QUE `classeurDeReprise()`. Celui-ci fabrique un
     * classeur d'après le catalogue ; celui-là part du fichier que le cabinet télécharge
     * VRAIMENT. Écrire dedans, c'est éprouver l'aller-retour complet : ce que la rubrique
     * distribue doit se redéposer, sans quoi le seul geste qui rend la reprise possible
     * conduirait à un refus.
     *
     * @param array<int, array<string, string|int|float>> $lignes indexées par CODE de colonne
     */
    private function remplirLeGabarit(string $chemin, Entreprise $entreprise, array $lignes): void
    {
        $colonnes = static::getContainer()->get(EtatDuPortefeuille::class)->colonnes($entreprise);

        $classeur = \PhpOffice\PhpSpreadsheet\IOFactory::load($chemin);
        $feuille = $classeur->getSheetByName(EtatDuPortefeuille::FEUILLE);
        self::assertNotNull($feuille, 'Le gabarit doit porter la feuille des données.');

        // On situe chaque colonne par son LIBELLÉ, comme le fait la lecture : la position
        // n'est pas un contrat, le libellé l'est.
        $lettreParCode = [];
        foreach ($feuille->getRowIterator(1, 1)->current()->getCellIterator() as $cellule) {
            $libelle = trim((string) $cellule->getValue());
            foreach ($colonnes as $code => $colonne) {
                if ($colonne->libelle === $libelle) {
                    $lettreParCode[$code] = $cellule->getColumn();
                    break;
                }
            }
        }

        foreach ($lignes as $rang => $valeurs) {
            foreach ($valeurs as $code => $valeur) {
                self::assertArrayHasKey($code, $lettreParCode, sprintf('Le gabarit ne porte pas « %s ».', $code));
                $feuille->setCellValue($lettreParCode[$code] . ($rang + 2), $valeur);
            }
        }

        (new Xlsx($classeur))->save($chemin);
    }

    /** Un classeur quelconque, sans feuille `DONNEES` — le cas du fichier maison. */
    private function classeurSansDonnees(string $feuille = 'Mon tableau'): string
    {
        $classeur = new Spreadsheet();
        $classeur->getActiveSheet()->setTitle($feuille);
        $classeur->getActiveSheet()->setCellValue('A1', 'Client');

        return $this->deposerClasseur($classeur);
    }

    private function deposerClasseur(Spreadsheet $classeur): string
    {
        $chemin = sys_get_temp_dir() . '/reprise-' . bin2hex(random_bytes(8)) . '.xlsx';
        (new Xlsx($classeur))->save($chemin);
        $this->classeursTemporaires[] = $chemin;

        return $chemin;
    }

    /** À appeler dans `tearDown()` : un dépôt abandonné ne doit pas rester sur le disque. */
    private function effacerLesClasseurs(): void
    {
        foreach ($this->classeursTemporaires as $chemin) {
            @unlink($chemin);
        }
        $this->classeursTemporaires = [];
    }
}
