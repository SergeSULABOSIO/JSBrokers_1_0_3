<?php

namespace App\Command;

use App\Echange\Service\ImportateurJsbx;
use App\Repository\EchangeImportRunRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * EFFACE LES DÉPÔTS D'IMPORT ABANDONNÉS.
 *
 * ── POURQUOI CETTE COMMANDE EXISTE ──────────────────────────────────────────────────
 * ⚠ UN CONTRÔLE EXPIRE DEPUIS TOUJOURS, ET RIEN N'EN TIRAIT LA CONSÉQUENCE.
 * `EchangeImportRunRepository::expires()` était écrite, testée par sa seule existence, et
 * n'avait AUCUN appelant : aucune commande, aucune tâche planifiée. Un cabinet qui dépose
 * un fichier puis change d'avis laissait donc sur le disque un classeur portant le nom,
 * l'adresse et les primes de tous ses clients — indéfiniment, dans un répertoire que
 * personne ne regarde.
 *
 * La durée de vie annoncée par l'entité (vingt-quatre heures) n'était qu'une promesse
 * d'écran : elle fermait la porte de la confirmation, et laissait le fichier derrière.
 *
 * ── CE QU'ELLE NE FAIT PAS ──────────────────────────────────────────────────────────
 * Elle ne touche ni aux occurrences — un import qui a eu lieu se trace pour toujours —,
 * ni aux données importées, ni à quoi que ce soit de l'exportation. Elle referme des
 * dossiers restés ouverts, et rien d'autre.
 *
 * À planifier une fois par jour. `--simuler` dit ce qu'elle ferait, sans rien effacer.
 */
#[AsCommand(
    name: 'app:echange:purger',
    description: 'Efface les dépôts d\'import expirés et les fichiers qu\'ils désignent.',
)]
final class EchangePurgerCommand extends Command
{
    public function __construct(
        private readonly EchangeImportRunRepository $runs,
        private readonly ImportateurJsbx $importateur,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('simuler', null, InputOption::VALUE_NONE, 'N\'efface rien : dit seulement ce qui le serait.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simuler = (bool) $input->getOption('simuler');

        $expires = $this->runs->expires();
        $octets = 0;
        $fichiers = 0;

        foreach ($expires as $run) {
            $chemin = $run->getCheminFichier();
            if ($chemin !== null && is_file($chemin)) {
                $octets += (int) filesize($chemin);
                ++$fichiers;
            }

            $io->writeln(sprintf(
                '  <comment>%s</comment> — %s, déposé le %s',
                $run->getStatut(),
                $run->getNomFichier() ?? '(sans nom)',
                $run->getCreatedAt()?->format('d/m/Y H:i') ?? '?',
            ));

            if (!$simuler) {
                // `annuler()` est le geste qui existe : il pose le statut ET efface le
                // dépôt. En refaire ici une seconde version, ce serait s'engager à
                // maintenir les deux.
                $this->importateur->annuler($run);
            }
        }

        // ⚠ ET LES FICHIERS QUE PLUS AUCUN CONTRÔLE NE DÉSIGNE. Un contrôle supprimé à la
        // main, une transaction perdue, un dépôt interrompu entre l'écriture du fichier et
        // la création de son contrôle : le classeur reste, et rien ne le réclame plus.
        $orphelins = $this->orphelins($simuler);

        $io->success(sprintf(
            '%s : %d contrôle(s) expiré(s), %d fichier(s) déposé(s) (%s), %d orphelin(s).',
            $simuler ? 'Simulation' : 'Purge',
            count($expires),
            $fichiers,
            $this->lisible($octets),
            $orphelins,
        ));

        return Command::SUCCESS;
    }

    /**
     * Les classeurs déposés que plus aucun contrôle ne référence.
     *
     * ⚠ ON NE TOUCHE QU'AU RÉPERTOIRE DES DÉPÔTS D'IMPORT. Les exports préparés vivent
     * ailleurs et suivent leur propre règle — ils s'effacent à la lecture.
     *
     * @return int le nombre de fichiers effacés (ou qui le seraient)
     */
    private function orphelins(bool $simuler): int
    {
        $racine = $this->projectDir . '/var/echange';
        if (!is_dir($racine)) {
            return 0;
        }

        // Ce qui est encore réclamé par un contrôle vivant : on ne l'efface sous aucun
        // prétexte, même si le fichier paraît vieux.
        $reclames = [];
        foreach ($this->runs->findAll() as $run) {
            $chemin = $run->getCheminFichier();
            if ($chemin !== null) {
                $reclames[$chemin] = true;
            }
        }

        $efface = 0;
        // Une heure de grâce : un dépôt en cours d'écriture n'a pas encore son contrôle en
        // base, et l'effacer sous les pieds de la requête qui le crée serait une panne
        // qu'on aurait fabriquée soi-même.
        $seuil = time() - 3600;

        foreach (glob($racine . '/*/*.xlsx') ?: [] as $chemin) {
            if (isset($reclames[$chemin]) || filemtime($chemin) > $seuil) {
                continue;
            }
            if (!$simuler) {
                @unlink($chemin);
            }
            ++$efface;
        }

        return $efface;
    }

    private function lisible(int $octets): string
    {
        if ($octets < 1024) {
            return $octets . ' o';
        }

        return $octets < 1048576
            ? round($octets / 1024) . ' Ko'
            : round($octets / 1048576, 1) . ' Mo';
    }
}
