<?php

namespace App\Service\Retro;

use App\Entity\Avenant;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Invite;
use App\Repository\ReversementRetroAgentRepository;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;

/**
 * L'AGENT INTERNE comme bénéficiaire : ce qu'il touche sur les affaires qu'il APPORTE.
 *
 * Son assiette n'est pas celle du partenaire : il touche un pourcentage de ce qui RESTE au
 * cabinet — la commission pure totale, MOINS ce qui est déjà parti chez les intermédiaires
 * externes. Les partenaires se servent d'abord ; l'agent partage le reliquat.
 *
 * Aucun calcul ici : chaque montant vient d'IndicatorCalculationHelper, la source unique
 * qui alimente les fiches, les listes et l'assistant. Un chiffre de ce rapport est donc, au
 * centime, celui que le courtier lit à l'écran de l'affaire.
 */
final class AgentRetro implements BeneficiaireRetro
{
    /** @var array<int, float> id d'avenant => total versé, chargé en un seul appel */
    private array $versements = [];

    public function __construct(
        private readonly Invite $agent,
        private readonly IndicatorCalculationHelper $helper,
        private readonly ReversementRetroAgentRepository $reversements,
    ) {
    }

    public function agent(): Invite
    {
        return $this->agent;
    }

    public function type(): string
    {
        return self::TYPE_AGENT;
    }

    public function id(): ?int
    {
        return $this->agent->getId();
    }

    public function nom(): string
    {
        return (string) $this->agent->getNom();
    }

    /**
     * Les affaires dont la piste porte l'une de ses conditions de partage. JAMAIS celles
     * qu'il gère : un agent peut apporter dix affaires et n'en suivre aucune.
     */
    public function cotations(): array
    {
        $cotations = [];
        foreach ($this->agent->getConditionsPartageAgent() as $condition) {
            foreach ($condition->getPistesAffectees() as $piste) {
                foreach ($piste->getCotations() as $cotation) {
                    // Dédoublonnage : deux conditions du même agent peuvent viser la même
                    // affaire (seule la première s'applique, mais toutes deux la désignent).
                    $cotations[(int) $cotation->getId()] = $cotation;
                }
            }
        }

        return $cotations;
    }

    public function montantDu(?Cotation $cotation): float
    {
        return $this->helper->getCotationMontantRetroAgent($cotation, $this->agent);
    }

    public function prechargerVersements(array $avenants): void
    {
        $this->versements = $this->reversements->totauxParAvenant($this->agent, $avenants);
    }

    /**
     * Le versé se lit DIRECTEMENT sur les reversements, sans prorata de note : ce circuit
     * ne passe par aucune note de débit ou de crédit.
     */
    public function montantPaye(?Cotation $cotation, ?Avenant $avenant): float
    {
        $id = $avenant?->getId();
        if ($id === null) {
            return 0.0;
        }

        return $this->versements[$id]
            ?? $this->helper->getAvenantMontantRetroAgentReversee($avenant, $this->agent);
    }

    public function montantExigible(?Cotation $cotation, ?Avenant $avenant): float
    {
        return $avenant !== null
            ? $this->helper->getAvenantRetroAgentExigible($avenant, $this->agent)
            : 0.0;
    }

    /** Ce qui RESTE au cabinet une fois les intermédiaires externes servis. */
    public function assiette(?Cotation $cotation): float
    {
        return $this->helper->getCotationAssietteRetroAgent($cotation);
    }

    /** Un agent peut porter plusieurs conditions sur la même affaire : la PREMIÈRE applicable. */
    public function conditionRetenue(?Cotation $cotation): ?ConditionPartage
    {
        return $this->helper->getCotationConditionsAgent($cotation, $this->agent)[$this->agent->getId()] ?? null;
    }

    public function note(): string
    {
        return 'RÉTROCOMMISSION D\'AGENT INTERNE (pas de partenaire externe) : '
            . 'assiette = ce qui RESTE au cabinet, soit la commission pure (HT moins la taxe due par '
            . 'le courtier) MOINS les rétrocommissions des partenaires externes. Le taux de la '
            . 'condition de partage s\'y applique. '
            . 'L\'agent BÉNÉFICIAIRE n\'est PAS le gestionnaire de l\'affaire : il peut l\'avoir '
            . 'apportée puis en confier le suivi à un collègue — ne jamais déduire l\'un de l\'autre. '
            . '« due » naît à la souscription ; « exigible » n\'est réclamable qu\'une fois la '
            . 'commission encaissée par le cabinet — ne propose jamais de verser un montant non '
            . 'exigible. Les affaires « en attente » sont des PROJECTIONS : aucun montant n\'y est dû. '
            . 'Ce circuit ne passe par AUCUNE note de débit ou de crédit : le versement est direct, '
            . 'comptabilisé en charges de personnel (SYSCOHADA 6611). Le payé et l\'exigible se '
            . 'lisent à la maille de l\'AVENANT.';
    }
}
