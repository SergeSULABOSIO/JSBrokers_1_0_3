<?php

namespace App\Crm;

use App\Entity\Crm\CrmProfil;

/**
 * @file Pipeline commercial du cycle de vie client (interne JS Brokers).
 * @description Source de vérité des étapes du pipeline + dérivation automatique
 * depuis les signaux SaaS (inscription, connexions, entreprises, invités, achats,
 * consommation). L'agent peut forcer manuellement une étape relationnelle (démo,
 * qualification) : la dérivation n'avance alors que vers un jalon dur (achat) ou
 * vers un churn. Aucune ressaisie d'information déjà connue de la plateforme.
 *
 * Les signaux sont calculés une seule fois par CrmSyncService (DRY) puis passés
 * ici sous forme de tableau, ce qui évite de dupliquer les requêtes.
 */
class CrmPipelineService
{
    public const STAGE_PROSPECT      = 'prospect';
    public const STAGE_CONTACT       = 'premier_contact';
    public const STAGE_QUALIFICATION = 'qualification';
    public const STAGE_DEMO          = 'demonstration';
    public const STAGE_ESSAI         = 'essai';
    public const STAGE_PREMIER_ACHAT = 'premier_achat';
    public const STAGE_ACTIF         = 'client_actif';
    public const STAGE_FIDELE        = 'client_fidele';
    public const STAGE_AMBASSADEUR   = 'client_ambassadeur';
    public const STAGE_CHURN         = 'churn';

    /** Ordre + libellé + alias d'icône (IconCanvasProvider) de chaque étape. */
    public const STAGES = [
        self::STAGE_PROSPECT      => ['rank' => 0, 'label' => 'Prospect',           'icon' => 'piste'],
        self::STAGE_CONTACT       => ['rank' => 1, 'label' => 'Premier contact',    'icon' => 'contact'],
        self::STAGE_QUALIFICATION => ['rank' => 2, 'label' => 'Qualification',      'icon' => 'action:analyser'],
        self::STAGE_DEMO          => ['rank' => 3, 'label' => 'Démonstration',      'icon' => 'action:view'],
        self::STAGE_ESSAI         => ['rank' => 4, 'label' => 'Essai',              'icon' => 'action:premium'],
        self::STAGE_PREMIER_ACHAT => ['rank' => 5, 'label' => 'Premier achat',      'icon' => 'action:cart'],
        self::STAGE_ACTIF         => ['rank' => 6, 'label' => 'Client actif',       'icon' => 'client'],
        self::STAGE_FIDELE        => ['rank' => 7, 'label' => 'Client fidèle',      'icon' => 'action:renew'],
        self::STAGE_AMBASSADEUR   => ['rank' => 8, 'label' => 'Client ambassadeur', 'icon' => 'action:premium'],
        self::STAGE_CHURN         => ['rank' => -1, 'label' => 'Churn / Inactif',   'icon' => 'action:alert'],
    ];

    /** Étapes que l'agent peut forcer manuellement (phases relationnelles). */
    public const MANUAL_STAGES = [
        self::STAGE_CONTACT,
        self::STAGE_QUALIFICATION,
        self::STAGE_DEMO,
    ];

    /** Seuil d'inactivité (jours) au-delà duquel un compte engagé bascule en churn (défaut, surchargé par la config). */
    public const CHURN_INACTIVE_DAYS = 45;

    public function __construct(private ParametresCrmService $params)
    {
    }

    public function isValidStage(string $stage): bool
    {
        return isset(self::STAGES[$stage]);
    }

    public function label(string $stage): string
    {
        return self::STAGES[$stage]['label'] ?? $stage;
    }

    public function icon(string $stage): string
    {
        return self::STAGES[$stage]['icon'] ?? 'piste';
    }

    private function rank(string $stage): int
    {
        return self::STAGES[$stage]['rank'] ?? 0;
    }

    /** Étapes ordonnées pour le Kanban (le churn est rendu à part). */
    public function orderedStages(): array
    {
        $stages = array_filter(
            self::STAGES,
            static fn (string $k) => $k !== self::STAGE_CHURN,
            ARRAY_FILTER_USE_KEY,
        );
        uasort($stages, static fn ($a, $b) => $a['rank'] <=> $b['rank']);

        return $stages;
    }

    /**
     * Étape déduite automatiquement des signaux SaaS.
     *
     * @param array{
     *   nbEntreprises:int, nbInvites:int, loginCount:int, lastActivityAt:?\DateTimeImmutable,
     *   nbPurchases:int, totalConsumption:int, score:int, daysSinceCreation:int
     * } $s
     */
    public function deriveAuto(array $s): string
    {
        $now = new \DateTimeImmutable();
        $daysSinceActivity = $s['lastActivityAt'] instanceof \DateTimeInterface
            ? (int) $s['lastActivityAt']->diff($now)->days
            : null;

        // Churn : compte ayant déjà eu de l'engagement, mais silencieux depuis trop longtemps.
        if (
            $daysSinceActivity !== null
            && $daysSinceActivity > $this->params->churnJours()
            && ($s['nbPurchases'] > 0 || $s['loginCount'] > 0)
        ) {
            return self::STAGE_CHURN;
        }

        if ($s['nbPurchases'] >= 2) {
            if ($s['score'] >= 85 && $s['daysSinceCreation'] >= 90) {
                return self::STAGE_AMBASSADEUR;
            }
            return self::STAGE_FIDELE;
        }

        if ($s['nbPurchases'] === 1) {
            return ($daysSinceActivity !== null && $daysSinceActivity <= 30)
                ? self::STAGE_ACTIF
                : self::STAGE_PREMIER_ACHAT;
        }

        // Aucun achat encore.
        if ($s['totalConsumption'] > 0) {
            return self::STAGE_ESSAI;
        }
        if ($s['nbInvites'] > 0) {
            return self::STAGE_QUALIFICATION;
        }
        if ($s['nbEntreprises'] > 0 || $s['loginCount'] > 0) {
            return self::STAGE_CONTACT;
        }

        return self::STAGE_PROSPECT;
    }

    /**
     * Applique la dérivation au profil en respectant l'override manuel et la règle
     * « jamais de recul automatique sauf churn ». Renvoie true si l'étape a changé.
     */
    public function recompute(CrmProfil $profil, array $signals): bool
    {
        $auto    = $this->deriveAuto($signals);
        $current = $profil->getEtapePipeline();

        if ($auto === $current) {
            return false;
        }

        $nouvelle = $current;

        if ($profil->isEtapeManuelleForcee()) {
            if ($auto === self::STAGE_CHURN) {
                $nouvelle = self::STAGE_CHURN;
                $profil->setEtapeManuelleForcee(false);
            } elseif ($current === self::STAGE_CHURN) {
                $nouvelle = $auto;
                $profil->setEtapeManuelleForcee(false);
            } elseif ($this->rank($auto) > $this->rank($current)) {
                $nouvelle = $auto;
                $profil->setEtapeManuelleForcee(false);
            }
        } else {
            if ($auto === self::STAGE_CHURN) {
                $nouvelle = self::STAGE_CHURN;
            } elseif ($current === self::STAGE_CHURN) {
                $nouvelle = $auto; // réactivation
            } elseif ($this->rank($auto) >= $this->rank($current)) {
                $nouvelle = $auto;
            }
        }

        if ($nouvelle !== $current) {
            $profil->setEtapePipeline($nouvelle);
            return true;
        }

        return false;
    }

    /**
     * Force manuellement une étape (override commercial via le Kanban). Marque le
     * profil comme « forcé » pour les étapes relationnelles afin que la dérivation
     * ne l'écrase pas tant qu'un jalon dur n'est pas atteint.
     */
    public function forceStage(CrmProfil $profil, string $stage): void
    {
        if (!$this->isValidStage($stage)) {
            return;
        }

        $profil->setEtapePipeline($stage);
        $profil->setEtapeManuelleForcee(in_array($stage, self::MANUAL_STAGES, true));
    }
}
