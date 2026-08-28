<?php

namespace App\Service\Retro;

use App\Entity\Invite;
use App\Entity\Partenaire;
use App\Repository\ReversementRetroAgentRepository;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\Canvas\Indicator\TrancheIndicatorStrategy;

/**
 * LE SEUL ENDROIT QUI SAIT CONSTRUIRE UN BÉNÉFICIAIRE.
 *
 * `AgentRetro` et `PartenaireRetro` ne prennent pas les mêmes dépendances — l'un lit des
 * reversements, l'autre un indicateur de tranche — et cette différence s'était recopiée en
 * QUATRE endroits : le contrôleur du rapport, l'outil de lecture de Ket, la façade du
 * rapport, et un test. Chacun devait donc injecter les trois services, et le jour où l'un
 * des deux camps gagne une dépendance, ces quatre sites divergent.
 *
 * Ici, un seul appel : `pour($agentOuPartenaire)`. L'appelant n'a plus à savoir DE QUOI un
 * bénéficiaire est fait, seulement lequel il veut — c'est le point même de l'interface.
 */
final class BeneficiaireRetroFactory
{
    public function __construct(
        private readonly IndicatorCalculationHelper $helper,
        private readonly ReversementRetroAgentRepository $reversements,
        private readonly TrancheIndicatorStrategy $strategieTranche,
    ) {
    }

    public function pour(Invite|Partenaire $cible): BeneficiaireRetro
    {
        return $cible instanceof Invite
            ? new AgentRetro($cible, $this->helper, $this->reversements)
            : new PartenaireRetro($cible, $this->helper, $this->strategieTranche);
    }
}
