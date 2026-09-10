<?php

namespace App\Echange\Etat;

use App\Ai\Finance\EconomieTranche;
use App\Ai\Presentation\Colonnes;
use App\Echange\Classeur\EcrivainJsbx;
use App\Echange\Classeur\Manifeste;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
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
    /**
     * La clé, en colonne A du dictionnaire, sous laquelle vit l'identifiant du cabinet.
     *
     * ⚠ ÉCRITE ET RELUE PAR LA MÊME CONSTANTE. La retoucher d'un côté seulement rendrait
     * tous les fichiers déjà distribués illisibles au dépôt, sans message utile.
     */
    public const CLE_CABINET = 'CABINET';

    /** Le bandeau de tête, selon que le classeur porte des données ou attend les vôtres. */
    public const CLE_REIMPORTABLE = 'CE FICHIER SE REDÉPOSE';
    public const CLE_GABARIT = 'GABARIT VIERGE';

    /** Première ligne de données : l'en-tête n'en occupe qu'une. */
    private const LIGNE_DONNEES = 2;


    /**
     * @param array<string, ColonneEtat>              $colonnes
     * @param iterable<int, array<string, mixed>>     $lignes
     */
    public function ecrire(
        Manifeste $manifeste,
        array $colonnes,
        iterable $lignes,
        string $validite = ValiditeDesTranches::TOUTES,
        string $exercice = ExerciceDesTranches::TOUS,
        bool $gabarit = false,
    ): Spreadsheet
    {
        $classeur = new Spreadsheet();
        $classeur->removeSheetByIndex(0);

        // ⚠ PAS DE FEUILLE `_MANIFESTE`. L'état ne se relit pas : il n'a besoin ni
        // d'empreinte ni de périmètre déclaré pour être reconnu. Le manifeste reste
        // CONSTRUIT — son empreinte alimente l'occurrence facturée — mais il n'est plus
        // écrit. Ce qu'il portait d'utile au lecteur (« ce fichier ne se redépose pas »)
        // ouvre désormais le dictionnaire.
        $this->ecrireDictionnaire($classeur, $manifeste, $colonnes, $validite, $exercice, $gabarit);
        $this->ecrireDonnees($classeur, $colonnes, $lignes, $gabarit);

        // ⚠ PAS DE SYNTHÈSE SUR UN GABARIT. Ses sommes conditionnelles pointeraient une
        // plage sans données : la feuille annoncerait un portefeuille à zéro, ce qui se
        // lit comme une panne et non comme un fichier à remplir.
        if (!$gabarit) {
            $this->ecrireSynthese($classeur, $colonnes, $lignes);
        }

        $classeur->setActiveSheetIndex(0);

        return $classeur;
    }

    /**
     * LE DICTIONNAIRE — trois bandeaux, puis les colonnes rangées par famille.
     *
     * ── CE QUE LA MISE EN FORME FAIT ICI, ET QUI N'EST PAS DE L'ORNEMENT ────────────
     * ⚠ TROIS BANDEAUX AVANT LA PREMIÈRE COLONNE. « Lecture seule », « Périmètre » et
     * « Exercice » ne décrivent pas une colonne : ils décrivent LE FICHIER. Écrits dans
     * la même table que les soixante entrées suivantes, ils se lisaient comme trois
     * colonnes de plus. Un fond, une couleur, et le lecteur sait d'un coup d'œil ce qui
     * parle du fichier et ce qui parle d'une colonne. (Bastien & Scapin > Signifiance ;
     * Nielsen > Visibilité de l'état du système.)
     *
     * ⚠ ET UNE LIGNE DE FAMILLE TOUS LES CINQ OU SIX POSTES. Soixante et une entrées à
     * la file forment un mur : on ne cherche plus, on parcourt. Les libellés portent
     * déjà leur famille (« Prime · Payée ») ; on la donne comme repère plutôt que de la
     * laisser se répéter sans jamais se voir. (Bastien & Scapin > Charge de travail.)
     *
     * @param array<string, ColonneEtat> $colonnes
     */
    private function ecrireDictionnaire(
        Spreadsheet $classeur,
        Manifeste $manifeste,
        array $colonnes,
        string $validite,
        string $exercice,
        bool $gabarit,
    ): void
    {
        $feuille = $classeur->createSheet();
        $feuille->setTitle(EcrivainJsbx::FEUILLE_DICTIONNAIRE);
        $feuille->getTabColor()->setARGB(Charte::COBALT_SOMBRE);

        $feuille->fromArray(['Colonne', 'Nature', 'Ce qu\'elle veut dire'], null, 'A1');
        $this->styleEntete($feuille, 'A1:C1');

        // ⚠ EN TÊTE, ET PAS AILLEURS : c'est la première chose que doit lire celui qui
        // retrouve ce fichier dans six mois, sans l'écran sous les yeux. Le fond
        // d'avertissement le dit avant même qu'on ait lu la phrase — c'est de la
        // PRÉVENTION DE L'ERREUR, pas une décoration.
        // ⚠ ET CE BANDEAU DISAIT LE CONTRAIRE. Il annonçait « cet état ne peut pas être
        // réimporté » — vrai tant que le fichier ne portait que des résultats, faux depuis
        // qu'il porte aussi ce qui les produit. Un fichier qui se trompe sur sa propre
        // nature est pire qu'un fichier muet : on le range, et on ne le ressort jamais.
        $feuille->fromArray($gabarit ? [
            self::CLE_GABARIT,
            'Nature du fichier',
            'Ce classeur est VIDE : remplissez une ligne par échéance de prime, puis déposez-le '
            . 'dans l\'onglet Importer. Il ne porte QUE les colonnes qui se reprennent — tout le '
            . 'reste (primes totales, taxes, commissions) est calculé par l\'application, et '
            . 'c\'est pourquoi vous ne le trouverez pas ici. Un export de vos données, lui, les '
            . 'porte toutes et se redépose tout aussi bien : les colonnes calculées y sont '
            . 'simplement ignorées.',
        ] : [
            self::CLE_REIMPORTABLE,
            'Nature du fichier',
            'Ce fichier SE REDÉPOSE dans l\'onglet Importer : corrigez-le, ajoutez des lignes, '
            . 'et il sera repris. Seules les colonnes marquées « Repris à l\'import » sont '
            . 'relues — les autres sont des RÉSULTATS (soldes, encaissements, exigibilités) que '
            . 'l\'application recalcule, et les modifier n\'a aucun effet.',
        ], null, 'A2');
        $this->bandeau($feuille, 2, Charte::AVERTISSEMENT_FOND, Charte::AVERTISSEMENT_TEXTE);

        // ⚠ QUELLES TRANCHES CE FICHIER PORTE. Un état des seuls PROJETS ressemble trait
        // pour trait à un état de polices : mêmes colonnes, mêmes montants d'allure. Le
        // confondre avec le portefeuille réel, c'est annoncer un chiffre d'affaires qu'on
        // n'a pas. Le fichier doit donc le dire lui-même, et en tête.
        // ⚠ L'IDENTITÉ DU CABINET VIT ICI, ET NON DANS UN MANIFESTE. La feuille
        // `_MANIFESTE` a été retirée de l'état : elle ne disait rien au lecteur. Mais
        // l'importation, elle, doit savoir d'où vient le fichier — importer les données
        // d'un cabinet dans un autre est parfois voulu, jamais anodin. La clé est donc
        // portée par le dictionnaire, à un endroit stable, et lue par
        // `LecteurDeLEtat::cabinet()`.
        $feuille->fromArray([
            self::CLE_CABINET,
            $manifeste->uidCabinet,
            sprintf(
                'Fichier produit par le cabinet « %s » le %s (version %s). Ne modifiez pas '
                . 'cette ligne : elle permet de vérifier, au dépôt, que le fichier revient '
                . 'bien dans le cabinet dont il est issu.',
                $manifeste->nomCabinet,
                $manifeste->genereLe->format('d/m/Y à H:i'),
                $manifeste->versionSchema,
            ),
        ], null, 'A5');
        $this->bandeau($feuille, 5, Charte::GRIS_MUET, Charte::TEXTE_CORPS);

        $feuille->fromArray([
            'PÉRIMÈTRE',
            ValiditeDesTranches::libelle($validite),
            ValiditeDesTranches::explication($validite),
        ], null, 'A3');
        $this->bandeau($feuille, 3, Charte::COBALT_TRES_CLAIR, Charte::TEXTE);

        // Même raison que le périmètre : un état d'un seul exercice a exactement l'allure
        // d'un état complet, en plus court.
        $feuille->fromArray([
            'EXERCICE',
            ExerciceDesTranches::libelle($exercice),
            ExerciceDesTranches::explication($exercice),
        ], null, 'A4');
        $this->bandeau($feuille, 4, Charte::COBALT_TRES_CLAIR, Charte::TEXTE);

        // ── LA LÉGENDE DES COULEURS ─────────────────────────────────────────────────
        // ⚠ LA COULEUR NE PORTE JAMAIS L'INFORMATION SEULE (WCAG 1.4.1). L'en-tête de la
        // feuille DONNEES distingue désormais les colonnes qu'on relit de celles que
        // l'application recalcule ; ce bandeau le dit en toutes lettres, et la colonne A
        // de chaque entrée ci-dessous le DÉMONTRE en portant la teinte de sa nature. Un
        // lecteur qui ne distingue pas les fonds — ou qui imprime en noir et blanc — lit
        // la même chose.
        $feuille->fromArray([
            'COULEURS DES COLONNES',
            $gabarit ? 'Tout est à remplir' : 'Bleu = à vous',
            $gabarit
                ? 'Ce gabarit ne porte QUE des colonnes à remplir : leur en-tête est bleu cobalt, '
                    . 'comme la pastille en regard de chaque ligne ci-dessous. Tout le reste — primes '
                    . 'totales, commissions, taxes — est calculé par l\'application, qui ne vous le '
                    . 'demande pas.'
                : 'Dans la feuille DONNEES, un en-tête BLEU COBALT signale une colonne reprise à '
                    . 'l\'import : c\'est là, et là seulement, que vos corrections seront relues. Un '
                    . 'en-tête GRIS signale une valeur calculée par l\'application : elle est exportée '
                    . 'pour information, et modifiée elle serait ignorée. Chaque ligne ci-dessous porte '
                    . 'la même pastille en colonne A, et le dit aussi en toutes lettres.',
        ], null, 'A6');
        $this->bandeau($feuille, 6, Charte::COBALT_TRES_CLAIR, Charte::TEXTE);

        $numero = 7;
        $familleCourante = null;

        foreach ($colonnes as $colonne) {
            $famille = $colonne->groupe();
            if ($famille !== $familleCourante) {
                $feuille->setCellValue('A' . $numero, mb_strtoupper($famille));
                $this->bandeau($feuille, $numero, Charte::GRIS_MUET, Charte::TEXTE_CORPS);
                $familleCourante = $famille;
                ++$numero;
            }

            // ⚠ LA NOTICE D'ABORD, L'EXPLICATION ENSUITE. Rien ne distingue à l'œil une
            // colonne qu'on peut corriger d'une colonne que l'application recalcule : un
            // courtier qui rectifie « Prime · Solde » puis redépose son fichier croirait
            // l'avoir corrigée, et ne comprendrait jamais pourquoi l'écran dit autre chose.
            $feuille->fromArray(
                [
                    $colonne->libelle,
                    $colonne->natureLisible(),
                    $colonne->notice() . ' ' . $colonne->explication,
                ],
                null,
                'A' . $numero,
            );

            // ⚠ LE RETOUR À LA LIGNE MANQUAIT, ET C'ÉTAIT LE PIRE DÉFAUT DE CETTE FEUILLE.
            // Seuls les trois bandeaux le posaient ; les explications, elles, débordaient
            // en une seule ligne interminable qu'on ne lisait tout simplement pas.
            $this->entree($feuille, $numero);

            // La pastille de nature, en regard du libellé : la légende n'est plus seulement
            // décrite en tête de feuille, elle se vérifie ligne à ligne. Même teinte que
            // l'en-tête de la colonne dans DONNEES.
            $feuille->getStyle('A' . $numero)->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB($colonne->lectureSeule() ? Charte::GRIS_MUET : Charte::COBALT);
            $feuille->getStyle('A' . $numero)->getFont()->setBold(true)
                ->getColor()->setARGB($colonne->lectureSeule() ? Charte::TEXTE_CORPS : Charte::BLANC);

            ++$numero;
        }

        // La règle du métier, à la fin, en toutes lettres.
        ++$numero;
        $feuille->setCellValue('A' . $numero, 'RÈGLE DU MÉTIER');
        $feuille->setCellValue('C' . $numero, EconomieTranche::NOTE);
        $this->bandeau($feuille, $numero, Charte::COBALT_TRES_CLAIR, Charte::TEXTE);
        $feuille->getStyle('C' . $numero)->getAlignment()->setWrapText(true)->setVertical('top');
        $feuille->getRowDimension($numero)->setRowHeight(-1);

        // ⚠ ET COMMENT LIRE LA LIGNE DE TOTAUX, qui surprend toujours une fois : elle
        // suit le filtre. Le dire ici évite de citer en réunion un total qui n'était pas
        // celui du portefeuille.
        ++$numero;
        $feuille->setCellValue('A' . $numero, 'LIGNE « TOTAUX »');
        $feuille->setCellValue('C' . $numero, sprintf(
            'En bas de la feuille %s, la ligne TOTAUX ne somme que les lignes AFFICHÉES. '
            . 'Filtrez sur un assureur ou sur un mois, et les totaux suivent le filtre. '
            . 'Ce sont des formules : elles se recalculent si vous corrigez une valeur.',
            EtatDuPortefeuille::FEUILLE,
        ));
        $this->bandeau($feuille, $numero, Charte::COBALT_TRES_CLAIR, Charte::TEXTE);
        $feuille->getStyle('C' . $numero)->getAlignment()->setWrapText(true)->setVertical('top');
        $feuille->getRowDimension($numero)->setRowHeight(-1);

        $feuille->getColumnDimension('A')->setWidth(46);
        $feuille->getColumnDimension('B')->setWidth(18);
        $feuille->getColumnDimension('C')->setWidth(96);

        // L'en-tête reste en vue : sans lui, la troisième colonne d'un long dictionnaire
        // n'a plus de nom dès qu'on a fait défiler.
        $feuille->freezePane('A2');
        $this->preparerImpression($feuille, 'A1:C' . $numero, 1);
    }

    /**
     * UN BANDEAU : une ligne qui parle du fichier, ou d'une famille, et non d'une colonne.
     *
     * ⚠ LE COUPLE FOND / TEXTE VIENT ENSEMBLE. La charte fixe les associations admises
     * (règle 2) parce que ce sont elles qui portent le contraste : `#664d03` sur
     * `#fff3cd`, blanc sur cobalt. Les passer d'un bloc empêche d'en changer une moitié.
     */
    private function bandeau(Worksheet $feuille, int $ligne, string $fond, string $texte): void
    {
        $style = $feuille->getStyle('A' . $ligne . ':C' . $ligne);
        $style->getFont()->setBold(true)->getColor()->setARGB($texte);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($fond);
        $style->getAlignment()->setWrapText(true)->setVertical('top');

        // ⚠ HAUTEUR AUTOMATIQUE (-1) ET NON UNE VALEUR FIXE : ces cellules portent des
        // paragraphes entiers, dont la longueur dépend du périmètre choisi. Une hauteur
        // en dur tronquerait la moitié du texte le jour où l'explication s'allonge.
        $feuille->getRowDimension($ligne)->setRowHeight(-1);
    }

    /** Une entrée de colonne : le texte respire, et une bordure sépare de la suivante. */
    private function entree(Worksheet $feuille, int $ligne): void
    {
        $style = $feuille->getStyle('A' . $ligne . ':C' . $ligne);
        $style->getAlignment()->setWrapText(true)->setVertical('top');
        $style->getFont()->getColor()->setARGB(Charte::TEXTE_CORPS);
        $style->getBorders()->getBottom()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setARGB(Charte::BORDURE);

        $feuille->getStyle('A' . $ligne)->getFont()->setBold(true)
            ->getColor()->setARGB(Charte::TEXTE);
        $feuille->getStyle('B' . $ligne)->getFont()->getColor()->setARGB(Charte::TEXTE_MUET);
        $feuille->getRowDimension($ligne)->setRowHeight(-1);
    }

    /**
     * @param array<string, ColonneEtat>          $colonnes
     * @param iterable<int, array<string, mixed>> $lignes
     */
    private function ecrireDonnees(Spreadsheet $classeur, array $colonnes, iterable $lignes, bool $gabarit = false): void
    {
        $feuille = $classeur->createSheet();
        $feuille->setTitle(EtatDuPortefeuille::FEUILLE);

        $feuille->getTabColor()->setARGB(Charte::COBALT);

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

        // ⚠ APRÈS `appliquerFormats()`, ET PAS AVANT L'ÉCRITURE DES LIGNES : le filet de
        // famille descend jusqu'à la dernière donnée, dont le numéro n'existe qu'ici. Rien
        // entre-temps ne touche l'en-tête, si bien que la surcharge du fond posé par
        // `styleEntete()` reste celle d'avant.
        $this->habillerLesColonnes($feuille, $colonnes, $derniereDonnee);

        // ⚠ LE FILTRE S'ARRÊTE AVANT LES TOTAUX. Les inclure ferait voyager cette ligne
        // au milieu des données au premier tri — un total posé entre deux tranches.
        $feuille->setAutoFilter('A1:' . $derniereLettre . $derniereDonnee);

        // ⚠ PAS DE TOTAUX SUR UN GABARIT. `SOUS.TOTAL` sur une plage sans données rend
        // zéro : le pied de feuille annoncerait un portefeuille vide, et l'utilisateur
        // effacerait cette ligne — cassant les plages du jour où il aura saisi.
        if (!$gabarit) {
            $this->ecrireTotaux($feuille, $colonnes, $derniereDonnee, $derniereLettre);
        }
        $this->ajusterColonnes($feuille, \count($codes));

        // Le volet fige l'en-tête ET la colonne d'identifiant : sans elle, on perd la
        // ligne qu'on lit dès qu'on fait défiler vers la droite — et il y a cinquante
        // colonnes à parcourir.
        $feuille->freezePane('B2');

        if (!$gabarit) {
            $this->alternerLesLignes($feuille, $derniereLettre, $derniereDonnee);
        }
        $this->signalerLesNegatifs($feuille, $colonnes, $derniereDonnee);
        $this->preparerImpression($feuille, 'A1:' . $derniereLettre . ($derniereDonnee + 1), 1);
    }

    /**
     * L'EN-TÊTE DIT LA NATURE DE SA COLONNE ; UN FILET SÉPARE LES FAMILLES.
     *
     * ── CE QUE LE FOND PORTE, ET POURQUOI CE N'EST PLUS LA FAMILLE ──────────────────
     * Le dictionnaire dit de chaque colonne si elle se relit — « Repris à l'import » ou
     * « Calculé par l'application : IGNORÉ à l'import » —, mais la feuille de données ne
     * le montrait nulle part. Sur soixante-quatorze colonnes dont vingt-cinq se
     * reprennent, il fallait ouvrir le dictionnaire colonne par colonne pour savoir
     * laquelle on pouvait corriger.
     *
     * ⚠ ET LE FOND NE PEUT PORTER QU'UNE CHOSE. Il revenait à l'alternance des familles ;
     * la nature l'emporte, parce qu'elle répond à une question que l'utilisateur se pose
     * — « que puis-je corriger ? » — quand l'alternance ne disait même pas LAQUELLE était
     * la famille, seulement où elle changeait. Le filet le dit aussi bien, et il descend
     * le long des données au lieu de s'arrêter à la première ligne.
     *
     * ⚠ LE CORPS EST INTERDIT À CE SIGNAL. `alternerLesLignes()` zèbre la plage de données
     * en mise en forme CONDITIONNELLE, laquelle l'emporte sur le format direct dans Excel :
     * une teinte posée sur une colonne n'apparaîtrait qu'une ligne sur deux, en damier —
     * pire que pas de signal du tout.
     *
     * ⚠ RIEN N'EST PORTÉ PAR LA SEULE COULEUR (WCAG 1.4.1). Le dictionnaire porte la phrase
     * en toutes lettres pour chaque colonne, et un bandeau y explique les deux fonds. Un
     * lecteur qui ne distingue pas les teintes — ou qui imprime en noir et blanc — ne perd
     * aucune information.
     *
     * ⚠ ET AUCUNE COULEUR N'EST INVENTÉE. Les quatre teintes sont déjà dans la charte, et
     * les deux couples respectent ses associations obligatoires (contrastes de 8,6:1 et
     * 7,4:1, au-delà du 4,5:1 exigé).
     *
     * @param array<string, ColonneEtat> $colonnes
     */
    private function habillerLesColonnes(Worksheet $feuille, array $colonnes, int $derniereLigne): void
    {
        $index = 0;
        $famille = null;

        foreach ($colonnes as $colonne) {
            ++$index;
            $lettre = Coordinate::stringFromColumnIndex($index);

            // ── LA NATURE, SUR L'EN-TÊTE ────────────────────────────────────────────
            // Ce qui est actionnable porte la couleur de marque ; ce que l'application
            // recalcule s'efface. Sur soixante-quatorze colonnes, les vingt-cinq qu'on
            // peut corriger ressortent en bleu au milieu du gris.
            $calculee = $colonne->lectureSeule();
            $entete = $feuille->getStyle($lettre . '1');
            $entete->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB($calculee ? Charte::GRIS_MUET : Charte::COBALT);
            $entete->getFont()->getColor()->setARGB($calculee ? Charte::TEXTE_CORPS : Charte::BLANC);

            // ── LA FAMILLE, AU FILET ────────────────────────────────────────────────
            // Le fond ne peut porter qu'une chose, et la nature prime : elle répond à
            // « que puis-je corriger ? », quand l'alternance ne disait même pas LAQUELLE
            // était la famille. Un filet qui descend sépare mieux, d'ailleurs, que deux
            // bleus qu'on ne distingue qu'à l'œil aiguisé.
            if ($colonne->groupe() !== $famille) {
                $famille = $colonne->groupe();

                // ⚠ JAMAIS SUR LA PREMIÈRE COLONNE : un filet au bord gauche de la
                // feuille ne sépare rien, il encadre.
                // ⚠ ET IL S'ARRÊTE À LA DERNIÈRE DONNÉE, jamais à la ligne de totaux :
                // celle-ci porte son propre habillage, et la traverser d'un trait
                // vertical la ferait lire comme une ligne de données.
                if ($index > 1) {
                    $feuille->getStyle($lettre . '1:' . $lettre . $derniereLigne)
                        ->getBorders()->getLeft()
                        ->setBorderStyle(Border::BORDER_MEDIUM)
                        ->getColor()->setARGB(Charte::COBALT);
                }
            }
        }
    }

    /**
     * UNE LIGNE SUR DEUX EN GRIS PÂLE — par MISE EN FORME CONDITIONNELLE, et non ligne à
     * ligne.
     *
     * ⚠ LA FORMULE COMPTE LES LIGNES VISIBLES, PAS LES NUMÉROS DE LIGNE. Un banal
     * `MOD(LIGNE();2)` bande d'après la position absolue : dès qu'on filtre sur un
     * assureur, il reste trois lignes grises côte à côte et deux blanches, et le repère
     * visuel se retourne contre le lecteur au moment précis où il en a le plus besoin.
     * `SOUS.TOTAL(103;…)` ne compte que ce qui s'affiche, si bien que l'alternance reste
     * vraie sous n'importe quel filtre.
     *
     * ⚠ ET C'EST UNE SEULE RÈGLE, PAS MILLE STYLES. Poser un fond ligne par ligne sur
     * soixante colonnes ferait, sur un gros portefeuille, des dizaines de milliers
     * d'applications de style — le genre de détail qui fait passer un export de deux
     * secondes à plusieurs minutes.
     */
    private function alternerLesLignes(Worksheet $feuille, string $derniereLettre, int $derniereDonnee): void
    {
        if ($derniereDonnee < self::LIGNE_DONNEES) {
            return;
        }

        $regle = new Conditional();
        $regle->setConditionType(Conditional::CONDITION_EXPRESSION);
        $regle->addCondition(sprintf(
            'MOD(SUBTOTAL(103,$A$%1$d:$A%2$d),2)=0',
            self::LIGNE_DONNEES,
            self::LIGNE_DONNEES,
        ));
        $regle->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB(Charte::GRIS_PALE);

        $plage = 'A' . self::LIGNE_DONNEES . ':' . $derniereLettre . $derniereDonnee;
        $feuille->setConditionalStyles($plage, [$regle]);
    }

    /**
     * LES MONTANTS NÉGATIFS EN ROUGE — le signe restant écrit.
     *
     * ⚠ UN SOLDE NÉGATIF N'EST PAS UNE ERREUR D'AFFICHAGE : une réserve peut l'être, et
     * l'on ne l'écrête pas. Mais un « − » perdu dans un mur de nombres alignés à droite
     * se manque, et c'est exactement le chiffre qu'il ne faut pas manquer. La couleur ne
     * fait que RENFORCER un signe qui reste là (WCAG 1.4.1 : jamais la couleur seule).
     *
     * ⚠ ET LE ROUGE EST CELUI DE LA CHARTE, pas celui d'Excel. Le format de nombre
     * `[Red]` d'Excel impose sa propre teinte ; une règle conditionnelle, elle, prend le
     * `#dc3545` de la maison. C'est la règle 3 de la charte : le danger ne sert qu'au
     * sémantique, et il a une valeur précise.
     *
     * @param array<string, ColonneEtat> $colonnes
     */
    private function signalerLesNegatifs(Worksheet $feuille, array $colonnes, int $derniereDonnee): void
    {
        if ($derniereDonnee < self::LIGNE_DONNEES) {
            return;
        }

        $index = 0;
        foreach ($colonnes as $colonne) {
            ++$index;
            if ($colonne->role !== Colonnes::MONTANT) {
                continue;
            }

            $regle = new Conditional();
            $regle->setConditionType(Conditional::CONDITION_CELLIS);
            $regle->setOperatorType(Conditional::OPERATOR_LESSTHAN);
            $regle->addCondition('0');
            $regle->getStyle()->getFont()->setBold(true)->getColor()->setARGB(Charte::DANGER);

            $lettre = Coordinate::stringFromColumnIndex($index);
            $feuille->setConditionalStyles(
                $lettre . self::LIGNE_DONNEES . ':' . $lettre . $derniereDonnee,
                [$regle],
            );
        }
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

        // ⚠ ELLE DOIT SE VOIR COMME UN PIED DE TABLE, pas comme une soixantième tranche.
        // Le fond la sort du flux des données, le filet cobalt la referme.
        $plage = 'A' . $ligne . ':' . $derniereLettre . $ligne;
        $style = $feuille->getStyle($plage);
        $style->getFont()->setBold(true)->getColor()->setARGB(Charte::TEXTE);
        $style->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB(Charte::COBALT_TRES_CLAIR);
        $style->getBorders()->getTop()
            ->setBorderStyle(Border::BORDER_MEDIUM)
            ->getColor()->setARGB(Charte::COBALT);

        // Le comportement de cette ligne surprend une fois : elle suit le filtre. Le
        // commentaire le dit au point même où la question se pose, et le dictionnaire le
        // répète pour qui lit le fichier sans jamais survoler une cellule.
        $feuille->getComment('A' . $ligne)->getText()->createTextRun(
            'Ces totaux ne comptent que les lignes AFFICHÉES : filtrez, et ils suivent.',
        );
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

        $feuille->getTabColor()->setARGB(Charte::COBALT_TRES_CLAIR);

        $feuille->setCellValue('A1', 'Synthèse du portefeuille');
        $feuille->getStyle('A1')->getFont()->setBold(true)->setSize(14)
            ->getColor()->setARGB(Charte::COBALT);

        // ⚠ DIRE D'OÙ VIENNENT CES CHIFFRES, ET QU'ILS SUIVENT. Sans cette ligne, on lit
        // un tableau posé là, dont on ne sait ni ce qu'il compte ni s'il se met à jour —
        // et la première réaction devant un total qui bouge est de croire à une panne.
        // (Nielsen > Visibilité de l'état du système.)
        $feuille->setCellValue('A2', sprintf(
            'Calculée par formules à partir de la feuille %s : corrigez une ligne là-bas, '
            . 'les totaux d\'ici suivent. Le périmètre retenu est rappelé dans %s.',
            EtatDuPortefeuille::FEUILLE,
            EcrivainJsbx::FEUILLE_DICTIONNAIRE,
        ));
        $feuille->getStyle('A2')->getFont()->setItalic(true)
            ->getColor()->setARGB(Charte::TEXTE_MUET);

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
            // Le mois se détache : c'est le niveau qu'on parcourt, les assureurs étant
            // le détail qu'on ne lit qu'une fois arrivé.
            $styleMois = $feuille->getStyle('A' . $ligne . ':' . $derniereColonne . $ligne);
            $styleMois->getFont()->setBold(true)->getColor()->setARGB(Charte::TEXTE);
            $styleMois->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB(Charte::COBALT_TRES_CLAIR);
            $styleMois->getBorders()->getTop()
                ->setBorderStyle(Border::BORDER_THIN)
                ->getColor()->setARGB(Charte::BORDURE);
            ++$ligne;

            foreach ($assureurs as $assureur) {
                // L'indentation dit la hiérarchie sans qu'on ait à la répéter en mots ;
                // le gris la confirme sans crier. Les deux ensemble, parce que le retrait
                // seul disparaît dès qu'on élargit la colonne.
                $feuille->setCellValue('A' . $ligne, $assureur);
                $feuille->getStyle('A' . $ligne)->getAlignment()->setIndent(2);
                $feuille->getStyle('A' . $ligne . ':' . $derniereColonne . $ligne)
                    ->getFont()->getColor()->setARGB(Charte::TEXTE_CORPS);
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
        $styleTotal = $feuille->getStyle($plageTotal);
        $styleTotal->getFont()->setBold(true)->getColor()->setARGB(Charte::TEXTE);
        $styleTotal->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB(Charte::GRIS_MUET);
        $styleTotal->getBorders()->getTop()
            ->setBorderStyle(Border::BORDER_MEDIUM)
            ->getColor()->setARGB(Charte::COBALT);

        $feuille->getStyle('B4:' . $derniereColonne . $ligne)->getNumberFormat()->setFormatCode('#,##0.00');
        $feuille->getStyle('B4:' . $derniereColonne . $ligne)->getAlignment()->setHorizontal('right');

        // Un négatif se signale ici comme dans les données : même règle, même rouge.
        $negatif = new Conditional();
        $negatif->setConditionType(Conditional::CONDITION_CELLIS);
        $negatif->setOperatorType(Conditional::OPERATOR_LESSTHAN);
        $negatif->addCondition('0');
        $negatif->getStyle()->getFont()->setBold(true)->getColor()->setARGB(Charte::DANGER);
        $feuille->setConditionalStyles('B4:' . $derniereColonne . $ligne, [$negatif]);

        $feuille->getColumnDimension('A')->setWidth(34);
        for ($i = 2; $i <= \count($mesures) + 1; ++$i) {
            $feuille->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setWidth(22);
        }

        // ⚠ LE VOLET FIGE JUSQU'À LA LIGNE 3 INCLUSE : c'est elle qui nomme les colonnes.
        // Le figer plus haut laisserait défiler l'en-tête, et l'on lirait des nombres sans
        // savoir lequel est la prime et lequel la commission.
        $feuille->freezePane('B4');
        $this->preparerImpression($feuille, 'A1:' . $derniereColonne . $ligne, 3);
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

    /**
     * L'EN-TÊTE : blanc sur cobalt, la seule association admise sur ce fond.
     *
     * ⚠ LA HAUTEUR SUIT LA PLAGE, ET NE VAUT PLUS « 1 » EN DUR. L'en-tête de la synthèse
     * est en ligne 3 : la ligne 1 y recevait donc les trente pixels — sur le titre — et
     * l'en-tête restait à l'étroit, ses libellés sur deux lignes rognés.
     */
    private function styleEntete(Worksheet $feuille, string $plage): void
    {
        $style = $feuille->getStyle($plage);
        $style->getFont()->setBold(true)->getColor()->setARGB(Charte::BLANC);
        $style->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB(Charte::COBALT);
        $style->getAlignment()->setVertical('center')->setWrapText(true);

        [$depart] = explode(':', $plage);
        $feuille->getRowDimension((int) Coordinate::coordinateFromString($depart)[1])->setRowHeight(30);
    }

    /**
     * CE QU'IL FAUT POUR QUE LA FEUILLE SORTE À L'IMPRIMANTE SANS ÊTRE ILLISIBLE.
     *
     * ⚠ UN ÉTAT DU PORTEFEUILLE SE PRÉSENTE, et souvent sur papier — à un assureur, en
     * comité. Sans réglage, soixante colonnes partent sur onze feuilles dans le désordre,
     * et les pages 2 à 11 n'ont plus d'en-tête : on tient une colonne de nombres dont
     * personne ne sait le nom. Le paysage, l'ajustement en largeur et la répétition des
     * lignes de titre corrigent les trois d'un coup.
     */
    private function preparerImpression(Worksheet $feuille, string $zone, int $lignesRepetees): void
    {
        $mise = $feuille->getPageSetup();
        $mise->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $mise->setFitToWidth(1);

        // ⚠ ZÉRO EN HAUTEUR = « autant de pages qu'il faut ». Le fixer à 1 tasserait mille
        // tranches sur une seule page, en corps illisible.
        $mise->setFitToHeight(0);
        $mise->setPrintArea($zone);

        if ($lignesRepetees > 0) {
            $mise->setRowsToRepeatAtTopByStartAndEnd(1, $lignesRepetees);
        }

        $feuille->getHeaderFooter()->setOddFooter('&L&B' . $feuille->getTitle() . '&RPage &P / &N');
        $feuille->getPageMargins()->setTop(0.6)->setBottom(0.6)->setLeft(0.4)->setRight(0.4);
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
