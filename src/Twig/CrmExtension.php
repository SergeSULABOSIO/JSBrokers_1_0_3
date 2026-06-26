<?php

namespace App\Twig;

use App\Crm\CrmHealthScoreService;
use App\Crm\CrmPipelineService;
use App\Entity\Crm\CrmInteraction;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * @file Fonctions Twig du module CRM.
 * @description Expose les libellés/icônes d'étape de pipeline et la sémantique du
 * score de santé (couleur, libellé, hex) afin que les gabarits restent DRY et
 * cohérents avec les services métier (source unique des constantes).
 */
class CrmExtension extends AbstractExtension
{
    public function __construct(
        private CrmPipelineService $pipeline,
        private CrmHealthScoreService $health,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('crm_stage_label', [$this->pipeline, 'label']),
            new TwigFunction('crm_stage_icon', [$this->pipeline, 'icon']),
            new TwigFunction('crm_health_label', [$this->health, 'colorLabel']),
            new TwigFunction('crm_health_hex', [$this->health, 'colorHex']),
            new TwigFunction('crm_health_color', [$this->health, 'color']),
            new TwigFunction('crm_interaction_label', [$this, 'interactionLabel']),
        ];
    }

    public function interactionLabel(string $type): string
    {
        return CrmInteraction::TYPES[$type] ?? $type;
    }
}
