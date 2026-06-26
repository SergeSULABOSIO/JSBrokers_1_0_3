<?php

namespace App\Command;

use App\Crm\CrmSyncService;
use App\Entity\Crm\CrmHealthSnapshot;
use App\Entity\Utilisateur;
use App\Repository\Crm\CrmHealthSnapshotRepository;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Resynchronise tous les profils CRM (étape de pipeline + score de santé) depuis
 * les données SaaS et enregistre un instantané quotidien du score (tendance).
 * À planifier en cron (ex. chaque nuit) :
 *   php bin/console app:crm:sync
 */
#[AsCommand(name: 'app:crm:sync', description: 'Resynchronise les profils CRM et capture les snapshots de santé.')]
class CrmSyncCommand extends Command
{
    public function __construct(
        private UtilisateurRepository $utilisateurRepository,
        private CrmSyncService $crmSync,
        private CrmHealthSnapshotRepository $snapshotRepository,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $clients = $this->utilisateurRepository->findAllCrm();
        $io->progressStart(count($clients));

        $snapshots = 0;
        foreach ($clients as $client) {
            /** @var Utilisateur $client */
            $sync = $this->crmSync->refresh($client, false);

            if (!$this->snapshotRepository->hasToday($client)) {
                $this->em->persist((new CrmHealthSnapshot())
                    ->setUtilisateur($client)
                    ->setScore($sync['health']['score'])
                    ->setCouleur($sync['health']['couleur'])
                    ->setDetails($sync['health']['details']));
                $snapshots++;
            }
            $io->progressAdvance();
        }

        $this->em->flush();
        $io->progressFinish();
        $io->success(sprintf('%d profils synchronisés, %d snapshots enregistrés.', count($clients), $snapshots));

        return Command::SUCCESS;
    }
}
