<?php

namespace App\Echange\Classeur;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * OUVRE un classeur déposé, et rien de plus.
 *
 * ── CE QU'IL SAVAIT FAIRE, ET POURQUOI IL NE LE FAIT PLUS ───────────────────────────
 * Ce service lisait aussi la feuille d'identité, l'inventaire des feuilles et leurs
 * lignes, en s'appuyant sur une ligne de codes techniques masquée. Tout cela appartenait
 * au format « normalisé » — une feuille par entité — que l'importation n'accepte plus :
 * il n'était plus produit nulle part, et le relire imposait de maintenir deux lecteurs,
 * deux passes structurelles et deux jeux de règles pour un fichier que personne ne
 * pouvait obtenir.
 *
 * Le classeur de reprise, lui, se lit par LIBELLÉ ({@see \App\Echange\Reprise\LecteurDeLEtat}) :
 * sa feuille porte un filtre automatique, dont la plage doit être contiguë, et une ligne
 * de codes intercalée y entrerait.
 *
 * ⚠ CE QUI RESTE EST PARTAGÉ PAR TOUS. `ouvrir()` sert au dépôt, aux paliers et à
 * l'annotation du fichier rendu : c'est la seule porte par laquelle un classeur entre.
 *
 * Il ne juge de RIEN. Le typage, les champs obligatoires, les droits et la cohérence
 * métier appartiennent au contrôle à blanc, qui s'appuie sur le circuit d'écriture commun
 * de l'espace de travail. Séparer les deux a une conséquence pratique : ce service ne peut
 * pas inventer une règle de validation qui différerait de celle de l'écran.
 */
final class LecteurJsbx
{
    /**
     * Ouvre un fichier déposé.
     *
     * @throws ClasseurIllisibleException si le fichier n'est pas un classeur exploitable
     */
    public function ouvrir(string $chemin): Spreadsheet
    {
        if (!is_file($chemin) || !is_readable($chemin)) {
            throw new ClasseurIllisibleException('Le fichier déposé est introuvable ou illisible.');
        }

        try {
            $lecteur = IOFactory::createReaderForFile($chemin);

            // ⚠ EXCEL ET RIEN D'AUTRE. PhpSpreadsheet devine le format et ouvrira
            // volontiers un .txt comme un CSV d'une seule colonne : le fichier passerait
            // alors la première porte pour échouer plus loin sur « ce n'est pas un
            // classeur de reprise », message exact mais trompeur. Le refus doit nommer la
            // vraie cause, qui est que ce n'est pas un classeur.
            if (!$lecteur instanceof XlsxReader) {
                throw new ClasseurIllisibleException(
                    'Seuls les fichiers Excel (.xlsx) produits par cette rubrique sont acceptés. '
                    . 'Le fichier déposé est d\'un autre format.',
                );
            }
            // On ne lit que les valeurs : la mise en forme, les images et les styles
            // d'un classeur de quarante feuilles pèsent bien plus que ses données, et
            // rien de tout cela ne sert à l'import.
            if (method_exists($lecteur, 'setReadDataOnly')) {
                $lecteur->setReadDataOnly(true);
            }

            return $lecteur->load($chemin);
        } catch (ClasseurIllisibleException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ClasseurIllisibleException(
                'Le fichier déposé n\'est pas un classeur Excel exploitable. '
                . 'Seuls les fichiers .xlsx produits par cette rubrique sont acceptés.',
                previous: $e,
            );
        }
    }
}
