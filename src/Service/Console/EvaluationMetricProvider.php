<?php

namespace App\Service\Console;

use App\Entity\Crm\CrmTache;
use App\Entity\Crm\CrmTicket;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @file Métriques système attribuables à un collaborateur (objectifs AUTO).
 * @description Registre de clés de métriques recalculées à la volée depuis les
 * données existantes, UNIQUEMENT là où l'attribution à un collaborateur est réelle
 * (agent d'un ticket, assigné d'une tâche). Le registre est volontairement restreint
 * et extensible : ajouter une entrée à self::definitions() suffit. Pas de promesse de
 * mesure auto sur des données non attribuables.
 */
class EvaluationMetricProvider
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * Définitions des métriques disponibles : clé => libellé humain.
     *
     * @return array<string, string>
     */
    public function metriquesDisponibles(): array
    {
        return [
            'tickets_resolus' => 'Tickets de support résolus',
            'taches_faites'   => 'Tâches CRM terminées',
        ];
    }

    /** Libellé d'une clé de métrique (repli sur la clé brute si inconnue). */
    public function label(?string $cle): string
    {
        if ($cle === null) {
            return '—';
        }

        return $this->metriquesDisponibles()[$cle] ?? $cle;
    }

    /**
     * Valeur atteinte pour une métrique, un collaborateur et une période.
     * Retourne 0.0 si la métrique est inconnue (objectif probablement passé en manuel).
     */
    public function value(string $cle, Utilisateur $collaborateur, \DateTimeImmutable $debut, \DateTimeImmutable $fin): float
    {
        return match ($cle) {
            'tickets_resolus' => $this->ticketsResolus($collaborateur, $debut, $fin),
            'taches_faites'   => $this->tachesFaites($collaborateur, $debut, $fin),
            default           => 0.0,
        };
    }

    private function ticketsResolus(Utilisateur $u, \DateTimeImmutable $debut, \DateTimeImmutable $fin): float
    {
        return (float) $this->em->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(CrmTicket::class, 't')
            ->andWhere('t.agent = :u')->setParameter('u', $u)
            ->andWhere('t.statut IN (:statuts)')
            ->setParameter('statuts', [CrmTicket::STATUT_RESOLU, CrmTicket::STATUT_CLOS])
            ->andWhere('t.resoluAt BETWEEN :debut AND :fin')
            ->setParameter('debut', $debut)->setParameter('fin', $fin)
            ->getQuery()->getSingleScalarResult();
    }

    private function tachesFaites(Utilisateur $u, \DateTimeImmutable $debut, \DateTimeImmutable $fin): float
    {
        return (float) $this->em->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(CrmTache::class, 't')
            ->andWhere('t.assigneA = :u')->setParameter('u', $u)
            ->andWhere('t.statut = :statut')->setParameter('statut', CrmTache::STATUT_FAITE)
            ->andWhere('t.closedAt BETWEEN :debut AND :fin')
            ->setParameter('debut', $debut)->setParameter('fin', $fin)
            ->getQuery()->getSingleScalarResult();
    }
}
