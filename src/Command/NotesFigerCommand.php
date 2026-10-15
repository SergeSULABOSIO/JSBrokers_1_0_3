<?php

namespace App\Command;

use App\Entity\Note;
use App\Service\Note\FigeageDeNote;
use App\Service\Note\NumerotationDesNotes;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * FIGER LES NOTES ÉMISES AVANT LA RÈGLE (OHADA, décision de Serge du 2026-10-15).
 *
 * Depuis la règle, une note se fige à sa validation. Celles qui l'ont été AVANT — et les
 * notes de reprise, commissions déjà encaissées dans l'ancien système — n'ont encore
 * aucun montant figé : elles se recalculent toujours. Cette commande les fige à leur
 * montant ACTUEL (aucune trace du montant d'origine n'existe), et rien d'autre.
 *
 * ── À BLANC PAR DÉFAUT ──────────────────────────────────────────────────────────────
 * Sans `--confirmer`, rien n'est écrit : la commande liste, note par note, le montant
 * recalculé aujourd'hui, ce qui a été payé, et l'écart — la mesure qui manquait au SQL,
 * le calcul étant en PHP. On regarde, puis on fige.
 *
 * ⚠ ET SANS UTILISATEUR CONNECTÉ, le calcul des taxes prend le cabinet de l'affaire
 * (ServiceTaxes::getMontantTaxeSurCommission) : le TTC n'y vaut pas le HT.
 *
 * Rejouée, elle ne trouve plus rien à figer.
 */
#[AsCommand(
    name: 'app:notes:figer',
    description: 'Fige les montants des notes validées (et des notes de reprise) qui ne le sont pas encore. À blanc sans --confirmer.',
)]
final class NotesFigerCommand extends Command
{
    /**
     * LE MARQUEUR EXACT d'un règlement de reprise (ReconstitueurDeTranche) : sa référence
     * ET sa description, comparées à la casse près. Une référence « Reprise » saisie à la
     * main sur un brouillon ne doit pas en faire une note de reprise.
     */
    private const REFERENCE_REPRISE = 'REPRISE';
    private const DESCRIPTION_REPRISE = 'Situation reprise depuis un classeur de reprise.';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FigeageDeNote $figeage,
        private readonly NumerotationDesNotes $numerotation,
        private readonly IndicatorCalculationHelper $calcul,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('confirmer', null, InputOption::VALUE_NONE, 'Fige réellement. Sans cette option, la commande se contente de lister.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $confirmer = (bool) $input->getOption('confirmer');

        $ids = $this->em->getConnection()->fetchFirstColumn(
            'SELECT n.id FROM note n
             WHERE n.figee_le IS NULL
               AND (n.validated = 1 OR n.origine = ?
                    OR EXISTS (SELECT 1 FROM paiement p WHERE p.note_id = n.id
                               AND BINARY p.reference = ? AND BINARY p.description = ?))
             ORDER BY n.entreprise_id, n.id',
            [Note::ORIGINE_REPRISE, self::REFERENCE_REPRISE, self::DESCRIPTION_REPRISE],
        );
        if ($ids === []) {
            $io->success('Aucune note à figer.');

            return Command::SUCCESS;
        }

        $lignes = [];
        $alertes = 0;
        $figees = 0;
        $echecs = [];
        foreach ($ids as $id) {
            try {
                /** @var Note $note */
                $note = $this->em->find(Note::class, (int) $id);
                $reprise = $note->isValidated() !== true;
                $montant = round($this->calcul->getNoteMontantTotal($note), 2);
                $paye = round($this->calcul->getNoteMontantPaye($note), 2);
                // ⚠ PAYÉ AU-DELÀ DU MONTANT : la note se recalcule aujourd'hui sous ce qu'elle
                // a encaissé (une règle corrigée depuis, une prime baissée…). La figer ainsi
                // graverait un trop-perçu : à regarder AVANT de confirmer.
                $alerte = $paye > $montant + 0.005;
                $alertes += $alerte ? 1 : 0;
                $lignes[] = [
                    $note->getEntreprise()?->getId(), $note->getId(), $note->getReference(),
                    $reprise ? 'reprise' : 'validée',
                    number_format($montant, 2, ',', ' '), number_format($paye, 2, ',', ' '),
                    number_format(round($montant - $paye, 2), 2, ',', ' ') . ($alerte ? ' ⚠ payé > montant' : ''),
                ];

                if ($confirmer) {
                    if ($reprise) {
                        $note->setOrigine(Note::ORIGINE_REPRISE);
                        // Hors série : une référence PROVISOIRE devient « REPRISE-… ». Un
                        // numéro déjà émis, lui, se conserve — le renommer ferait un trou.
                        if (str_starts_with((string) $note->getReference(), NumerotationDesNotes::PREFIXE_BROUILLON)) {
                            $note->setReference($this->numerotation->provisoire((int) $note->getEntreprise()->getId(), 'REPRISE-'));
                        }
                    }
                    $this->figeage->figer($note);
                    $this->em->flush();
                    ++$figees;
                }
            } catch (\Throwable $e) {
                // Une note fautive ne bloque pas le lot : elle est signalée et reste à figer.
                $echecs[] = sprintf('Note #%d : %s', $id, $e->getMessage());
                if (!$this->em->isOpen()) {
                    break; // une écriture refusée ferme le gestionnaire : on s'arrête là.
                }
            }
            // Le graphe d'une note ne sert plus : la mémoire ne grossit pas avec le lot.
            $this->em->clear();
        }

        $io->table(['Cabinet', 'Note', 'Référence', 'Nature', 'Montant (TTC) figé', 'Payé', 'Écart (reste dû)'], $lignes);
        if ($alertes > 0) {
            $io->warning(sprintf('%d note(s) ont encaissé plus que leur montant recalculé (⚠) : vérifiez-les avant de confirmer.', $alertes));
        }
        foreach ($echecs as $echec) {
            $io->error($echec);
        }
        if ($confirmer) {
            $io->success(sprintf('%d note(s) figée(s) à leur montant actuel.', $figees));
        } else {
            $io->note(sprintf('%d note(s) à figer. Rien n’a été écrit : relancez avec --confirmer.', count($lignes)));
        }

        return Command::SUCCESS;
    }
}
