<?php

namespace App\Echange\Classeur;

use App\Echange\Canevas\CanevasDEchange;
use App\Echange\Canevas\RessourceDEchange;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * LIT un classeur d'échange déposé.
 *
 * Ne juge de RIEN : il ouvre, il situe, il rend. Le typage, les champs obligatoires,
 * les droits et la cohérence métier appartiennent au contrôle à blanc, qui s'appuie
 * pour cela sur le circuit d'écriture commun de l'espace de travail. Séparer les deux
 * a une conséquence pratique : ce service ne peut pas inventer une règle de validation
 * qui différerait de celle de l'écran.
 *
 * TOUT SE FAIT PAR LA LIGNE 2. L'ordre des colonnes n'a aucune importance, une colonne
 * masquée reste lue, une colonne ajoutée par l'utilisateur est ignorée sans bruit. Ce
 * qui compte est le code technique, jamais la position.
 */
final class LecteurJsbx
{
    /** Ligne portant les codes techniques. */
    private const LIGNE_CODES = 2;

    /** Première ligne de données. */
    private const LIGNE_DONNEES = 3;

    public function __construct(
        private readonly CanevasDEchange $canevas,
    ) {
    }

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
            // alors la première porte pour échouer plus loin sur « feuille d'identité
            // absente », message exact mais trompeur. Le refus doit nommer la vraie
            // cause, qui est que ce n'est pas un classeur.
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

    /**
     * Contenu de la feuille `_MANIFESTE`, en clé/valeur.
     *
     * @return array<string, string> vide si la feuille est absente
     */
    public function lireManifeste(Spreadsheet $classeur): array
    {
        $feuille = $classeur->getSheetByName(EcrivainJsbx::FEUILLE_MANIFESTE);
        if ($feuille === null) {
            return [];
        }

        $valeurs = [];
        foreach ($feuille->toArray(null, true, false, false) as $ligne) {
            $cle = trim((string) ($ligne[0] ?? ''));
            // La colonne B ne porte qu'un libellé de confort ; la valeur est en C.
            if ($cle === '' || $cle === 'Clé') {
                continue;
            }
            $valeurs[$cle] = trim((string) ($ligne[2] ?? ''));
        }

        return $valeurs;
    }

    /**
     * Feuilles de données PRÉSENTES dans le classeur, rapportées aux ressources connues.
     *
     * Une feuille attendue mais absente n'est pas une erreur : son entité est
     * simplement hors du périmètre de cet import. Une feuille inconnue est ignorée et
     * mentionnée au rapport — c'est ainsi qu'un utilisateur peut garder sa feuille de
     * brouillon dans le fichier.
     *
     * @param array<string, RessourceDEchange> $ressources
     *
     * @return array{presentes: array<string, RessourceDEchange>, absentes: string[], inconnues: string[]}
     */
    public function inventaireDesFeuilles(Spreadsheet $classeur, array $ressources): array
    {
        $parFeuille = [];
        foreach ($ressources as $ressource) {
            $parFeuille[$ressource->feuille] = $ressource;
        }

        $techniques = [
            EcrivainJsbx::FEUILLE_MANIFESTE,
            EcrivainJsbx::FEUILLE_DICTIONNAIRE,
            EcrivainJsbx::FEUILLE_LISTES,
            EcrivainJsbx::FEUILLE_RAPPORT,
        ];

        $presentes = [];
        $inconnues = [];
        foreach ($classeur->getSheetNames() as $nom) {
            if (in_array($nom, $techniques, true)) {
                continue;
            }
            if (isset($parFeuille[$nom])) {
                $presentes[$parFeuille[$nom]->code] = $parFeuille[$nom];
                continue;
            }
            $inconnues[] = $nom;
        }

        $absentes = [];
        foreach ($ressources as $code => $ressource) {
            if (!isset($presentes[$code])) {
                $absentes[] = $ressource->feuille;
            }
        }

        return ['presentes' => $presentes, 'absentes' => $absentes, 'inconnues' => $inconnues];
    }

    /**
     * Codes techniques réellement présents en ligne 2 d'une feuille, indexés par lettre
     * de colonne. Sert à la fois à la lecture et au calcul de l'empreinte.
     *
     * @return array<string, string> lettre de colonne => code technique
     */
    public function codesDeLaFeuille(Spreadsheet $classeur, RessourceDEchange $ressource): array
    {
        $feuille = $classeur->getSheetByName($ressource->feuille);
        if ($feuille === null) {
            return [];
        }

        $codes = [];
        $derniere = Coordinate::columnIndexFromString($feuille->getHighestDataColumn());
        for ($i = 1; $i <= $derniere; ++$i) {
            $lettre = Coordinate::stringFromColumnIndex($i);
            $code = trim((string) $feuille->getCell($lettre . self::LIGNE_CODES)->getValue());
            if ($code !== '') {
                $codes[$lettre] = $code;
            }
        }

        return $codes;
    }

    /**
     * Lignes de données d'une feuille.
     *
     * Les colonnes inconnues du canevas sont écartées ici : l'utilisateur a le droit
     * d'ajouter une colonne de notes personnelles, et elle ne doit ni faire échouer
     * l'import, ni tenter d'écrire un champ qui n'existe pas.
     *
     * @return LigneLue[]
     */
    public function lignesDe(Spreadsheet $classeur, RessourceDEchange $ressource): array
    {
        $feuille = $classeur->getSheetByName($ressource->feuille);
        if ($feuille === null) {
            return [];
        }

        $codesParLettre = $this->codesDeLaFeuille($classeur, $ressource);
        $connus = array_merge(
            EcrivainJsbx::COLONNES_TECHNIQUES,
            array_map(static fn ($c) => $c->code, $ressource->colonnes),
        );

        // Lettre par code, pour situer une anomalie sans reparcourir la feuille.
        $lettreParCode = [];
        foreach ($codesParLettre as $lettre => $code) {
            if (in_array($code, $connus, true)) {
                $lettreParCode[$code] = $lettre;
            }
        }

        $lignes = [];
        $derniere = $feuille->getHighestDataRow();
        for ($numero = self::LIGNE_DONNEES; $numero <= $derniere; ++$numero) {
            $valeurs = [];
            foreach ($lettreParCode as $code => $lettre) {
                $valeurs[$code] = $feuille->getCell($lettre . $numero)->getValue();
            }

            $ligne = new LigneLue($ressource->feuille, $ressource->code, $numero, $valeurs, $lettreParCode);
            if ($ligne->estVide(EcrivainJsbx::COLONNES_TECHNIQUES)) {
                continue;
            }

            $lignes[] = $ligne;
        }

        return $lignes;
    }

    /**
     * Codes techniques ATTENDUS sur une feuille et absents de sa ligne 2.
     *
     * ⚠ POURQUOI PAS UNE EMPREINTE GLOBALE, comme le prévoyait la spécification. Un
     * hachage répond « le fichier a changé » et s'arrête là : l'utilisateur, lui, a
     * besoin de savoir QUELLE colonne il a supprimée en faisant le ménage dans son
     * tableur. Un contrôle qui ne dit pas quoi réparer transforme un geste d'une
     * seconde en reprise complète du fichier.
     *
     * On ne regarde ni l'ordre ni la position : déplacer une colonne, la masquer, en
     * ajouter une pour ses propres notes sont des gestes légitimes. Seule la
     * DISPARITION d'un code rend le fichier menteur sur ce qu'il contient.
     *
     * @return string[] codes manquants, vide si la feuille est conforme
     */
    public function codesManquants(Spreadsheet $classeur, RessourceDEchange $ressource): array
    {
        $presents = array_values($this->codesDeLaFeuille($classeur, $ressource));
        if ($presents === []) {
            return [];
        }

        $attendus = array_merge(
            EcrivainJsbx::COLONNES_TECHNIQUES,
            array_map(static fn ($c) => $c->code, $ressource->colonnes),
        );

        return array_values(array_filter(
            $attendus,
            static fn (string $code) => !in_array($code, $presents, true),
        ));
    }
}
