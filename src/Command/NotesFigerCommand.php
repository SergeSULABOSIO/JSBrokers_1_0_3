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

    private static function f(float $montant): string
    {
        return number_format($montant, 2, ',', ' ');
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

        // DEUX CHIFFRES, ET UN SEUL DOIT ÊTRE NUL.
        //  • CONTRÔLE : le montant FIGÉ face au montant RECALCULÉ juste avant le figeage. Le
        //    figeage photographie le calcul : l'écart doit valoir 0,00 — sinon le figé ne dit
        //    pas ce que la note disait, et rien ne doit être confirmé (⚠).
        //  • RESTE À ENCAISSER : le montant face aux paiements reçus. Une INFORMATION, pas une
        //    anomalie : une note partiellement réglée se fige telle quelle, et son reste se
        //    suit au recouvrement. (Un trop-perçu, payé au-delà du montant, est signalé aussi.)
        // À blanc, le figeage se fait EN MÉMOIRE et n'est pas écrit : le contrôle se lit avant
        // de confirmer.
        $lignes = [];
        $anomalies = 0;
        $tropPercus = 0;
        $figees = 0;
        $echecs = [];
        foreach ($ids as $id) {
            try {
                /** @var Note $note */
                $note = $this->em->find(Note::class, (int) $id);
                $reprise = $note->isValidated() !== true;
                $recalcule = round($this->calcul->getNoteMontantTotal($note), 2);
                $paye = round($this->calcul->getNoteMontantPaye($note), 2);

                if ($confirmer && $reprise) {
                    $note->setOrigine(Note::ORIGINE_REPRISE);
                    // Hors série : une référence PROVISOIRE devient « REPRISE-… ». Un numéro
                    // déjà émis, lui, se conserve — le renommer ferait un trou.
                    if (str_starts_with((string) $note->getReference(), NumerotationDesNotes::PREFIXE_BROUILLON)) {
                        $note->setReference($this->numerotation->provisoire((int) $note->getEntreprise()->getId(), 'REPRISE-'));
                    }
                }
                $fige = round($this->figeage->figer($note)['ttc'], 2);
                $ecart = round($fige - $recalcule, 2);
                $anomalies += abs($ecart) >= 0.01 ? 1 : 0;
                $reste = round($fige - $paye, 2);
                $tropPercus += $reste <= -0.01 ? 1 : 0;

                $lignes[] = [
                    $note->getEntreprise()?->getId(), $note->getId(), $note->getReference(),
                    $reprise ? 'reprise' : 'validée',
                    self::f($recalcule), self::f($fige),
                    self::f($ecart) . (abs($ecart) >= 0.01 ? ' ⚠' : ' ✓'),
                    self::f($paye),
                    $reste <= -0.01 ? self::f($reste) . ' (trop-perçu)' : self::f($reste),
                ];

                if ($confirmer) {
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
            // Le graphe d'une note ne sert plus — et, à blanc, son figeage en mémoire est jeté.
            $this->em->clear();
            $this->calcul->reset();
        }

        $io->table(
            ['Cabinet', 'Note', 'Référence', 'Nature', 'Recalculé (TTC)', 'Figé (TTC)', 'Contrôle figé − recalculé', 'Payé', 'Reste à encaisser (info)'],
            $lignes,
        );
        $io->text([
            'Contrôle figé − recalculé : doit valoir 0,00 (✓). Un écart (⚠) signifie que le figé ne reproduit pas la note : ne confirmez pas.',
            'Reste à encaisser : information — une note partiellement réglée se fige telle quelle ; son reste se suit au recouvrement.',
        ]);
        if ($anomalies > 0) {
            $io->error(sprintf('%d note(s) : le montant figé ne reproduit pas le montant recalculé (⚠). Ne confirmez pas — signalez-le.', $anomalies));
        }
        if ($tropPercus > 0) {
            $io->note(sprintf('%d note(s) ont reçu plus que leur montant (trop-perçu) : à vérifier au recouvrement.', $tropPercus));
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
