<?php

namespace App\Service\RetroAgent;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Repository\ReversementRetroAgentRepository;
use App\Service\Retro\AgentRetro;
use App\Service\Retro\RapportProductionBuilder;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\Search\CotationSouscriptionScope;

/**
 * LE RAPPORT DE PRODUCTION D'UN AGENT INTERNE — façade sur le socle partagé.
 *
 * Le corps de ce service vit désormais dans App\Service\Retro : le même rapport sert un
 * agent interne et un partenaire externe, et le tenir en double aurait fait diverger les
 * deux camps au premier ajout de colonne (le partenaire n'avait, lui, aucun rapport du
 * tout — c'est ce déséquilibre qui a motivé l'extraction).
 *
 * ── POURQUOI CETTE FAÇADE SUBSISTE ──────────────────────────────────────────────────
 * L'écran du workspace et le picker de reversement lisent des clés en `retroAgent*`, et le
 * test de parité écran/Ket s'appuie sur cette signature. Une façade mince coûte moins
 * qu'une migration de gabarits dont rien n'avait besoin, et elle sert de FILET : tant
 * qu'elle rend exactement ce qu'elle rendait, l'extraction n'a rien déplacé.
 */
final class RapportProductionAgentBuilder
{
    public function __construct(
        private readonly RapportProductionBuilder $builder,
        private readonly IndicatorCalculationHelper $helper,
        private readonly ReversementRetroAgentRepository $reversements,
    ) {
    }

    /**
     * @param string $statut l'un des CotationSouscriptionScope::STATUT_*
     *
     * @return array<string, mixed>
     */
    public function build(Invite $agent, Entreprise $entreprise, string $statut = CotationSouscriptionScope::STATUT_SOUSCRITES): array
    {
        $rapport = $this->builder->build($this->beneficiaire($agent), $entreprise, $statut);

        $rapport['agent'] = $agent;
        $rapport['lignes'] = array_map([$this, 'aliaser'], $rapport['lignes']);
        $rapport['totaux'] = $this->aliaser($rapport['totaux']);

        return $rapport;
    }

    /**
     * Les lignes où l'agent a un solde EXIGIBLE — la matière du picker de reversement.
     * On ne propose que ce qui est réclamable : le dû non encore encaissé par le cabinet
     * n'a pas à être versé, c'est la même garde que pour les partenaires.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lignesAVerser(Invite $agent): array
    {
        return array_values(array_filter(
            $this->build($agent, $agent->getEntreprise(), CotationSouscriptionScope::STATUT_SOUSCRITES)['lignes'],
            static fn (array $ligne) => $ligne['retroAgentExigible'] > 0.0,
        ));
    }

    private function beneficiaire(Invite $agent): AgentRetro
    {
        return new AgentRetro($agent, $this->helper, $this->reversements);
    }

    /**
     * Les quatre colonnes du bénéficiaire, sous le nom que l'écran leur donne. Les clés
     * neutres restent présentes : un gabarit peut migrer quand il en aura une raison, pas
     * parce qu'un refactoring l'y aura forcé.
     *
     * @param array<string, mixed> $ligne
     *
     * @return array<string, mixed>
     */
    private function aliaser(array $ligne): array
    {
        return $ligne + [
            'retroAgentDue'      => $ligne['due'] ?? 0.0,
            'retroAgentPayee'    => $ligne['payee'] ?? 0.0,
            'retroAgentSolde'    => $ligne['solde'] ?? 0.0,
            'retroAgentExigible' => $ligne['exigible'] ?? 0.0,
        ];
    }
}
