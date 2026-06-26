<?php

namespace App\Crm;

use App\Entity\Crm\CrmNotification;
use App\Entity\Crm\CrmTache;
use App\Entity\Utilisateur;
use App\Repository\Crm\CrmAutomationLogRepository;
use App\Repository\Crm\CrmProfilRepository;
use App\Repository\Crm\CrmTacheRepository;
use App\Repository\Crm\CrmTicketRepository;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @file Moteur d'automatisations CRM.
 * @description Applique les règles « si … alors … » du plan : inactivité →
 * tâche, solde bas → alerte, santé critique → tâche + notification, SLA dépassé →
 * escalade, premier achat → onboarding. Idempotent via CrmAutomationLog (clé
 * règle+entité) et la clé unique des tâches auto, pour éviter les doublons à
 * chaque exécution du cron.
 */
class CrmAutomationEngine
{
    public function __construct(
        private EntityManagerInterface $em,
        private UtilisateurRepository $utilisateurRepository,
        private CrmProfilRepository $profilRepository,
        private CrmTacheRepository $tacheRepository,
        private CrmTicketRepository $ticketRepository,
        private CrmAutomationLogRepository $logRepository,
        private CrmNotifier $notifier,
        private ParametresCrmService $params,
    ) {
    }

    /**
     * Exécute les règles temporelles (appelé par le cron app:crm:run-automations).
     *
     * @return array<string, int> Compteurs par règle déclenchée
     */
    public function runScheduled(): array
    {
        $counts = ['inactivite' => 0, 'solde_bas' => 0, 'sante_critique' => 0, 'sla_depasse' => 0];
        $now = new \DateTimeImmutable();
        $semaine = $now->format('o-\WW');
        $inactiviteJours = $this->params->inactiviteJours();
        $soldeBas = $this->params->soldeBas();

        $clients = $this->utilisateurRepository->findAllCrm();
        $profils = $this->profilRepository->mapByUserIds(array_map(static fn (Utilisateur $u) => (int) $u->getId(), $clients));

        foreach ($clients as $client) {
            $uid = (int) $client->getId();
            $profil = $profils[$uid] ?? null;

            // Règle 1 : aucune connexion depuis N jours → tâche de relance.
            $lastLogin = $client->getLastLoginAt();
            if ($lastLogin !== null && (int) $lastLogin->diff($now)->days >= $inactiviteJours) {
                $cle = sprintf('inactivite-%d-%s', $uid, $semaine);
                if (!$this->tacheRepository->existsByCleAuto($cle)) {
                    $this->em->persist((new CrmTache())
                        ->setClient($client)
                        ->setTitre('Relancer ' . ($client->getNom() ?: $client->getEmail()) . ' (inactif)')
                        ->setDescription(sprintf('Aucune connexion depuis %d jours.', (int) $lastLogin->diff($now)->days))
                        ->setPriorite(CrmTache::PRIORITE_NORMALE)
                        ->setOrigine(CrmTache::ORIGINE_AUTO)
                        ->setCleAuto($cle)
                        ->setDueAt($now->modify('+2 days')));
                    $counts['inactivite']++;
                }
            }

            // Règle 2 : solde de tokens bas → alerte (notification).
            if ($client->getPaidTokens() > 0 && $client->getPaidTokens() < $soldeBas) {
                if ($this->logRepository->record('solde_bas', sprintf('%d-%s', $uid, $semaine))) {
                    $this->notifier->broadcast(
                        'Solde de tokens bas',
                        sprintf('%s n\'a plus que %s tokens prépayés. Suggérer une recharge.', $client->getNom() ?: $client->getEmail(), number_format($client->getPaidTokens(), 0, '.', ' ')),
                        CrmNotification::NIVEAU_ALERTE,
                    );
                    $counts['solde_bas']++;
                }
            }

            // Règle 3 : santé critique (rouge) → tâche prioritaire + notification.
            if ($profil !== null && $profil->getScoreCouleur() === 'rouge') {
                $cle = sprintf('sante_critique-%d-%s', $uid, $semaine);
                if (!$this->tacheRepository->existsByCleAuto($cle)) {
                    $this->em->persist((new CrmTache())
                        ->setClient($client)
                        ->setTitre('Santé critique : ' . ($client->getNom() ?: $client->getEmail()))
                        ->setDescription('Score de santé au rouge — contacter le client rapidement.')
                        ->setPriorite(CrmTache::PRIORITE_HAUTE)
                        ->setOrigine(CrmTache::ORIGINE_AUTO)
                        ->setCleAuto($cle)
                        ->setDueAt($now->modify('+1 day')));
                    $this->notifier->broadcast(
                        'Client à risque',
                        sprintf('%s est passé en santé critique.', $client->getNom() ?: $client->getEmail()),
                        CrmNotification::NIVEAU_ALERTE,
                    );
                    $counts['sante_critique']++;
                }
            }
        }

        // Règle 4 : tickets dont le SLA est dépassé → notification d'escalade (une fois).
        foreach ($this->ticketRepository->findSlaBreached() as $ticket) {
            if ($this->logRepository->record('sla_depasse', 'ticket-' . $ticket->getId())) {
                $this->notifier->broadcast(
                    'SLA dépassé',
                    sprintf('Le ticket %s (%s) a dépassé son délai de traitement.', $ticket->getReference(), $ticket->getSujet()),
                    CrmNotification::NIVEAU_ALERTE,
                );
                $counts['sla_depasse']++;
            }
        }

        $this->em->flush();

        return $counts;
    }

    /**
     * Déclenché au premier achat d'un client : tâche d'onboarding + remerciement.
     * Idempotent (une seule fois par client). Ne flush pas (le flux d'achat flushe).
     */
    public function onFirstPurchase(Utilisateur $client): void
    {
        if (!$this->logRepository->record('premier_achat', 'user-' . $client->getId())) {
            return;
        }

        $this->em->persist((new CrmTache())
            ->setClient($client)
            ->setTitre('Onboarding : ' . ($client->getNom() ?: $client->getEmail()))
            ->setDescription('Premier achat effectué — dérouler le parcours d\'onboarding (prise en main, bonnes pratiques).')
            ->setPriorite(CrmTache::PRIORITE_HAUTE)
            ->setOrigine(CrmTache::ORIGINE_AUTO)
            ->setCleAuto('onboarding-user-' . $client->getId())
            ->setDueAt((new \DateTimeImmutable())->modify('+1 day')));

        $this->notifier->broadcast(
            'Premier achat 🎉',
            sprintf('%s vient d\'effectuer son premier achat de tokens.', $client->getNom() ?: $client->getEmail()),
            CrmNotification::NIVEAU_SUCCES,
        );
    }
}
