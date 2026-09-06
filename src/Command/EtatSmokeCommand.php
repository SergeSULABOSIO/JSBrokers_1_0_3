<?php

namespace App\Command;

use App\Echange\Etat\EtatDuPortefeuille;
use App\Echange\Etat\InjecteurDeTcd;
use App\Echange\Etat\ProducteurDeLEtat;
use App\Entity\Entreprise;
use App\Repository\InviteRepository;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * VÉRIFICATION À CHAUD de l'état du portefeuille, sur les VRAIES données d'un cabinet.
 *
 * Les tests travaillent sur des cabinets fabriqués : des tranches propres, des taxes
 * paramétrées, un partenaire bien rangé. Le réel apporte les cas qu'on n'écrit pas —
 * une tranche sans cotation, une police sans avenant, une taxe absente. C'est cette
 * commande qui les rencontre en premier, et qui les NOMME.
 *
 * ⚠ ELLE NE FACTURE JAMAIS. Elle passe par `produire()`, jamais par `exporter()`. La
 * distinction n'est pas théorique : une commande de diagnostic branchée sur le chemin
 * complet a débité 23 400 tokens au propriétaire d'un cabinet réel pour une vérification
 * que personne n'avait demandée.
 */
#[AsCommand(
    name: 'app:etat:smoke',
    description: 'Produit l\'état du portefeuille d\'un cabinet et rend compte de ce qu\'il contient.',
)]
final class EtatSmokeCommand extends Command
{
    public function __construct(
        private readonly ProducteurDeLEtat $producteur,
        private readonly InviteRepository $invites,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    /** Chemin où conserver le classeur, quand on veut l'ouvrir dans Excel. */
    private ?string $destination = null;

    protected function configure(): void
    {
        $this->addArgument('idEntreprise', InputArgument::REQUIRED, 'Identifiant du cabinet');
        // ⚠ POUR OUVRIR LE FICHIER DANS EXCEL. Les contrôles de cette commande vont aussi
        // loin qu'on peut aller sans tableur ; seule une ouverture réelle tranche.
        $this->addOption('vers', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Conserve le classeur à ce chemin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->destination = $input->getOption('vers') === null ? null : (string) $input->getOption('vers');

        // Les avertissements PHP deviennent des exceptions : c'est exactement le genre de
        // défaut (« Array to string conversion ») qui passe inaperçu en production et fait
        // échouer l'écriture du classeur bien plus loin, sans dire pourquoi.
        set_error_handler(static function (int $niveau, string $message, string $fichier, int $ligne): bool {
            throw new \ErrorException($message, 0, $niveau, $fichier, $ligne);
        });

        try {
            $entreprise = $this->em->getRepository(Entreprise::class)->find((int) $input->getArgument('idEntreprise'));
            if ($entreprise === null) {
                $io->error('Cabinet introuvable.');

                return Command::FAILURE;
            }

            $invite = $this->invites->findOneBy(['entreprise' => $entreprise, 'proprietaire' => true])
                ?? $this->invites->findOneBy(['entreprise' => $entreprise]);
            if ($invite === null) {
                $io->error('Ce cabinet n\'a aucun invité : impossible de signer l\'état.');

                return Command::FAILURE;
            }

            $debut = microtime(true);
            [$classeur, $manifeste, $total] = $this->producteur->produire(
                $entreprise,
                $invite,
                $entreprise->getUtilisateur(),
            );
            $duree = microtime(true) - $debut;

            $feuille = $classeur->getSheetByName(EtatDuPortefeuille::FEUILLE);
            if ($feuille === null) {
                $io->error('La feuille de données est absente.');

                return Command::FAILURE;
            }

            $colonnes = Coordinate::columnIndexFromString($feuille->getHighestDataColumn());
            // Une ligne d'en-tête, et une ligne de TOTAUX en pied : ni l'une ni l'autre
            // n'est une donnée. Les compter fausserait la comparaison avec le
            // pré-comptage, qui ne connaît que des tranches.
            $lignes = max(0, $feuille->getHighestDataRow() - 2);

            $io->section(sprintf('État de « %s »', $entreprise->getNom() ?? '?'));
            $io->listing([
                sprintf('%d feuille(s) : %s', $classeur->getSheetCount(), implode(', ', $classeur->getSheetNames())),
                sprintf('%d colonne(s), %d ligne(s) de données (+ en-tête et totaux)', $colonnes, $lignes),
                sprintf('%d tranche(s) annoncée(s) par le pré-comptage', $total),
                sprintf('produit en %.1f s', $duree),
                sprintf('empreinte de structure : %s', substr($manifeste->empreinteEntetes, 0, 12)),
            ]);

            // ⚠ LE PRÉ-COMPTAGE ET LE RÉSULTAT DOIVENT CONCORDER. S'ils divergent, la
            // barre de progression ment — elle annonce un reste qui n'arrivera jamais, ou
            // atteint 100 % avant la fin.
            if ($lignes !== $total) {
                $io->warning(sprintf(
                    'Le pré-comptage annonce %d ligne(s), le classeur en porte %d : la progression serait fausse.',
                    $total,
                    $lignes,
                ));

                return Command::FAILURE;
            }

            // ⚠ LE TABLEAU CROISÉ EST LE SEUL MORCEAU QU'AUCUN TEST PHP NE PEUT JUGER.
            // Il est injecté en OOXML brut dans le zip ; une erreur ne se voit pas à
            // l'exécution — c'est EXCEL qui annonce « fichier corrompu » à l'ouverture. On
            // va donc aussi loin qu'on peut sans Excel : parties présentes, XML bien formé,
            // relations résolues, types déclarés.
            if ($classeur->getSheetByName(InjecteurDeTcd::FEUILLE) !== null) {
                $io->section('Synthèse');
                if (!$this->verifierLaSynthese($io, $classeur)) {
                    return Command::FAILURE;
                }
            }

            $io->success('L\'état se produit sans avertissement.');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error(sprintf('%s : %s', $e::class, $e->getMessage()));
            $io->writeln(sprintf('<comment>%s:%d</comment>', $e->getFile(), $e->getLine()));

            return Command::FAILURE;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * LA SYNTHÈSE DIT-ELLE LA VÉRITÉ ?
     *
     * ⚠ C'EST LA VÉRIFICATION QUE LE TABLEAU CROISÉ NE PERMETTAIT PAS. Un TCD ne se
     * calcule que dans un tableur ; ses valeurs n'existaient donc nulle part avant
     * l'ouverture, et le seul juge était l'utilisateur — deux fichiers refusés, puis un
     * plantage d'Excel. Des FORMULES, elles, s'évaluent ici : on relit le classeur produit,
     * on demande le calcul, et l'on compare le total de la synthèse à la somme de la
     * colonne. Si les deux coïncident, la feuille dit vrai.
     */
    private function verifierLaSynthese(SymfonyStyle $io, $classeur): bool
    {
        $chemin = (string) tempnam(sys_get_temp_dir(), 'jsbx_smoke_');
        $this->producteur->ecrireSur($classeur, $chemin);

        try {
            $lecteur = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
            $relu = $lecteur->load($chemin);

            $synthese = $relu->getSheetByName(InjecteurDeTcd::FEUILLE);
            $donnees = $relu->getSheetByName(EtatDuPortefeuille::FEUILLE);
            if ($synthese === null || $donnees === null) {
                $io->error('La feuille de synthèse est absente du classeur relu.');

                return false;
            }

            $controles = [];

            // La dernière ligne de la synthèse porte le total général.
            $ligneTotal = $synthese->getHighestDataRow();
            $controles['la dernière ligne est le total général'] =
                $synthese->getCell('A' . $ligneTotal)->getValue() === 'Total général';

            // ⚠ AUCUN CHIFFRE FIGÉ : chaque cellule de valeur doit être une FORMULE. Un
            // nombre écrit en dur mentirait dès la première correction d'une donnée.
            $formules = 0;
            $figees = 0;
            for ($l = 4; $l <= $ligneTotal; ++$l) {
                $valeur = $synthese->getCell('B' . $l)->getValue();
                if (is_string($valeur) && str_starts_with($valeur, '=')) {
                    ++$formules;
                } elseif ($valeur !== null && $valeur !== '') {
                    ++$figees;
                }
            }
            $controles['toutes les valeurs sont des formules'] = $formules > 0 && $figees === 0;

            // ⚠ LE TOTAL DE LA SYNTHÈSE DOIT ÉGALER LA SOMME DE LA COLONNE. C'est le
            // contrôle qui attrape une plage décalée, un critère mal posé, ou une ligne de
            // totaux comptée deux fois — trois fautes qui donnent toutes un nombre
            // parfaitement plausible.
            $totalSynthese = (float) $synthese->getCell('B' . $ligneTotal)->getCalculatedValue();

            $somme = 0.0;
            $derniereDonnee = $donnees->getHighestDataRow() - 1; // la ligne TOTAUX est hors somme
            $lettre = $this->colonneDe($donnees, (string) $synthese->getCell('B3')->getValue());
            for ($l = 2; $l <= $derniereDonnee; ++$l) {
                $somme += (float) $donnees->getCell($lettre . $l)->getValue();
            }

            $controles['le total égale la somme de la colonne'] = abs($totalSynthese - $somme) < 0.01;

            // ⚠ ET LES GROUPES DOIVENT REFAIRE CE TOTAL. Ce contrôle-ci attrape une faute que
            // le précédent laisse passer : un groupe dont le libellé n'existe PAS dans les
            // données somme zéro, sans rien casser d'autre — le total général, lui, reste
            // juste puisqu'il additionne la colonne entière. C'est arrivé aux tranches sans
            // date d'effet : 0,00 affiché pour 4 952,50 réels.
            $sommeDesGroupes = 0.0;
            for ($l = 4; $l < $ligneTotal; ++$l) {
                if ($synthese->getStyle('A' . $l)->getAlignment()->getIndent() > 0) {
                    continue; // une sous-ligne, déjà comptée dans son groupe
                }
                $sommeDesGroupes += (float) $synthese->getCell('B' . $l)->getCalculatedValue();
            }
            $controles['les groupes refont le total général'] = abs($sommeDesGroupes - $totalSynthese) < 0.01;

            $lignes = [];
            $tout = true;
            foreach ($controles as $quoi => $bon) {
                $lignes[] = sprintf('%s — %s', $bon ? 'OK ' : 'ÉCHEC', $quoi);
                $tout = $tout && $bon;
            }
            $lignes[] = sprintf(
                'total synthèse %.2f / somme colonne %.2f / somme des groupes %.2f',
                $totalSynthese,
                $somme,
                $sommeDesGroupes,
            );
            $lignes[] = sprintf('%d formule(s), %d valeur(s) figée(s)', $formules, $figees);
            $io->listing($lignes);

            if (!$tout) {
                $io->error('La synthèse ne dit pas la même chose que les données.');
            }

            return $tout;
        } finally {
            $vers = $this->destination;
            if ($vers !== null) {
                @copy($chemin, $vers);
                $io->writeln(sprintf('  <comment>classeur écrit : %s</comment>', $vers));
            }
            @unlink($chemin);
        }
    }

    /**
     * La lettre de la colonne de DONNEES que somme un titre « Somme de X ».
     *
     * Le titre de la synthèse est dérivé du libellé de la colonne : on refait le chemin
     * inverse plutôt que de coder une position, qui changerait au premier export restreint.
     */
    private function colonneDe($donnees, string $titre): string
    {
        $libelle = str_starts_with($titre, 'Somme de ') ? substr($titre, 9) : $titre;

        $derniere = Coordinate::columnIndexFromString($donnees->getHighestDataColumn());
        for ($i = 1; $i <= $derniere; ++$i) {
            $lettre = Coordinate::stringFromColumnIndex($i);
            if ((string) $donnees->getCell($lettre . '1')->getValue() === $libelle) {
                return $lettre;
            }
        }

        return 'A';
    }

    /**
     * CE QU'EXCEL EXIGE D'UN RAPPORT DE TABLEAU CROISÉ, au-delà du XML bien formé.
     *
     * Deux règles, et ce sont exactement les deux qui manquaient :
     *
     *  1. L'ORDRE DES ENFANTS est imposé par le schéma — location, pivotFields, rowFields,
     *     rowItems, colFields, colItems, dataFields. Un élément déplacé fait rejeter tout
     *     le rapport, sans que le XML cesse d'être valide au sens de la syntaxe.
     *
     *  2. PLUSIEURS VALEURS OCCUPENT L'AXE DES COLONNES. Dès qu'il y en a plus d'une, cet
     *     axe doit être déclaré (`colFields` avec le pseudo-champ de rang -2) et `colItems`
     *     compte une entrée par valeur. Sept valeurs face à un `colItems` unique, c'est une
     *     définition qui se contredit.
     *
     * @return array<string, bool>
     */
    private function structureDuCroisement(string $pivot): array
    {
        $ordreAttendu = ['location', 'pivotFields', 'rowFields', 'rowItems', 'colFields', 'colItems', 'dataFields'];

        $positions = [];
        foreach ($ordreAttendu as $balise) {
            $trouve = strpos($pivot, '<' . $balise);
            if ($trouve !== false) {
                $positions[$balise] = $trouve;
            }
        }

        $ordonne = $positions === [] ? false : $positions === $this->trierParValeur($positions);

        $nbValeurs = $this->compter($pivot, 'dataFields');
        $nbColItems = $this->compter($pivot, 'colItems');
        $aColFields = str_contains($pivot, '<colFields');
        $aRowItems = str_contains($pivot, '<rowItems');
        $aRowFields = str_contains($pivot, '<rowFields');

        return [
            'ordre des éléments conforme au schéma' => $ordonne,
            'axe des valeurs déclaré (colFields)' => $nbValeurs <= 1 || $aColFields,
            'une entrée de colonne par valeur' => $nbValeurs <= 1 || $nbColItems === $nbValeurs,
            'rowItems présent avec rowFields' => !$aRowFields || $aRowItems,
        ];
    }

    /** @param array<string, int> $positions */
    private function trierParValeur(array $positions): array
    {
        asort($positions);

        return $positions;
    }

    private function compter(string $xml, string $balise): int
    {
        return preg_match('/<' . $balise . ' count="(\\d+)"/', $xml, $trouve) === 1 ? (int) $trouve[1] : 0;
    }
}
