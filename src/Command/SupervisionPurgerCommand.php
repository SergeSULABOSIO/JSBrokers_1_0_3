<?php

namespace App\Command;

use App\Repository\ErreurApplicativeRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * FERME LE DOSSIER DES DÉFAUTS TRANCHÉS DEPUIS LONGTEMPS.
 *
 * ── POURQUOI CETTE COMMANDE EXISTE ──────────────────────────────────────────
 * La table de supervision ne s'efface jamais d'elle-même : chaque défaut
 * distinct y laisse une ligne pour toujours, avec sa trace complète. Sur la
 * durée, ce sont les erreurs corrigées il y a un an qui occupent l'écran, et la
 * rubrique cesse d'être une liste de tâches pour devenir des archives.
 *
 * ── CE QU'ELLE N'EFFACE JAMAIS ──────────────────────────────────────────────
 * ⚠ TOUT CE QUI EST OUVERT RESTE, quel que soit son âge. Un défaut « nouveau »
 * que personne n'a regardé depuis un an reste un défaut que personne n'a
 * regardé : l'effacer ne le corrigerait pas, cela effacerait seulement la preuve
 * qu'il existe — et il reviendrait ensuite comme une nouveauté, compteur remis
 * à zéro, perdant du même coup son ampleur.
 *
 * Seuls partent les défauts TRANCHÉS — résolus ou ignorés — qui ne se sont plus
 * manifestés depuis la durée de rétention. Un défaut résolu qui reparaît est
 * repassé « nouvelle » par l'enregistreur avant que cette commande ne le voie ;
 * il est donc à l'abri par construction.
 *
 * À planifier une fois par semaine. `--simuler` dit ce qu'elle ferait, sans
 * rien effacer — comme `app:echange:purger`, avec lequel elle partage ce motif.
 */
#[AsCommand(
    name: 'app:supervision:purger',
    description: 'Efface les défauts résolus ou ignorés qui ne se sont plus manifestés depuis 90 jours.',
)]
final class SupervisionPurgerCommand extends Command
{
    /**
     * Trois mois. Assez pour qu'un défaut corrigé au printemps soit encore
     * consultable en été si la question revient ; assez peu pour que la liste
     * reste celle du travail en cours.
     */
    private const RETENTION_JOURS = 90;

    public function __construct(
        private readonly ErreurApplicativeRepository $depot,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('simuler', null, InputOption::VALUE_NONE, 'N\'efface rien : dit seulement ce qui le serait.')
            ->addOption('jours', null, InputOption::VALUE_REQUIRED, 'Durée de rétention, en jours.', (string) self::RETENTION_JOURS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simuler = (bool) $input->getOption('simuler');

        $jours = max(1, (int) $input->getOption('jours'));
        $limite = new \DateTimeImmutable(sprintf('-%d days', $jours));

        $purgeables = $this->depot->compterPurgeables($limite);

        if (0 === $purgeables) {
            $io->success(sprintf('Rien à effacer : aucun défaut tranché avant le %s.', $limite->format('d/m/Y')));

            return Command::SUCCESS;
        }

        if ($simuler) {
            $io->success(sprintf(
                'Simulation : %d défaut(s) tranché(s) sans occurrence depuis le %s seraient effacés.',
                $purgeables,
                $limite->format('d/m/Y'),
            ));

            return Command::SUCCESS;
        }

        $supprimes = $this->depot->purgerTranchesAvant($limite);

        $io->success(sprintf(
            'Purge : %d défaut(s) effacé(s) — tranchés et sans occurrence depuis le %s.',
            $supprimes,
            $limite->format('d/m/Y'),
        ));

        return Command::SUCCESS;
    }
}
