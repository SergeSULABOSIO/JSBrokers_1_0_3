<?php

namespace App\Command;

use App\Entity\Avenant;
use App\Entity\Piste;
use App\Form\PisteType;
use App\Services\Tranche\RegularisationDesEcheances;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * RATTRAPAGE DES ÉCHÉANCES « À RÉGULARISER » des annulations et résiliations enregistrées
 * avant que la règle existe.
 *
 * MÊME RÈGLE QUE LE MOUVEMENT (RegularisationDesEcheances) : une échéance de la police de
 * base, exigible le jour de la date d'effet de l'acte ou après, sans aucun encaissement de
 * prime. Une migration SQL ne saurait pas voir les encaissements déduits des bordereaux.
 *
 * IDEMPOTENTE : une échéance déjà marquée par ce mouvement est seulement listée, jamais
 * remarquée ; une échéance déjà régularisée par un AUTRE mouvement n'est pas touchée.
 * Appelée par bin/deploy.sh après les migrations. --dry-run liste sans rien écrire, pour un
 * examen préalable en production ; le mode réel affiche le même récapitulatif.
 */
#[AsCommand(name: 'app:echeances:regulariser', description: 'Rattrape les échéances « à régulariser » des annulations et résiliations déjà enregistrées.')]
final class EcheancesRegulariserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RegularisationDesEcheances $regularisation,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('rattrapage', null, InputOption::VALUE_NONE, 'Parcourt les annulations et résiliations déjà enregistrées.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Liste les échéances qui seraient marquées, sans rien écrire.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$input->getOption('rattrapage')) {
            $io->error('Précisez --rattrapage (et --dry-run pour un examen sans écriture).');

            return Command::INVALID;
        }
        $simulation = (bool) $input->getOption('dry-run');

        /** @var Piste[] $mouvements */
        $mouvements = $this->em->getRepository(Piste::class)->createQueryBuilder('p')
            ->where('p.avenantDeBase IS NOT NULL')
            ->andWhere('p.typeAvenant IN (:actes)')
            ->setParameter('actes', [Piste::AVENANT_ANNULATION, Piste::AVENANT_RESILIATION])
            ->orderBy('p.id', 'ASC')
            ->getQuery()->getResult();

        $lignes = [];
        $aMarquer = 0;
        $dejaMarquees = 0;
        foreach ($mouvements as $mouvement) {
            $base = $mouvement->getAvenantDeBase();
            $dateEffet = $this->dateDEffet($mouvement);
            if ($base === null || $dateEffet === null) {
                continue; // acte sans avenant : pas de date d'effet, rien à décider
            }
            $acte = sprintf(
                '%s au %s (opportunité #%d)',
                PisteType::TYPE_AVENANT_LABELS[$mouvement->getTypeAvenant()] ?? 'Acte',
                $dateEffet->format('d/m/Y'),
                $mouvement->getId(),
            );

            foreach ($this->regularisation->analyser($base, $dateEffet)['aRegulariser'] as $ligne) {
                $tranche = $ligne['tranche'];
                if ($tranche->getRegularisationPar() === $mouvement) {
                    $etat = 'déjà marquée';
                    ++$dejaMarquees;
                } elseif ($tranche->getRegularisationPar() !== null) {
                    continue; // régularisée par un autre mouvement : on n'y touche pas
                } else {
                    $etat = $simulation ? 'serait marquée' : 'marquée';
                    ++$aMarquer;
                    if (!$simulation) {
                        $tranche->setRegularisationPar($mouvement);
                    }
                }
                $lignes[] = [
                    (string) $base->getReferencePolice(),
                    sprintf('%s · %s', (string) $tranche->getNom(), RegularisationDesEcheances::dateDeReference($tranche)?->format('d/m/Y') ?? '—'),
                    number_format($ligne['prime'], 2, ',', ' '),
                    $acte,
                    $etat,
                ];
            }
        }

        if (!$simulation && $aMarquer > 0) {
            $this->em->flush();
        }

        $io->title($simulation ? 'Échéances à régulariser — SIMULATION (rien n’est écrit)' : 'Échéances à régulariser — rattrapage');
        if ($lignes === []) {
            $io->success('Aucune échéance à régulariser.');

            return Command::SUCCESS;
        }
        $io->table(['Police', 'Échéance', 'Prime', 'Acte en cause', 'État'], $lignes);
        $io->success(sprintf(
            '%d échéance%s %s, %d déjà marquée%s.',
            $aMarquer,
            $aMarquer > 1 ? 's' : '',
            $simulation ? ($aMarquer > 1 ? 'seraient marquées' : 'serait marquée') : ($aMarquer > 1 ? 'marquées' : 'marquée'),
            $dejaMarquees,
            $dejaMarquees > 1 ? 's' : '',
        ));

        return Command::SUCCESS;
    }

    /** Date d'effet de l'acte : celle de l'avenant né de l'opportunité dérivée (interrogé en base). */
    private function dateDEffet(Piste $mouvement): ?\DateTimeImmutable
    {
        $acte = $this->em->getRepository(Avenant::class)->createQueryBuilder('a')
            ->join('a.cotation', 'c')
            ->where('c.piste = :piste')
            ->setParameter('piste', $mouvement)
            ->orderBy('a.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        return $acte?->getStartingAt();
    }
}
