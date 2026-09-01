<?php

namespace App\Command;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\MouvementConge;
use App\Entity\TypeAbsence;
use App\Repository\MouvementCongeRepository;
use App\Repository\TypeAbsenceRepository;
use App\Service\Conge\CalculateurSolde;
use App\Service\Conge\CongeParametres;
use App\Service\Conge\ParametresDuCabinet;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * L'OUVERTURE D'UN EXERCICE — report du reliquat, puis dotation de l'année.
 *
 * ── L'ORDRE COMPTE, ET IL N'EST PAS ARBITRAIRE ──────────────────────────────────────
 * On lit d'abord le reliquat de l'exercice PRÉCÉDENT, on l'écrit en REPORT sur le nouvel
 * exercice, puis seulement on crédite la DOTATION. Inverser reviendrait à reporter un
 * solde déjà gonflé de la dotation de l'année qui s'ouvre.
 *
 * ── LE REPORT EST SANS LIMITE DE DURÉE ──────────────────────────────────────────────
 * Il n'expire pas, et il est fondu dans l'acquis du nouvel exercice. L'écran en montre
 * toutefois le détail — « dont report N-1 » — en sommant les mouvements de cette nature,
 * et `app:conges:rappels` alerte au-delà du seuil réglé par le cabinet. Un solde qui
 * s'accumule indéfiniment est une dette qui grossit sans que personne ne la regarde.
 *
 * ── IDEMPOTENTE, ET C'EST VITAL ─────────────────────────────────────────────────────
 * Une ouverture rejouée doublerait le droit de chacun, en silence, et personne ne s'en
 * apercevrait avant que quelqu'un prenne des jours qu'il n'a pas. La garde est le triplet
 * agent/exercice/nature : un agent qui a déjà son REPORT n'en reçoit pas un second, et
 * de même pour sa DOTATION — les deux sont vérifiées SÉPARÉMENT, pour qu'une exécution
 * interrompue à mi-chemin puisse être reprise.
 *
 * ── DRY-RUN PAR DÉFAUT ──────────────────────────────────────────────────────────────
 * Sans `--force`, rien n'est écrit. Sur des droits à congé, on regarde avant.
 */
#[AsCommand(
    name: 'app:conges:ouvrir-exercice',
    description: "Ouvre un exercice de congés : reporte le reliquat de l'année précédente et crédite la dotation annuelle.",
)]
final class CongesOuvrirExerciceCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MouvementCongeRepository $mouvementRepository,
        private readonly TypeAbsenceRepository $typeAbsenceRepository,
        private readonly CalculateurSolde $calculateurSolde,
        private readonly ParametresDuCabinet $parametres,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'annee',
                InputArgument::OPTIONAL,
                "Exercice à ouvrir (année civile). Par défaut, l'année en cours.",
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                "Écrit réellement. Sans cette option, la commande rapporte ce qu'elle ferait.",
            )
            ->addOption(
                'entreprise',
                null,
                InputOption::VALUE_REQUIRED,
                'Ne traiter que ce cabinet (identifiant). Par défaut, tous.',
            )
            ->addOption(
                'sans-report',
                null,
                InputOption::VALUE_NONE,
                "N'écrire que la dotation, sans reporter le reliquat de l'exercice précédent.",
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $sansReport = (bool) $input->getOption('sans-report');
        $idEntreprise = $input->getOption('entreprise');

        $annee = (int) ($input->getArgument('annee') ?? 0) ?: (int) (new \DateTimeImmutable('now'))->format('Y');
        if ($annee < 2000 || $annee > 2100) {
            $io->error(sprintf("L'exercice « %d » n'est pas une année plausible.", $annee));

            return Command::FAILURE;
        }

        $io->title(sprintf("Ouverture de l'exercice %d", $annee));
        if ($sansReport) {
            $io->text("Report désactivé : seule la dotation sera créditée.");
        }
        if (!$force) {
            $io->warning("Lecture seule : rien ne sera écrit. Ajoutez --force pour appliquer.");
        }

        $criteres = $idEntreprise !== null ? ['id' => (int) $idEntreprise] : [];
        $entreprises = $this->em->getRepository(Entreprise::class)->findBy($criteres);

        if ($entreprises === []) {
            $io->warning('Aucun cabinet à traiter.');

            return Command::SUCCESS;
        }

        $totaux = ['reports' => 0, 'dotations' => 0, 'joursReportes' => 0.0];
        $lignes = [];

        foreach ($entreprises as $entreprise) {
            $bilan = $this->ouvrirPourUnCabinet($entreprise, $annee, $force, $sansReport, $io);

            $totaux['reports'] += $bilan['reports'];
            $totaux['dotations'] += $bilan['dotations'];
            $totaux['joursReportes'] += $bilan['joursReportes'];

            if ($bilan['reports'] + $bilan['dotations'] > 0) {
                $lignes[] = [
                    (string) $entreprise->getId(),
                    (string) $entreprise->getNom(),
                    (string) $bilan['reports'],
                    $this->formater($bilan['joursReportes']),
                    (string) $bilan['dotations'],
                ];
            }
        }

        if ($force) {
            $this->em->flush();
        }

        if ($lignes !== []) {
            $io->table(['Cabinet', 'Nom', 'Reports', 'Jours reportés', 'Dotations'], $lignes);
        }

        $io->success(sprintf(
            '%s : %d report(s) pour %s jour(s), %d dotation(s) sur %d cabinet(s).',
            $force ? 'Appliqué' : 'À appliquer',
            $totaux['reports'],
            $this->formater($totaux['joursReportes']),
            $totaux['dotations'],
            count($entreprises),
        ));

        if (!$force && ($totaux['reports'] + $totaux['dotations']) > 0) {
            $io->note('Relancez avec --force pour écrire.');
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{reports: int, dotations: int, joursReportes: float}
     */
    private function ouvrirPourUnCabinet(
        Entreprise $entreprise,
        int $annee,
        bool $force,
        bool $sansReport,
        SymfonyStyle $io,
    ): array {
        $congeAnnuel = $this->typeAbsenceRepository->parCode($entreprise, TypeAbsence::CODE_CONGE_ANNUEL);
        if ($congeAnnuel === null) {
            // Sans type de congé annuel, la dotation ne créditerait rien de lisible. Le
            // cabinet n'a jamais été provisionné : c'est `app:conges:provisionner` qu'il
            // lui faut, pas une ouverture d'exercice.
            $io->warning(sprintf(
                'Cabinet « %s » ignoré : aucun type « Congé annuel ». Lancez d\'abord app:conges:provisionner.',
                (string) $entreprise->getNom(),
            ));

            return ['reports' => 0, 'dotations' => 0, 'joursReportes' => 0.0];
        }

        $proprietaire = $this->proprietaireDe($entreprise);
        $dotationAnnuelle = $this->parametres->dotationAnnuelle($entreprise);

        $bilan = ['reports' => 0, 'dotations' => 0, 'joursReportes' => 0.0];

        foreach ($entreprise->getInvites() as $agent) {
            // ── 1. LE REPORT, D'ABORD ────────────────────────────────────────────────
            // Lu sur l'exercice PRÉCÉDENT, avant que la dotation de l'année qui s'ouvre
            // ne vienne le gonfler.
            if (!$sansReport && !$this->aDeja($agent, $annee, MouvementConge::NATURE_REPORT)) {
                $reliquat = $this->calculateurSolde->pour($agent, $annee - 1)->disponible();

                if (abs($reliquat) >= 0.001) {
                    $bilan['reports']++;
                    $bilan['joursReportes'] += $reliquat;

                    if ($force) {
                        $this->ecrire(
                            $agent,
                            $annee,
                            $congeAnnuel,
                            MouvementConge::NATURE_REPORT,
                            $reliquat,
                            sprintf('Report du reliquat de l\'exercice %d.', $annee - 1),
                            $proprietaire,
                            $entreprise,
                        );
                    }
                }
            }

            // ── 2. LA DOTATION ──────────────────────────────────────────────────────
            if ($this->aDeja($agent, $annee, MouvementConge::NATURE_DOTATION)) {
                continue;
            }

            $jours = CongeParametres::dotationAuProrata(
                $dotationAnnuelle,
                $agent->getCreatedAt() ?? new \DateTimeImmutable('now'),
                $annee,
            );

            if ($jours <= 0.0) {
                continue; // Pas encore arrivé sur cet exercice.
            }

            $bilan['dotations']++;

            if ($force) {
                $this->ecrire(
                    $agent,
                    $annee,
                    $congeAnnuel,
                    MouvementConge::NATURE_DOTATION,
                    $jours,
                    sprintf('Dotation de l\'exercice %d, au prorata des mois de présence.', $annee),
                    $proprietaire,
                    $entreprise,
                );
            }
        }

        return $bilan;
    }

    /**
     * Cet agent a-t-il déjà un mouvement de cette nature sur cet exercice ?
     *
     * Report et dotation sont vérifiés SÉPARÉMENT : une exécution interrompue entre les
     * deux doit pouvoir être reprise sans redoubler ce qui est déjà écrit.
     */
    private function aDeja(Invite $agent, int $exercice, string $nature): bool
    {
        return $agent->getId() !== null
            && $this->mouvementRepository->existePour($agent, $exercice, $nature);
    }

    private function ecrire(
        Invite $agent,
        int $exercice,
        TypeAbsence $type,
        string $nature,
        float $quantite,
        string $commentaire,
        ?Invite $auteur,
        Entreprise $entreprise,
    ): void {
        $mouvement = new MouvementConge();
        $mouvement->setAgent($agent);
        $mouvement->setExercice($exercice);
        $mouvement->setTypeAbsence($type);
        $mouvement->setNature($nature);
        $mouvement->setQuantite(number_format($quantite, 1, '.', ''));
        $mouvement->setAuteur($auteur);
        $mouvement->setCommentaire($commentaire);
        $mouvement->setEntreprise($entreprise);
        $mouvement->setInvite($auteur);

        $this->em->persist($mouvement);
    }

    private function proprietaireDe(Entreprise $entreprise): ?Invite
    {
        foreach ($entreprise->getInvites() as $invite) {
            if ($invite->isProprietaire() === true) {
                return $invite;
            }
        }

        return $entreprise->getInvites()->first() ?: null;
    }

    private function formater(float $jours): string
    {
        return rtrim(rtrim(number_format($jours, 1, ',', ' '), '0'), ',');
    }
}
