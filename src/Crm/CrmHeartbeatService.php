<?php

namespace App\Crm;

use App\Repository\PlateformeParametresRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @file Déclenchement « paresseux » des automatisations CRM (sans cron).
 * @description Throttle l'exécution de la routine quotidienne : au plus une fois
 * par fenêtre (24 h). La réservation se fait par une mise à jour SQL CONDITIONNELLE
 * et atomique de l'horodatage — un seul processus peut « gagner » même sous
 * requêtes concurrentes, ce qui évite toute double exécution. L'exécution réelle
 * est confiée à kernel.terminate (après la réponse) pour ne pas ralentir l'agent.
 */
class CrmHeartbeatService
{
    /** Fenêtre minimale entre deux exécutions de la routine. */
    public const INTERVAL_HOURS = 24;

    public function __construct(
        private EntityManagerInterface $em,
        private PlateformeParametresRepository $repository,
    ) {
    }

    /**
     * Tente de réserver l'exécution de la routine. Renvoie true si ce processus
     * a obtenu le créneau (la routine doit alors être lancée), false sinon.
     */
    public function claimIfDue(): bool
    {
        $singleton = $this->repository->getSingleton();
        $now = new \DateTimeImmutable();
        $cutoff = $now->modify('-' . self::INTERVAL_HOURS . ' hours');

        // Réservation atomique : ne réussit que si jamais exécuté ou hors fenêtre.
        $affected = $this->em->getConnection()->executeStatement(
            'UPDATE plateforme_parametres SET crm_last_auto_run_at = :now
             WHERE id = :id AND (crm_last_auto_run_at IS NULL OR crm_last_auto_run_at <= :cutoff)',
            [
                'now'    => $now->format('Y-m-d H:i:s'),
                'id'     => $singleton->getId(),
                'cutoff' => $cutoff->format('Y-m-d H:i:s'),
            ],
        );

        if ($affected === 1) {
            // Garde l'entité gérée cohérente avec la valeur écrite (un flush
            // ultérieur réécrira la même date, pas l'ancienne valeur nulle).
            $singleton->setCrmLastAutoRunAt($now);

            return true;
        }

        return false;
    }
}
