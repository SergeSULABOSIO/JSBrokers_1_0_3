<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * LE WORKER DE KET, TOUJOURS UN, JAMAIS DEUX (Serge, 2026-10-29).
 *
 * Lancée chaque minute (cron en production, tâche planifiée Windows en développement), elle
 * prend un VERROU DE FICHIER sans attendre (`flock`, l'équivalent de `flock -n`) :
 *   — pris : elle lance `messenger:consume async` avec une limite de durée et de mémoire. Le
 *     worker s'arrête PROPREMENT à la limite (code neuf relu, mémoire rendue) et le lancement
 *     suivant le remplace dans la minute ;
 *   — tenu par un autre : un worker tourne déjà, elle sort aussitôt, en silence.
 * Le verrou est tenu par le noyau : un worker tué (plantage, redémarrage) le rend aussitôt.
 *
 * Pourquoi elle existe : le worker lancé par `symfony serve` (`--time-limit=3600`) s'arrêtait au
 * bout d'une heure et n'était jamais relancé — mesuré le 2026-10-29 : démarré à 10 h 44 min 13 s,
 * dernier signe à 11 h 44, aucun successeur. Ket restait muet jusqu'à une relance à la main.
 */
#[AsCommand(name: 'app:ket:worker', description: 'Lance le worker de Ket s’il ne tourne pas déjà (verrou), avec des limites de durée et de mémoire.')]
final class WorkerDeKetCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/worker/worker-%kernel.environment%.lock')]
        private readonly string $fichierDuVerrou,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Durée de vie du worker, en secondes', '3300')
            ->addOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'Mémoire au-delà de laquelle il s’arrête', '200M');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dossier = \dirname($this->fichierDuVerrou);
        if (!is_dir($dossier)) {
            @mkdir($dossier, 0775, true);
        }
        $verrou = fopen($this->fichierDuVerrou, 'c');
        if ($verrou === false) {
            $output->writeln('<error>Verrou du worker de Ket impossible à ouvrir : ' . $this->fichierDuVerrou . '</error>');

            return Command::FAILURE;
        }
        if (!flock($verrou, LOCK_EX | LOCK_NB)) {
            fclose($verrou);
            // Le cas ordinaire du cron (une fois par minute) : rien à dire, sauf à qui le demande.
            $output->writeln('Un worker de Ket tourne déjà : rien à faire.', OutputInterface::VERBOSITY_VERBOSE);

            return Command::SUCCESS;
        }

        try {
            $consommer = new ArrayInput([
                'receivers' => ['async'],
                '--time-limit' => (string) $input->getOption('time-limit'),
                '--memory-limit' => (string) $input->getOption('memory-limit'),
            ]);
            // Lancé par un cron : jamais de question.
            $consommer->setInteractive(false);

            return $this->getApplication()->find('messenger:consume')->run($consommer, $output);
        } finally {
            flock($verrou, LOCK_UN);
            fclose($verrou);
        }
    }
}
