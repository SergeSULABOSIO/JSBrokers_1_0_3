<?php

namespace App\Command;

use App\Entity\Bordereau;
use App\Services\Bordereau\BordereauLigneNormaliseur;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Rattrapage des bordereaux analysés AVANT la persistance des montants par police.
 *
 * Leurs lignes ne portent que les clés de repérage (type, row_index, reference_police,
 * avenant_id) : la commission encaissée par tranche y est répartie par une règle d'imputation
 * au lieu de suivre la déclaration de l'assureur. Le fichier Excel étant toujours attaché au
 * bordereau, et selectedSheetName / mappedColumns persistés, les montants sont reconstructibles.
 *
 * GARDE-FOU, non négociable : une ligne n'est enrichie que si la ligne Excel à son row_index
 * porte TOUJOURS la même référence de police. C'est le contrôle d'intégrité déjà appliqué à
 * l'affichage — sans lui, un fichier remplacé depuis l'analyse écrirait des montants
 * appartenant à d'autres polices. Les lignes écartées sont listées, jamais corrigées en silence.
 *
 * Idempotente : une ligne déjà enrichie n'est jamais réécrite.
 */
#[AsCommand(
    name: 'app:bordereau:enrichir-lignes',
    description: 'Complète les lignes d\'analyse des bordereaux avec les montants déclarés par police.',
)]
final class BordereauEnrichirLignesCommand extends Command
{
    private const EXTENSIONS = ['xlsx', 'xls', 'ods'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ParameterBagInterface $params,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Écrit réellement. Sans cette option, la commande se contente de rapporter (dry-run).',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ecrire = (bool) $input->getOption('force');

        if (!$ecrire) {
            $io->note('Dry-run : aucune écriture. Relancer avec --force pour appliquer.');
        }

        $bordereaux = $this->em->getRepository(Bordereau::class)->findBy(['type' => Bordereau::TYPE_BOREDERAU_PRODUCTION]);
        $totaux = ['enrichies' => 0, 'deja' => 0, 'ecartees' => 0, 'bordereaux' => 0];

        foreach ($bordereaux as $bordereau) {
            $lignes = $bordereau->getAnalysisResults() ?? [];
            if ($lignes === []) {
                continue;
            }

            $aFaire = array_filter($lignes, static fn (array $l) => !array_key_exists('commission_ht_payable_now', $l));
            if ($aFaire === []) {
                $totaux['deja'] += count($lignes);
                continue;
            }

            $rows = $this->lireFeuille($bordereau, $io);
            if ($rows === null) {
                $totaux['ecartees'] += count($aFaire);
                continue;
            }

            $mappedColumns = $bordereau->getMappedColumns() ?: [];
            $refColonne = $mappedColumns['reference_police'] ?? null;
            $refColonne = is_array($refColonne) ? ($refColonne[0] ?? null) : $refColonne;
            if ($refColonne === null) {
                $io->warning(sprintf('Bordereau #%d : colonne « N° de police » non mappée, ignoré.', $bordereau->getId()));
                $totaux['ecartees'] += count($aFaire);
                continue;
            }

            $enrichies = 0;
            $ecartees = [];
            foreach ($lignes as $i => $ligne) {
                if (array_key_exists('commission_ht_payable_now', $ligne)) {
                    ++$totaux['deja'];
                    continue;
                }

                $rowIndex = $ligne['row_index'] ?? null;
                $row = $rowIndex !== null ? ($rows[$rowIndex] ?? null) : null;

                // CONTRÔLE D'INTÉGRITÉ : même row_index ET même police, sinon le fichier a
                // changé depuis l'analyse et les montants ne sont plus les siens.
                $policeExcel = $row !== null
                    ? BordereauLigneNormaliseur::normaliserValeur($row[$refColonne] ?? null, 'reference_police')
                    : null;
                if ($row === null || (string) $policeExcel !== (string) ($ligne['reference_police'] ?? '')) {
                    $ecartees[] = sprintf('ligne %s (police %s)', $rowIndex ?? '?', $ligne['reference_police'] ?? '?');
                    continue;
                }

                $rawLineData = BordereauLigneNormaliseur::normaliserLigne($row, $mappedColumns);
                $lignes[$i] = $ligne + [
                    'commission_ht_payable_now' => (float) ($rawLineData['commission_ht_payable_now'] ?? 0),
                    'taxe_commission_payable_now' => (float) ($rawLineData['taxe_commission_payable_now'] ?? 0),
                    'prime_ttc' => (float) ($rawLineData['prime_ttc'] ?? 0),
                ];
                ++$enrichies;
            }

            $totaux['enrichies'] += $enrichies;
            $totaux['ecartees'] += count($ecartees);
            ++$totaux['bordereaux'];

            $reclame = array_sum(array_map(
                static fn (array $l) => (float) ($l['commission_ht_payable_now'] ?? 0) + (float) ($l['taxe_commission_payable_now'] ?? 0),
                $lignes,
            ));
            $io->writeln(sprintf(
                'Bordereau #%d « %s » : %d ligne(s) enrichie(s), %d écartée(s). Somme reconstruite %s / montantPayableNow %s',
                $bordereau->getId(),
                $bordereau->getNom(),
                $enrichies,
                count($ecartees),
                number_format($reclame, 2, ',', ' '),
                number_format((float) $bordereau->getMontantPayableNow(), 2, ',', ' '),
            ));
            if ($ecartees !== []) {
                $io->listing($ecartees);
            }

            if ($ecrire && $enrichies > 0) {
                $bordereau->setAnalysisResults(array_values($lignes));
                $this->em->persist($bordereau);
            }
        }

        if ($ecrire) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%d bordereau(x) traité(s) : %d ligne(s) enrichie(s), %d déjà à jour, %d écartée(s)%s',
            $totaux['bordereaux'],
            $totaux['enrichies'],
            $totaux['deja'],
            $totaux['ecartees'],
            $ecrire ? '.' : ' — RIEN N\'A ÉTÉ ÉCRIT (dry-run).',
        ));

        return Command::SUCCESS;
    }

    /**
     * Lignes de la feuille retenue à l'analyse, indexées comme au moment de celle-ci
     * (en-tête retiré, indices repartant de 0 — même convention que row_index).
     *
     * @return array<int, array<string, mixed>>|null null si le fichier est introuvable/illisible
     */
    private function lireFeuille(Bordereau $bordereau, SymfonyStyle $io): ?array
    {
        $document = null;
        foreach ($bordereau->getDocuments() as $doc) {
            $nom = $doc->getNomFichierStocke();
            if ($nom && in_array(strtolower(pathinfo($nom, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
                $document = $doc;
                break;
            }
        }
        if ($document === null) {
            $io->warning(sprintf('Bordereau #%d : aucun fichier Excel attaché, ignoré.', $bordereau->getId()));

            return null;
        }

        $chemin = $this->params->get('kernel.project_dir') . '/public/uploads/documents/' . $document->getNomFichierStocke();
        if (!is_file($chemin)) {
            $io->warning(sprintf('Bordereau #%d : fichier introuvable (%s), ignoré.', $bordereau->getId(), $chemin));

            return null;
        }

        try {
            $feuille = IOFactory::load($chemin)->getSheetByName((string) $bordereau->getSelectedSheetName());
            if ($feuille === null) {
                $io->warning(sprintf('Bordereau #%d : feuille « %s » absente, ignoré.', $bordereau->getId(), $bordereau->getSelectedSheetName()));

                return null;
            }

            // MÊME LECTURE QUE L'ANALYSE, au paramètre près (cf. _loadSheetData) :
            // indexation par LETTRE de colonne — c'est ce que référence mappedColumns —,
            // et formatData À FALSE pour obtenir les valeurs brutes (les dates restent des
            // numéros de série Excel, que le normaliseur sait convertir). En-tête retiré,
            // lignes vides écartées, puis réindexation : row_index suit cette numérotation.
            $lignes = $feuille->toArray(null, true, false, true);
            array_shift($lignes);
            $lignes = array_filter(
                $lignes,
                static fn ($ligne) => !empty(array_filter($ligne, static fn ($cell) => $cell !== null && $cell !== '')),
            );

            return array_values($lignes);
        } catch (\Throwable $e) {
            $io->warning(sprintf('Bordereau #%d : lecture impossible (%s), ignoré.', $bordereau->getId(), $e->getMessage()));

            return null;
        }
    }
}
