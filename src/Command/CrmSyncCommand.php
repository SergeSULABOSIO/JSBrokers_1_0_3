<?php

namespace App\Command;

use App\Crm\CrmMaintenanceService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Resynchronise tous les profils CRM (étape de pipeline + score de santé) depuis
 * les données SaaS et enregistre un instantané quotidien du score (tendance).
 * À planifier en cron (ex. chaque nuit) — OU laissé au déclenchement paresseux
 * de la console (heartbeat) :
 *   php bin/console app:crm:sync
 */
#[AsCommand(name: 'app:crm:sync', description: 'Resynchronise les profils CRM et capture les snapshots de santé.')]
class CrmSyncCommand extends Command
{
    public function __construct(private CrmMaintenanceService $maintenance)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $res = $this->maintenance->syncAndSnapshotAll();
        $io->success(sprintf('%d profils synchronisés, %d snapshots enregistrés.', $res['clients'], $res['snapshots']));

        return Command::SUCCESS;
    }
}
