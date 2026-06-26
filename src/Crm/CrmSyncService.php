<?php

namespace App\Crm;

use App\Entity\Crm\CrmProfil;
use App\Entity\Utilisateur;
use App\Repository\Crm\CrmProfilRepository;
use App\Repository\InviteRepository;
use App\Repository\TokenConsumptionRepository;
use App\Repository\TokenPurchaseRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @file Synchronisation CRM : alimente le profil client depuis les données SaaS.
 * @description Source unique des « signaux » d'un client (entreprises, invités,
 * connexions, achats, consommation) — calculés une fois, réutilisés par le
 * pipeline ET le score de santé (DRY). Garantit le principe « aucune ressaisie » :
 * le profil est créé/mis à jour automatiquement à la connexion et à l'affichage
 * de la fiche, sans intervention du commercial.
 */
class CrmSyncService
{
    public function __construct(
        private EntityManagerInterface $em,
        private CrmProfilRepository $profilRepository,
        private TokenPurchaseRepository $purchaseRepository,
        private TokenConsumptionRepository $consumptionRepository,
        private InviteRepository $inviteRepository,
        private CrmPipelineService $pipeline,
        private CrmHealthScoreService $health,
    ) {
    }

    /** Récupère le profil CRM d'un client, en le créant (et persistant) si absent. */
    public function getOrCreateProfil(Utilisateur $user, bool $flush = false): CrmProfil
    {
        $profil = $this->profilRepository->findForUser($user);
        if ($profil === null) {
            $profil = new CrmProfil($user);
            $this->em->persist($profil);
            if ($flush) {
                $this->em->flush();
            }
        }

        return $profil;
    }

    /**
     * Construit le jeu de signaux d'un client à partir des sources SaaS.
     *
     * @return array<string, mixed>
     */
    public function buildSignals(Utilisateur $user): array
    {
        $uid = (int) $user->getId();
        $now = new \DateTimeImmutable();

        $purchase = $this->purchaseRepository->metricsForUser($uid);
        $lastConsoAt = $this->consumptionRepository->lastAtForProprietaire($uid);
        $lastLoginAt = $user->getLastLoginAt();

        // Activité = la plus récente entre dernière connexion et dernière opération métrée.
        $lastActivityAt = $this->latest($lastLoginAt, $lastConsoAt);

        return [
            'nbEntreprises'     => $user->getEntreprises()->count(),
            'nbInvites'         => $this->inviteRepository->countGuestsForOwner($uid),
            'loginCount'        => $user->getLoginCount(),
            'lastActivityAt'    => $lastActivityAt,
            'nbPurchases'       => $purchase['count'],
            'lastPurchaseAt'    => $purchase['last'],
            'firstPurchaseAt'   => $purchase['first'],
            'montantTotal'      => $purchase['montant'],
            'totalConsumption'  => $this->consumptionRepository->totalConsommeForProprietaire($uid),
            'consumption30'     => $this->consumptionRepository->sumForProprietaireSince($uid, $now->modify('-30 days')),
            'distinctEntites'   => $this->consumptionRepository->countDistinctEntitesForProprietaire($uid),
            'paidTokens'        => $user->getPaidTokens(),
            'daysSinceCreation' => $user->getCreatedAt() ? (int) $user->getCreatedAt()->diff($now)->days : 0,
            'openTickets'       => 0, // alimenté par le module Support (phase ultérieure)
        ];
    }

    /**
     * Applique les signaux à un profil : recalcule le score de santé puis l'étape
     * du pipeline, et met à jour l'indicateur de risque de churn. Ne flush pas.
     *
     * @param array<string, mixed> $signals
     *
     * @return array{score:int, couleur:string, details:array}
     */
    public function apply(CrmProfil $profil, array $signals): array
    {
        $health = $this->health->compute($signals);

        $profil->setScoreSante($health['score']);
        $profil->setScoreCouleur($health['couleur']);

        $this->pipeline->recompute($profil, $signals + ['score' => $health['score']]);

        $profil->setRisqueChurn(
            $profil->getEtapePipeline() === CrmPipelineService::STAGE_CHURN
            || in_array($health['couleur'], ['orange', 'rouge'], true),
        );

        return $health;
    }

    /**
     * Synchronise complètement le profil d'un client (signaux + score + étape).
     *
     * @return array{profil:CrmProfil, signals:array, health:array}
     */
    public function refresh(Utilisateur $user, bool $flush = true): array
    {
        $profil = $this->getOrCreateProfil($user);
        $signals = $this->buildSignals($user);
        $health = $this->apply($profil, $signals);

        if ($flush) {
            $this->em->flush();
        }

        return ['profil' => $profil, 'signals' => $signals, 'health' => $health];
    }

    /**
     * Synchronise un lot de clients (page de liste, scan de tableau de bord) avec
     * un seul flush. Renvoie les profils indexés par id d'utilisateur.
     *
     * @param Utilisateur[] $users
     *
     * @return array<int, CrmProfil>
     */
    public function refreshMany(array $users): array
    {
        $map = [];
        foreach ($users as $user) {
            $profil = $this->getOrCreateProfil($user);
            $this->apply($profil, $this->buildSignals($user));
            $map[(int) $user->getId()] = $profil;
        }
        $this->em->flush();

        return $map;
    }

    /** Renvoie la plus récente de deux dates (en ignorant les valeurs nulles). */
    private function latest(?\DateTimeImmutable $a, ?\DateTimeImmutable $b): ?\DateTimeImmutable
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return $a >= $b ? $a : $b;
    }
}
