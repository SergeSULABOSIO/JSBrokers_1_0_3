<?php

namespace App\Command;

use App\Crm\CrmAutomationEngine;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Exécute les automatisations CRM temporelles (relances d'inactivité, alertes de
 * solde, santé critique, SLA dépassés). Idempotent (clés d'automatisation).
 * À planifier en cron après app:crm:sync :
 *   php bin/console app:crm:run-automations
 */
#[AsCommand(name: 'app:crm:run-automations', description: 'Déclenche les automatisations CRM (tâches, alertes, notifications).')]
class CrmRunAutomationsCommand extends Command
{
    public function __construct(private CrmAutomationEngine $engine)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $counts = $this->engine->runScheduled();

        $io->success(sprintf(
            'Automatisations exécutées — relances: %d, soldes bas: %d, santé critique: %d, SLA dépassés: %d.',
            $counts['inactivite'],
            $counts['solde_bas'],
            $counts['sante_critique'],
            $counts['sla_depasse'],
        ));

        return Command::SUCCESS;
    }
}
