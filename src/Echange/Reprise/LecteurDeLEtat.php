<?php

namespace App\Echange\Reprise;

use App\Echange\Canevas\CanevasDEchange;
use App\Echange\Classeur\EcrivainJsbx;
use App\Echange\Classeur\LigneLue;
use App\Echange\Etat\EcrivainEtat;
use App\Echange\Etat\ColonneEtat;
use App\Echange\Etat\EtatDuPortefeuille;
use App\Echange\Service\ResolveurDeRenvois;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * LIT LA FEUILLE `DONNEES` — une ligne par tranche — et la rend au format que
 * l'importation sait déjà traiter.
 *
 * ── POURQUOI PAS DE LIGNE DE CODES TECHNIQUES, CONTRAIREMENT AU CLASSEUR NORMALISÉ ──
 * Le format d'échange écrit ses libellés en ligne 1 et ses CODES en ligne 2, masquée :
 * seuls les codes font foi au parsing, si bien que les libellés restent libres.
 *
 * ⚠ CETTE FEUILLE-CI N'EN A PAS, ET C'EST UN CHOIX. Elle porte un filtre automatique,
 * dont la plage doit être contiguë : une ligne de codes intercalée entrerait dans le
 * filtre, et Excel proposerait « policeReference » parmi les valeurs de la colonne. On
 * lit donc par LIBELLÉ.
 *
 * Le libellé est une clé acceptable pour une raison précise : il est déclaré UNE FOIS,
 * dans {@see CatalogueDesColonnes}, qui sert à l'écriture comme à la lecture. Et la
 * comparaison passe par {@see ResolveurDeRenvois::normaliser()}, donc ni la casse, ni les
 * accents, ni les espaces surnuméraires ne comptent. Reste le risque d'un libellé
 * réécrit : `RepriseLibellesTest` gèle ceux des colonnes de saisie, pour qu'un renommage
 * ne casse pas en silence la relecture des fichiers déjà distribués.
 *
 * ── CE QUE CE LECTEUR NE FAIT PAS ──────────────────────────────────────────────────
 * Il ne juge rien. Il rend des `LigneLue` — la même monnaie que le classeur normalisé —
 * et c'est {@see ReconstitueurDeTranche} qui en tire des écritures.
 */
final class LecteurDeLEtat
{
    /** Première ligne de données : l'en-tête n'occupe qu'une ligne. */
    private const LIGNE_DONNEES = 2;

    /**
     * Le code de « ressource » porté par les lignes de cette feuille.
     *
     * Une ligne ne décrit pas une entité mais une CHAÎNE ; le code retenu est celui de sa
     * maille, la tranche. Il n'apparaît que dans les messages d'anomalie, où il situe la
     * feuille — et « Tranche » est ce que l'utilisateur y reconnaît.
     */
    public const RESSOURCE = 'Tranche';

    /** La feuille de l'état est-elle présente dans ce classeur ? */
    public static function estUnClasseurDEtat(Spreadsheet $classeur): bool
    {
        return $classeur->getSheetByName(EtatDuPortefeuille::FEUILLE) !== null;
    }

    /**
     * L'IDENTIFIANT DU CABINET QUI A PRODUIT CE FICHIER, lu dans `_DICTIONNAIRE`.
     *
     * ⚠ POURQUOI PAS DANS UN MANIFESTE. L'état n'a pas de feuille `_MANIFESTE` : elle a
     * été retirée parce qu'elle ne disait rien au lecteur. Mais l'importation, elle, doit
     * savoir d'où vient le fichier — déposer les données d'un cabinet dans un autre est
     * parfois voulu (une reprise), jamais anodin, et les identifiants qu'il contient ne
     * désignent rien ailleurs.
     *
     * La clé vit donc dans le dictionnaire, sous `EcrivainEtat::CLE_CABINET`, écrite et
     * relue par la même constante.
     *
     * Rend une chaîne vide si le fichier ne la porte pas — un gabarit rempli à la main, ou
     * un classeur retaillé. C'est à l'appelant d'en décider : refuser tout net serait
     * fermer la porte à une reprise préparée hors ligne.
     */
    public function cabinet(Spreadsheet $classeur): string
    {
        $feuille = $classeur->getSheetByName(EcrivainJsbx::FEUILLE_DICTIONNAIRE);
        if ($feuille === null) {
            return '';
        }

        $derniere = min($feuille->getHighestDataRow(), 12);
        for ($numero = 1; $numero <= $derniere; ++$numero) {
            if (trim((string) $feuille->getCell('A' . $numero)->getValue()) === EcrivainEtat::CLE_CABINET) {
                return trim((string) $feuille->getCell('B' . $numero)->getValue());
            }
        }

        return '';
    }

    /**
     * LES LIGNES DE LA FEUILLE, indexées par CODE de colonne.
     *
     * @param array<string, ColonneEtat> $colonnes le catalogue, source des libellés
     *
     * @return array<int, LigneLue>
     */
    public function lignes(Spreadsheet $classeur, array $colonnes): array
    {
        $feuille = $classeur->getSheetByName(EtatDuPortefeuille::FEUILLE);
        if ($feuille === null) {
            return [];
        }

        $lettreParCode = $this->lettresParCode($feuille, $colonnes);
        if ($lettreParCode === []) {
            return [];
        }

        $lignes = [];
        $derniere = $this->derniereLigneDeDonnees($feuille);

        for ($numero = self::LIGNE_DONNEES; $numero <= $derniere; ++$numero) {
            $valeurs = [];
            foreach ($lettreParCode as $code => $lettre) {
                $valeurs[$code] = $this->valeur($feuille, $lettre . $numero);
            }

            $ligne = new LigneLue(EtatDuPortefeuille::FEUILLE, self::RESSOURCE, $numero, $valeurs, $lettreParCode);

            // Une ligne blanche laissée sous les données — un tri, un copier-coller — ne
            // doit pas devenir une création à champs vides, puis une volée d'erreurs
            // « champ obligatoire manquant » que l'utilisateur n'a pas provoquées.
            // ⚠ L'IDENTIFIANT ET L'ACTION SONT « TECHNIQUES » ICI. Sans cela, une ligne
            // ne portant qu'un identifiant et « SUPPRIMER » passerait pour une ligne
            // blanche : la suppression demandée serait ignorée en silence. `estVide()`
            // retient d'ailleurs toute ligne portant une action explicite.
            if ($ligne->estVide([EtatDuPortefeuille::COLONNE_IDENTITE, CanevasDEchange::COL_ACTION])) {
                continue;
            }

            $lignes[] = $ligne;
        }

        return $lignes;
    }

    /**
     * Les colonnes du catalogue ABSENTES du fichier.
     *
     * ⚠ UNE COLONNE DE SAISIE MANQUANTE N'EST PAS UNE ERREUR. L'utilisateur a le droit
     * d'exporter un sous-ensemble de colonnes, puis de le redéposer : ce qu'il n'a pas
     * exporté, il ne l'a pas modifié. C'est le reconstitueur qui décide si ce qui manque
     * l'empêche d'écrire — la référence de police, par exemple.
     *
     * @param array<string, ColonneEtat> $colonnes
     *
     * @return string[] codes absents
     */
    public function codesAbsents(Spreadsheet $classeur, array $colonnes): array
    {
        $feuille = $classeur->getSheetByName(EtatDuPortefeuille::FEUILLE);
        if ($feuille === null) {
            return array_keys($colonnes);
        }

        return array_values(array_diff(
            array_keys($colonnes),
            array_keys($this->lettresParCode($feuille, $colonnes)),
        ));
    }

    /**
     * ⚠ LA LIGNE DE TOTAUX N'EST PAS UNE DONNÉE, et rien dans sa forme ne le dit : ses
     * cellules portent des nombres comme les autres. L'importer créerait une tranche
     * « TOTAUX » dont la prime serait celle du portefeuille entier.
     *
     * On la reconnaît par son libellé en colonne A, celui-là même qu'écrit
     * `EcrivainEtat::ecrireTotaux()`.
     */
    private function derniereLigneDeDonnees(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $feuille): int
    {
        $derniere = $feuille->getHighestDataRow();

        for ($numero = $derniere; $numero >= self::LIGNE_DONNEES; --$numero) {
            $premiere = trim((string) $feuille->getCell('A' . $numero)->getValue());
            if ($premiere === 'TOTAUX') {
                return $numero - 1;
            }
        }

        return $derniere;
    }

    /**
     * Lettre de colonne Excel par code du catalogue, d'après les libellés de la ligne 1.
     *
     * @param array<string, ColonneEtat> $colonnes
     *
     * @return array<string, string>
     */
    private function lettresParCode(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $feuille, array $colonnes): array
    {
        // Libellé normalisé => code. La normalisation est celle de tout le reste : ni la
        // casse, ni les accents, ni un espace de trop ne doivent empêcher la relecture.
        $codeParLibelle = [];
        foreach ($colonnes as $code => $colonne) {
            $codeParLibelle[ResolveurDeRenvois::normaliser($colonne->libelle)] = $code;
        }

        $lettres = [];
        $derniere = Coordinate::columnIndexFromString($feuille->getHighestDataColumn());

        for ($i = 1; $i <= $derniere; ++$i) {
            $lettre = Coordinate::stringFromColumnIndex($i);
            $libelle = ResolveurDeRenvois::normaliser((string) $feuille->getCell($lettre . '1')->getValue());
            $code = $codeParLibelle[$libelle] ?? null;

            // ⚠ LA PREMIÈRE OCCURRENCE GAGNE. Une colonne recopiée par l'utilisateur ne
            // doit pas faire dépendre la lecture de l'ordre des colonnes.
            if ($code !== null && !isset($lettres[$code])) {
                $lettres[$code] = $lettre;
            }
        }

        return $lettres;
    }

    /**
     * La valeur d'une cellule, telle que le classeur la porte.
     *
     * ⚠ AUCUNE CONVERSION DE DATE ICI, ET C'EST UN CORRECTIF. Ce lecteur convertissait les
     * dates Excel en « aaaa-mm-jj » — un format que le formulaire a REFUSÉ : les champs
     * temporels du projet sont des `datetime_immutable`, et leur widget attend
     * « aaaa-mm-jjThh:mm ». Résultat : les soixante-dix-neuf lignes d'un export réimporté
     * étaient rejetées sur « Veuillez saisir une date et une heure valides », pour un
     * format inventé ici.
     *
     * La conversion appartient à `ReconstitueurDeTranche`, qui seul connaît la CIBLE de
     * chaque colonne — donc son type Doctrine, dont le format se dérive. Le format
     * d'échange normalisé fait de même, et pour la même raison.
     */
    private function valeur(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $feuille, string $cellule): mixed
    {
        $brute = $feuille->getCell($cellule)->getValue();

        return $brute === '' ? null : $brute;
    }
}
