<?php

namespace App\Echange\Classeur;

use App\Echange\Canevas\CanevasDEchange;
use App\Echange\Canevas\ColonneDEchange;
use App\Echange\Canevas\RessourceDEchange;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * ÉCRIT un classeur d'échange (« JSBX »).
 *
 * UN SEUL FORMAT sert de fichier d'export ET de gabarit d'import : il n'y a pas deux
 * structures à maintenir, donc pas de dérive possible entre ce qu'on produit et ce
 * qu'on sait relire.
 *
 * Disposition d'une feuille de données :
 *   ligne 1 — libellé humain, mis en forme, figée ;
 *   ligne 2 — CODE TECHNIQUE, masquée : c'est elle, et elle seule, qui fait foi au
 *             parsing. L'utilisateur peut donc masquer des colonnes, en déplacer, en
 *             ajouter : rien de tout cela ne casse la relecture ;
 *   ligne 3+ — données.
 *
 * Conventions reprises de ComptaExportService, pour que les classeurs de la maison se
 * ressemblent : StreamedResponse vers php://output (jamais de fichier temporaire),
 * en-tête blanc sur cobalt, colonnes auto-dimensionnées, onglets à 31 caractères.
 */
final class EcrivainJsbx
{
    public const COBALT = '0047AB';

    /** Ordre des colonnes techniques, en tête de chaque feuille de données. */
    public const COLONNES_TECHNIQUES = [
        CanevasDEchange::COL_UID,
        CanevasDEchange::COL_ACTION,
        CanevasDEchange::COL_REF,
        CanevasDEchange::COL_MODIFIE_LE,
    ];

    /** Libellés humains des colonnes techniques (ligne 1). */
    private const LIBELLES_TECHNIQUES = [
        CanevasDEchange::COL_UID        => 'Identifiant (ne pas modifier)',
        CanevasDEchange::COL_ACTION     => 'Action',
        CanevasDEchange::COL_REF        => 'Repère local',
        CanevasDEchange::COL_MODIFIE_LE => 'Modifié le (ne pas modifier)',
    ];

    public const FEUILLE_MANIFESTE    = '_MANIFESTE';
    public const FEUILLE_DICTIONNAIRE = '_DICTIONNAIRE';
    public const FEUILLE_LISTES       = '_LISTES';
    public const FEUILLE_RAPPORT      = '_RAPPORT';

    /** Valeurs de la liste déroulante d'un booléen. */
    public const OUI = 'OUI';
    public const NON = 'NON';

    /**
     * Construit le classeur complet.
     *
     * @param array<string, RessourceDEchange>      $ressources en ordre topologique
     * @param array<string, array<int, array<string, mixed>>> $lignes code de ressource => lignes,
     *        chaque ligne étant `code de colonne => valeur déjà normalisée` (cf. LigneExportable)
     */
    public function ecrire(Manifeste $manifeste, array $ressources, array $lignes): Spreadsheet
    {
        $classeur = new Spreadsheet();
        $classeur->removeSheetByIndex(0);

        $this->ecrireManifeste($classeur, $manifeste);
        $this->ecrireDictionnaire($classeur, $ressources);
        $plages = $this->ecrireListes($classeur, $ressources);

        foreach ($ressources as $ressource) {
            $this->ecrireFeuilleDeDonnees($classeur, $ressource, $lignes[$ressource->code] ?? [], $plages);
        }

        $classeur->setActiveSheetIndex(0);

        return $classeur;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Feuilles techniques
    // ─────────────────────────────────────────────────────────────────────────────

    private function ecrireManifeste(Spreadsheet $classeur, Manifeste $manifeste): void
    {
        $feuille = $classeur->createSheet();
        $feuille->setTitle(self::FEUILLE_MANIFESTE);

        $feuille->fromArray(['Clé', 'Information', 'Valeur'], null, 'A1');
        $this->styleEntete($feuille, 'A1:C1');

        $ligne = 2;
        foreach ($manifeste->lignes() as [$cle, $libelle, $valeur]) {
            // La valeur est écrite en TEXTE explicite : une empreinte de 64 chiffres
            // hexadécimaux serait sinon interprétée comme un nombre et perdue en
            // notation scientifique — le fichier se déclarerait alors altéré.
            $feuille->setCellValue('A' . $ligne, $cle);
            $feuille->setCellValue('B' . $ligne, $libelle);
            $feuille->setCellValueExplicit(
                'C' . $ligne,
                $valeur,
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING,
            );
            ++$ligne;
        }

        $this->ajusterColonnes($feuille, 3);
        $feuille->getProtection()->setSheet(true);
    }

    /** @param array<string, RessourceDEchange> $ressources */
    private function ecrireDictionnaire(Spreadsheet $classeur, array $ressources): void
    {
        $feuille = $classeur->createSheet();
        $feuille->setTitle(self::FEUILLE_DICTIONNAIRE);

        $feuille->fromArray(
            ['Feuille', 'Code technique', 'Intitulé', 'Type', 'Obligatoire', 'Modifiable à l\'import', 'À savoir'],
            null,
            'A1',
        );
        $this->styleEntete($feuille, 'A1:G1');

        $ligne = 2;
        foreach ($ressources as $ressource) {
            foreach (self::COLONNES_TECHNIQUES as $code) {
                $feuille->fromArray([
                    $ressource->feuille,
                    $code,
                    self::LIBELLES_TECHNIQUES[$code],
                    'technique',
                    self::NON,
                    in_array($code, [CanevasDEchange::COL_ACTION, CanevasDEchange::COL_REF], true) ? self::OUI : self::NON,
                    $this->noticeTechnique($code),
                ], null, 'A' . $ligne);
                ++$ligne;
            }

            foreach ($ressource->colonnes as $colonne) {
                $feuille->fromArray([
                    $ressource->feuille,
                    $colonne->code,
                    $colonne->libelle,
                    $colonne->type,
                    $colonne->obligatoire ? self::OUI : self::NON,
                    $colonne->estModifiable() ? self::OUI : self::NON,
                    $colonne->noticeDictionnaire(),
                ], null, 'A' . $ligne);
                ++$ligne;
            }
        }

        $this->ajusterColonnes($feuille, 7);
        $feuille->getStyle('G1:G' . max(1, $ligne - 1))->getAlignment()->setWrapText(true);
        $feuille->getColumnDimension('G')->setAutoSize(false);
        $feuille->getColumnDimension('G')->setWidth(70);
        $feuille->freezePane('A2');
        $feuille->getProtection()->setSheet(true);
    }

    private function noticeTechnique(string $code): string
    {
        return match ($code) {
            CanevasDEchange::COL_UID => 'Identifiant de la ligne. Laissez-le tel quel pour modifier une ligne existante ; '
                . 'laissez-le VIDE pour créer une ligne. Dans un GABARIT VIERGE, cette colonne est vide '
                . 'partout : toutes les lignes que vous y saisirez seront donc des CRÉATIONS, jamais des '
                . 'mises à jour de fiches existantes.',
            CanevasDEchange::COL_ACTION => sprintf(
                'Laissez vide et l\'action se déduit : identifiant vide = création, identifiant rempli = mise à jour. '
                . 'Écrivez %s pour supprimer — une suppression ne se déduit jamais.',
                CanevasDEchange::ACTION_SUPPRIMER,
            ),
            CanevasDEchange::COL_REF => 'Repère de votre choix (ex. C1) permettant à une autre ligne NOUVELLE de ce même '
                . 'fichier de désigner celle-ci. C\'est ainsi que l\'on crée un client et son contrat en un seul import.',
            CanevasDEchange::COL_MODIFIE_LE => 'Date de dernière modification au moment de l\'export. Sert à détecter '
                . 'qu\'un collègue a modifié la même ligne entre-temps.',
            default => '',
        };
    }

    /**
     * Feuille des listes déroulantes, masquée.
     *
     * @param array<string, RessourceDEchange> $ressources
     *
     * @return array<string, string> « ressource.colonne » => nom de plage nommée
     */
    private function ecrireListes(Spreadsheet $classeur, array $ressources): array
    {
        $feuille = $classeur->createSheet();
        $feuille->setTitle(self::FEUILLE_LISTES);

        $plages = [];
        $colonne = 1;

        // Liste booléenne partagée : une seule colonne pour toutes les cases du classeur.
        $this->ecrireListe($classeur, $feuille, $colonne, 'L_BOOLEEN', ['Oui / Non', self::OUI, self::NON]);
        $plages['__booleen__'] = 'L_BOOLEEN';
        ++$colonne;

        // Liste des actions : la colonne _action de chaque feuille y renvoie.
        $this->ecrireListe($classeur, $feuille, $colonne, 'L_ACTION', [
            'Actions',
            CanevasDEchange::ACTION_CREER,
            CanevasDEchange::ACTION_MAJ,
            CanevasDEchange::ACTION_SUPPRIMER,
        ]);
        $plages['__action__'] = 'L_ACTION';
        ++$colonne;

        foreach ($ressources as $ressource) {
            foreach ($ressource->colonnes as $col) {
                if (!$col->aUneListe() || !$col->estModifiable()) {
                    continue;
                }
                $nom = $this->nomDePlage($ressource->code, $col->code, $plages);
                // On propose les LIBELLÉS : une liste de codes (0, 1, 2…) ne se relit pas.
                // La relecture accepte les deux, en comparant sur une forme normalisée.
                $this->ecrireListe($classeur, $feuille, $colonne, $nom, array_merge(
                    [$ressource->code . ' — ' . $col->libelle],
                    array_values($col->choix),
                ));
                $plages[$ressource->code . '.' . $col->code] = $nom;
                ++$colonne;
            }
        }

        $this->ajusterColonnes($feuille, max(1, $colonne - 1));
        $feuille->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $feuille->getProtection()->setSheet(true);

        return $plages;
    }

    /**
     * Écrit une liste en colonne et la déclare comme plage nommée.
     *
     * @param string[] $valeurs la première est l'en-tête, hors plage
     */
    private function ecrireListe(Spreadsheet $classeur, Worksheet $feuille, int $colonne, string $nom, array $valeurs): void
    {
        $lettre = Coordinate::stringFromColumnIndex($colonne);
        foreach ($valeurs as $i => $valeur) {
            $feuille->setCellValueExplicit(
                $lettre . ($i + 1),
                (string) $valeur,
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING,
            );
        }
        $this->styleEntete($feuille, $lettre . '1');

        $derniere = count($valeurs);
        if ($derniere < 2) {
            return; // En-tête seul : rien à proposer.
        }

        $classeur->addNamedRange(new NamedRange(
            $nom,
            $feuille,
            sprintf('$%s$2:$%s$%d', $lettre, $lettre, $derniere),
        ));
    }

    /**
     * Nom de plage nommée valide pour Excel : lettres, chiffres et soulignés, jamais
     * commençant par un chiffre, et unique dans le classeur.
     *
     * @param array<string, string> $dejaPris
     */
    private function nomDePlage(string $ressource, string $colonne, array $dejaPris): string
    {
        $base = 'L_' . preg_replace('/[^A-Za-z0-9]/', '', $ressource) . '_' . preg_replace('/[^A-Za-z0-9]/', '', $colonne);
        $base = mb_substr($base, 0, 240);

        $nom = $base;
        $i = 2;
        while (in_array($nom, $dejaPris, true)) {
            $nom = $base . '_' . $i;
            ++$i;
        }

        return $nom;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Feuilles de données
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * @param array<int, array<string, mixed>> $lignes
     * @param array<string, string>            $plages
     */
    private function ecrireFeuilleDeDonnees(
        Spreadsheet $classeur,
        RessourceDEchange $ressource,
        array $lignes,
        array $plages,
    ): void {
        $feuille = $classeur->createSheet();
        $feuille->setTitle($ressource->feuille);

        $codes = array_merge(self::COLONNES_TECHNIQUES, array_map(
            static fn (ColonneDEchange $c) => $c->code,
            $ressource->colonnes,
        ));
        $libelles = array_merge(
            array_map(static fn (string $c) => self::LIBELLES_TECHNIQUES[$c], self::COLONNES_TECHNIQUES),
            array_map(static fn (ColonneDEchange $c) => $c->libelle, $ressource->colonnes),
        );

        $feuille->fromArray($libelles, null, 'A1');
        $feuille->fromArray($codes, null, 'A2');
        $derniereLettre = Coordinate::stringFromColumnIndex(count($codes));
        $this->styleEntete($feuille, 'A1:' . $derniereLettre . '1');

        // La ligne 2 fait foi au parsing, mais n'a rien à dire à l'utilisateur : on la
        // masque plutôt que de l'effacer. La supprimer rendrait le fichier illisible
        // par l'import, ce que rien à l'écran ne laisserait deviner.
        $feuille->getRowDimension(2)->setVisible(false);
        $feuille->getStyle('A2:' . $derniereLettre . '2')->getFont()->setSize(8)->getColor()->setARGB('FF999999');

        $numero = 3;
        foreach ($lignes as $ligne) {
            $this->ecrireLigne($feuille, $ressource, $codes, $ligne, $numero);
            ++$numero;
        }

        $this->appliquerValidations($feuille, $ressource, $codes, $plages, max($numero - 1, 3));
        $this->appliquerFormats($feuille, $ressource, $codes, max($numero - 1, 3));

        $this->ajusterColonnes($feuille, count($codes));
        // Le volet fige les deux lignes d'en-tête : la seconde étant masquée, l'effet
        // visible est une ligne de titres qui reste à l'écran.
        $feuille->freezePane('A3');
    }

    /**
     * @param string[]             $codes
     * @param array<string, mixed> $ligne
     */
    private function ecrireLigne(Worksheet $feuille, RessourceDEchange $ressource, array $codes, array $ligne, int $numero): void
    {
        foreach ($codes as $index => $code) {
            $valeur = $ligne[$code] ?? null;
            if ($valeur === null || $valeur === '') {
                continue;
            }

            $cellule = Coordinate::stringFromColumnIndex($index + 1) . $numero;
            $colonne = $ressource->colonne($code);

            // Les identifiants, renvois et empreintes sont écrits en TEXTE explicite :
            // « Client:412 » passerait encore, mais un code d'entité purement numérique
            // deviendrait un nombre, et le renvoi serait perdu.
            if ($colonne === null || $colonne->type === ColonneDEchange::TYPE_REFERENCE) {
                $feuille->setCellValueExplicit($cellule, (string) $valeur, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                continue;
            }

            $feuille->setCellValue($cellule, $valeur);
        }
    }

    /**
     * Listes déroulantes : sur la colonne `_action` de chaque feuille, sur les booléens,
     * et sur tout champ à valeurs fermées.
     *
     * @param string[]              $codes
     * @param array<string, string> $plages
     */
    private function appliquerValidations(
        Worksheet $feuille,
        RessourceDEchange $ressource,
        array $codes,
        array $plages,
        int $derniereLigne,
    ): void {
        // On équipe une marge de lignes VIDES sous les données : sans elle, la ligne
        // qu'on ajoute à la main n'hériterait d'aucune liste déroulante, et l'aide
        // disparaîtrait précisément là où elle sert le plus.
        $jusqua = $derniereLigne + 200;

        foreach ($codes as $index => $code) {
            $lettre = Coordinate::stringFromColumnIndex($index + 1);

            $plage = null;
            if ($code === CanevasDEchange::COL_ACTION) {
                $plage = $plages['__action__'] ?? null;
            } else {
                $colonne = $ressource->colonne($code);
                if ($colonne !== null && $colonne->estModifiable()) {
                    if ($colonne->type === ColonneDEchange::TYPE_BOOLEEN) {
                        $plage = $plages['__booleen__'] ?? null;
                    } elseif ($colonne->aUneListe()) {
                        $plage = $plages[$ressource->code . '.' . $colonne->code] ?? null;
                    }
                }
            }

            if ($plage === null) {
                continue;
            }

            $validation = $feuille->getCell($lettre . '3')->getDataValidation();
            $validation->setType(DataValidation::TYPE_LIST)
                // WARNING et non STOP : l'utilisateur doit pouvoir coller un lot de
                // valeurs sans qu'Excel le bloque cellule par cellule. Le contrôle qui
                // fait autorité est celui du serveur, pas celui du tableur.
                ->setErrorStyle(DataValidation::STYLE_WARNING)
                ->setAllowBlank(true)
                ->setShowDropDown(true)
                ->setShowErrorMessage(true)
                ->setErrorTitle('Valeur inattendue')
                ->setError('Choisissez une valeur de la liste, ou laissez la cellule vide.')
                ->setFormula1('=' . $plage);

            $feuille->setDataValidation($lettre . '3:' . $lettre . $jusqua, clone $validation);
        }
    }

    /**
     * Formats de nombre et de date, dérivés du canevas : un montant s'affiche comme un
     * montant, une date comme une date NATIVE (jamais du texte, que le retour ne saurait
     * plus relire de façon fiable).
     *
     * @param string[] $codes
     */
    private function appliquerFormats(Worksheet $feuille, RessourceDEchange $ressource, array $codes, int $derniereLigne): void
    {
        foreach ($codes as $index => $code) {
            $lettre = Coordinate::stringFromColumnIndex($index + 1);

            $format = null;
            if ($code === CanevasDEchange::COL_MODIFIE_LE) {
                $format = 'dd/mm/yyyy hh:mm';
            } else {
                $colonne = $ressource->colonne($code);
                if ($colonne !== null) {
                    $format = match ($colonne->type) {
                        ColonneDEchange::TYPE_DATE     => 'dd/mm/yyyy',
                        ColonneDEchange::TYPE_DATETIME => 'dd/mm/yyyy hh:mm',
                        default                        => $colonne->formatExcel,
                    };
                }
            }

            if ($format === null) {
                continue;
            }

            $feuille->getStyle($lettre . '3:' . $lettre . $derniereLigne)
                ->getNumberFormat()
                ->setFormatCode($format);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Mise en forme
    // ─────────────────────────────────────────────────────────────────────────────

    private function styleEntete(Worksheet $feuille, string $plage): void
    {
        $style = $feuille->getStyle($plage);
        $style->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $style->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF' . self::COBALT);
    }

    private function ajusterColonnes(Worksheet $feuille, int $nombre): void
    {
        for ($i = 1; $i <= $nombre; ++$i) {
            $feuille->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
    }
}
