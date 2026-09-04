<?php

namespace App\Command;

use App\Echange\Canevas\CanevasDEchange;
use App\Echange\Service\ExportateurJsbx;
use App\Echange\Service\Progression;
use App\Entity\Entreprise;
use App\Service\Workspace\WorkspaceAccessResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * VÉRIFICATION À CHAUD de l'échange de données, sur les VRAIES données d'un cabinet.
 *
 * Pourquoi cette commande existe : les tests travaillent sur des cabinets fabriqués,
 * dont les entités sont propres et les champs simples. Le premier export réel a échoué
 * en 500 sur un « Array to string conversion » qu'aucun test n'avait rencontré — parce
 * qu'aucun cabinet de test ne portait de champ à choix multiples rempli.
 *
 * Elle promeut donc TOUT avertissement PHP en exception : une conversion douteuse ne
 * doit pas se glisser dans un fichier, elle doit interrompre et se nommer. Et elle
 * parcourt les ressources UNE PAR UNE, pour dire LAQUELLE échoue — un export global qui
 * tombe ne désigne personne.
 */
#[AsCommand(
    name: 'app:echange:smoke',
    description: 'Exporte les données d\'un cabinet, ressource par ressource, et nomme celle qui échoue.',
)]
final class EchangeSmokeCommand extends Command
{
    public function __construct(
        private readonly CanevasDEchange $canevas,
        private readonly ExportateurJsbx $exportateur,
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('idEntreprise', InputArgument::OPTIONAL, 'Identifiant du cabinet', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Un avertissement PHP silencieux est exactement ce qui a produit le 500 : ici,
        // il lève.
        set_error_handler(static function (int $niveau, string $message, string $fichier, int $ligne): bool {
            throw new \ErrorException($message, 0, $niveau, $fichier, $ligne);
        });

        try {
            $entreprise = $this->em->getRepository(Entreprise::class)->find((int) $input->getArgument('idEntreprise'));
            if ($entreprise === null) {
                $io->error('Cabinet introuvable.');

                return Command::FAILURE;
            }

            $invite = $this->em->getRepository(\App\Entity\Invite::class)
                ->findOneBy(['entreprise' => $entreprise, 'proprietaire' => true]);
            if ($invite === null) {
                $io->error('Ce cabinet n\'a pas de propriétaire : impossible de simuler un export.');

                return Command::FAILURE;
            }

            $io->title(sprintf('Échange — cabinet « %s »', (string) $entreprise->getNom()));

            $lisibles = $this->canevas->ressourcesLisibles($invite);
            $io->writeln(sprintf('%d ressource(s) lisible(s) par le propriétaire.', count($lisibles)));

            $echecs = [];
            foreach ($lisibles as $code => $ressource) {
                try {
                    // ⚠ On appelle produire(), PAS exporter() : un outil de diagnostic ne
                    // doit jamais facturer. Le chemin complet décomptait une occurrence
                    // par ressource — quarante-deux occurrences et 23 400 tokens débités
                    // au propriétaire pour un contrôle technique, la première fois.
                    //
                    // Chaque ressource est traitée SEULE : c'est ce qui permet de nommer
                    // la fautive, là où un export global qui tombe ne désigne personne.
                    // On capte la progression pour la VÉRIFIER : un pourcentage qui
                    // n'atteint jamais cent, ou qui n'est jamais publié, se voit ici
                    // plutôt que sous les yeux de l'utilisateur.
                    $etapes = 0;
                    $dernierPct = 0.0;
                    $progression = new Progression(0, static function (array $etat) use (&$etapes, &$dernierPct): void {
                        ++$etapes;
                        $dernierPct = $etat['pct'];
                    });

                    [$classeur, , $lignes] = $this->exportateur->produire(
                        $entreprise,
                        $invite,
                        $entreprise->getUtilisateur(),
                        [$code => $ressource],
                        $progression,
                    );

                    $io->writeln(sprintf(
                        '  <info>OK</info>   %-28s %s ligne(s), %d feuille(s), %d point(s) de progression, %.0f %%',
                        $code,
                        number_format($lignes, 0, ',', ' '),
                        $classeur->getSheetCount(),
                        $etapes,
                        $dernierPct,
                    ));
                } catch (\Throwable $e) {
                    $echecs[$code] = $e;
                    $io->writeln(sprintf('  <error>KO</error>   %-28s %s', $code, $e->getMessage()));
                }
            }

            if ($echecs !== []) {
                $io->error(sprintf('%d ressource(s) en échec.', count($echecs)));
                foreach ($echecs as $code => $e) {
                    $io->section($code);
                    $io->writeln(sprintf('%s: %s', $e::class, $e->getMessage()));
                    $io->writeln(sprintf('%s:%d', $e->getFile(), $e->getLine()));
                }

                return Command::FAILURE;
            }

            // ── PASSE COMPLÈTE ──────────────────────────────────────────────────
            // Le cas réel : les quarante-deux feuilles d'un coup. C'est là que la
            // progression compte vraiment, et là seulement qu'on peut vérifier qu'elle
            // avance régulièrement au lieu de sauter de zéro à cent.
            $io->section('Export complet');

            $points = [];
            $progression = new Progression(0, static function (array $etat) use (&$points): void {
                $points[] = $etat;
            });

            $debut = microtime(true);
            [$classeur, , $lignes] = $this->exportateur->produire(
                $entreprise,
                $invite,
                $entreprise->getUtilisateur(),
                $lisibles,
                $progression,
            );
            $duree = microtime(true) - $debut;

            $pourcentages = array_map(static fn (array $p) => $p['pct'], $points) ?: [0.0];
            $avecEstimation = array_filter($points, static fn (array $p) => $p['restant'] !== null);

            $io->writeln(sprintf(
                '  %s ligne(s) sur %d feuille(s), en %.1f s',
                number_format($lignes, 0, ',', ' '),
                $classeur->getSheetCount(),
                $duree,
            ));
            $io->writeln(sprintf(
                '  %d point(s) de progression, de %.0f %% a %.0f %%',
                count($points),
                min($pourcentages),
                max($pourcentages),
            ));
            $io->writeln(sprintf('  %d point(s) portant une estimation de temps restant', count($avecEstimation)));

            if (max($pourcentages) < 99.0) {
                $io->error('La progression n\'atteint jamais cent pour cent : le denominateur est faux.');

                return Command::FAILURE;
            }


            $io->success('Toutes les ressources s\'exportent sans avertissement.');

            return Command::SUCCESS;
        } finally {
            restore_error_handler();
        }
    }
}
