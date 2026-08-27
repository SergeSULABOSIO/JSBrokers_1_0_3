<?php

namespace App\Command;

use App\Entity\Entreprise;
use App\Entity\Note;
use App\Entity\ReversementRetroAgent;
use App\Repository\EntrepriseRepository;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * REPREND LES RÉTROS PARTENAIRES DÉJÀ PAYÉES PAR NOTE DE CRÉDIT, en reversements.
 *
 *   php bin/console app:reversements:reprise-notes --dry-run
 *   php bin/console app:reversements:reprise-notes
 *
 * ── POURQUOI CETTE COMMANDE EXISTE ──────────────────────────────────────────────────
 *
 * Le « payé » d'un partenaire se déduisait du prorata des règlements d'une note de crédit.
 * Il se lit désormais sur les REVERSEMENTS : le partenaire facture le cabinet par sa note de
 * débit, le cabinet lui reverse et garde la pièce.
 *
 * Sans reprise, tout ce qui a DÉJÀ été payé par l'ancien circuit repasserait en « non
 * payé » : le cabinet croirait devoir ce qu'il a versé, et un intermédiaire pourrait
 * réclamer deux fois. C'est le genre de perte qu'aucune erreur ne signale.
 *
 * ── POURQUOI UNE COMMANDE ET NON UNE MIGRATION ──────────────────────────────────────
 *
 * Le montant repris est la part RÉGLÉE d'un article : `proportion × montant de l'article`,
 * où la proportion vient des paiements de la note. Ces trois grandeurs sont calculées par le
 * moteur (`getNoteMontantPayable`, `getNoteMontantPaye`, `getArticleMontant`) et hors de
 * portée du SQL. Une migration aurait dû les réinventer — c'est-à-dire inventer une formule.
 *
 * ── CE QUI EST GARANTI ──────────────────────────────────────────────────────────────
 *
 *  — IDEMPOTENCE : une ligne déjà reprise est reconnue à sa référence et à son échéance,
 *    donc une seconde exécution ne double rien. C'est ce qui permet de la relancer après
 *    correction d'un cas particulier ;
 *  — LA MAILLE : chaque article porte sa tranche, la reprise conserve donc l'échéance
 *    exacte — aucune répartition n'est inventée ;
 *  — LE LOT : les articles d'une même note partagent son `lotReference`. Une note réglant
 *    trois échéances redevient UN virement de trois lignes, ce que le mécanisme de lot sait
 *    déjà lire, pièce justificative comprise ;
 *  — `--dry-run` : rien n'est écrit, tout est compté. Sur des montants, on regarde avant.
 */
#[AsCommand(
    name: 'app:reversements:reprise-notes',
    description: 'Reprend les rétrocommissions partenaires déjà réglées par note de crédit sous forme de reversements.',
)]
class ReversementsRepriseNotesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EntrepriseRepository $entreprises,
        private readonly IndicatorCalculationHelper $helper,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compte sans rien écrire.')
            ->addOption('entreprise', null, InputOption::VALUE_REQUIRED, 'Ne traiter qu’une entreprise (id).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simulation = (bool) $input->getOption('dry-run');
        $idEntreprise = $input->getOption('entreprise');

        $entreprises = $idEntreprise !== null
            ? array_filter([$this->entreprises->find((int) $idEntreprise)])
            : $this->entreprises->findAll();

        if ($entreprises === []) {
            $io->warning('Aucune entreprise à traiter.');

            return Command::SUCCESS;
        }

        $io->title($simulation ? 'Reprise des rétros partenaires — SIMULATION' : 'Reprise des rétros partenaires');

        $totalLignes = 0;
        $totalMontant = 0.0;
        $totalDejaReprises = 0;

        foreach ($entreprises as $entreprise) {
            [$lignes, $montant, $deja] = $this->traiter($entreprise, $simulation, $io);
            $totalLignes += $lignes;
            $totalMontant += $montant;
            $totalDejaReprises += $deja;
        }

        if (!$simulation) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%d reversement(s) %s pour %s au total. %d ligne(s) déjà reprise(s), laissée(s) intacte(s).',
            $totalLignes,
            $simulation ? 'à créer' : 'créé(s)',
            number_format($totalMontant, 2, ',', ' '),
            $totalDejaReprises,
        ));

        if ($simulation && $totalLignes > 0) {
            $io->note('Relancez sans --dry-run pour écrire, puis contrôlez les soldes en base.');
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{0: int, 1: float, 2: int} lignes créées, montant total, lignes déjà reprises
     */
    private function traiter(Entreprise $entreprise, bool $simulation, SymfonyStyle $io): array
    {
        $notes = $this->em->getRepository(Note::class)->findBy([
            'entreprise' => $entreprise,
            'addressedTo' => Note::TO_PARTENAIRE,
            'type' => Note::TYPE_NOTE_DE_CREDIT,
        ]);

        $lignes = 0;
        $montantTotal = 0.0;
        $deja = 0;

        foreach ($notes as $note) {
            $partenaire = $note->getPartenaire();
            if ($partenaire === null || $note->getArticles()->isEmpty()) {
                continue;
            }

            $payable = $this->helper->getNoteMontantPayable($note);
            $paye = $this->helper->getNoteMontantPaye($note);
            if ($payable <= 0.0 || $paye <= 0.0) {
                continue; // rien de réglé : il n'y a rien à reprendre
            }
            $proportion = min(1.0, $paye / $payable);

            $reference = (string) ($note->getReference() ?? ('NC-' . $note->getId()));
            $quand = $this->dernierReglement($note);

            foreach ($note->getArticles() as $article) {
                $tranche = $article->getTranche();
                if ($tranche === null) {
                    continue; // sans échéance, la maille manquerait : on ne devine pas
                }

                $montant = round($proportion * $this->helper->getArticleMontant($article), 2);
                if ($montant <= 0.0) {
                    continue;
                }

                if ($this->dejaRepris($reference, (int) $tranche->getId())) {
                    ++$deja;
                    continue;
                }

                ++$lignes;
                $montantTotal += $montant;

                if ($simulation) {
                    $io->text(sprintf(
                        '  · %s — %s, échéance « %s » : %s',
                        $reference,
                        $partenaire->getNom(),
                        $tranche->getNom(),
                        number_format($montant, 2, ',', ' '),
                    ));
                    continue;
                }

                $reversement = (new ReversementRetroAgent())
                    ->setPartenaire($partenaire)
                    ->setTranche($tranche)
                    ->setAvenant($tranche->getCotation()?->getAvenants()->first() ?: null)
                    ->setMontant($montant)
                    ->setPaidAt($quand)
                    ->setReference($reference)
                    // Une note réglant plusieurs échéances redevient UN virement de
                    // plusieurs lignes : c'est exactement ce que le lot modélise.
                    ->setLotReference($reference)
                    ->setDescription('Reprise de la note de crédit ' . $reference);
                $reversement->setEntreprise($entreprise)->setInvite($note->getInvite());
                $this->em->persist($reversement);
            }
        }

        if ($lignes > 0 || $deja > 0) {
            $io->section(sprintf('%s : %d à reprendre, %d déjà reprises', $entreprise->getNom(), $lignes, $deja));
        }

        return [$lignes, $montantTotal, $deja];
    }

    /**
     * Une reprise est reconnue à sa RÉFÉRENCE et à son ÉCHÉANCE : c'est ce couple qui rend
     * la commande relançable sans rien doubler.
     */
    private function dejaRepris(string $reference, int $trancheId): bool
    {
        return $this->em->getRepository(ReversementRetroAgent::class)->count([
            'reference' => $reference,
            'tranche' => $trancheId,
        ]) > 0;
    }

    /** La date du dernier règlement de la note : celle à laquelle l'argent est parti. */
    private function dernierReglement(Note $note): \DateTimeImmutable
    {
        $dates = [];
        foreach ($note->getPaiements() as $paiement) {
            $quand = $paiement->getPaidAt();
            if ($quand instanceof \DateTimeInterface) {
                $dates[] = $quand->getTimestamp();
            }
        }

        return $dates === []
            ? new \DateTimeImmutable('now')
            : (new \DateTimeImmutable())->setTimestamp(max($dates));
    }
}
