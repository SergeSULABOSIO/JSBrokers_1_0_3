<?php

namespace App\Crm;

use App\Entity\Crm\CrmHealthSnapshot;
use App\Entity\Utilisateur;
use App\Repository\Crm\CrmHealthSnapshotRepository;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @file Routine de maintenance CRM (resync + snapshots + automatisations).
 * @description Source unique de la « tâche quotidienne » du CRM, appelée par la
 * commande cron app:crm:sync ET par le déclenchement paresseux (heartbeat à
 * l'ouverture de la console / ping Stimulus). Centraliser ici évite toute
 * duplication entre ces points d'entrée (DRY).
 */
class CrmMaintenanceService
{
    public function __construct(
        private EntityManagerInterface $em,
        private UtilisateurRepository $utilisateurRepository,
        private CrmSyncService $crmSync,
        private CrmHealthSnapshotRepository $snapshotRepository,
        private CrmAutomationEngine $automationEngine,
    ) {
    }

    /**
     * Resynchronise tous les profils clients (étape + score) et capture un
     * instantané quotidien du score de santé (une fois par jour et par client).
     *
     * @return array{clients:int, snapshots:int}
     */
    public function syncAndSnapshotAll(): array
    {
        $clients = $this->utilisateurRepository->findAllCrm();
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
        }

        $this->em->flush();

        return ['clients' => count($clients), 'snapshots' => $snapshots];
    }

    /**
     * Routine quotidienne complète : resync + snapshots puis automatisations.
     *
     * @return array<string, int>
     */
    public function runDaily(): array
    {
        $base = $this->syncAndSnapshotAll();
        $auto = $this->automationEngine->runScheduled();

        return $base + $auto;
    }
}
