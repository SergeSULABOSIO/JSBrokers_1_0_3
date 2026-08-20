<?php

namespace App\Service\Retro;

use App\Entity\Avenant;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Partenaire;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\Canvas\Indicator\TrancheIndicatorStrategy;

/**
 * LE PARTENAIRE EXTERNE comme bénéficiaire : ce qu'il touche sur les affaires qu'il APPORTE.
 *
 * Il se sert AVANT l'agent : son assiette est la commission pure des revenus PARTAGEABLES,
 * pleine, sans déduction préalable. C'est ensuite le reliquat qui alimente les agents du
 * cabinet.
 *
 * ── DEUX CHEMINS POUR ÊTRE L'INTERMÉDIAIRE D'UNE AFFAIRE ────────────────────────────
 * L'affaire le désigne elle-même (Piste::partenaire), ou bien elle n'en désigne aucun et
 * c'est l'intermédiaire du CLIENT qui prend le relais. C'est la règle de
 * getCotationPartenaire(), et on ne la réécrit pas ici : on rassemble les affaires
 * candidates par les deux chemins, puis on demande au moteur, affaire par affaire, qui en
 * est réellement l'intermédiaire. Un client peut avoir plusieurs intermédiaires et une
 * piste peut en désigner un autre que celui de son client — trancher soi-même aurait
 * attribué à ce partenaire des affaires qui ne le paient pas.
 *
 * ── LE PAYÉ ET L'EXIGIBLE VIENNENT DES NOTES, À LA MAILLE TRANCHE ───────────────────
 * Contrairement à l'agent, rien n'est versé en clair : la rétrocommission d'un partenaire
 * se facture par NOTE DE CRÉDIT, et le payé s'en déduit au prorata des règlements. Son
 * exigibilité se juge tranche par tranche, sur la commission PARTAGEABLE encaissée — pas
 * sur la commission totale. On lit donc l'indicateur de la tranche plutôt que de récrire
 * sa formule : elle tient compte du circuit bordereau, qu'aucune reconstitution naïve
 * n'aurait honoré.
 */
final class PartenaireRetro implements BeneficiaireRetro
{
    /** @var array<int, float> id de cotation => exigible, mémoïsé le temps du rapport */
    private array $exigibles = [];

    public function __construct(
        private readonly Partenaire $partenaire,
        private readonly IndicatorCalculationHelper $helper,
        private readonly TrancheIndicatorStrategy $strategieTranche,
    ) {
    }

    public function partenaire(): Partenaire
    {
        return $this->partenaire;
    }

    public function type(): string
    {
        return self::TYPE_PARTENAIRE;
    }

    public function id(): ?int
    {
        return $this->partenaire->getId();
    }

    public function nom(): string
    {
        return (string) $this->partenaire->getNom();
    }

    public function cotations(): array
    {
        $candidates = [];
        foreach ($this->partenaire->getPistes() as $piste) {
            foreach ($piste->getCotations() as $cotation) {
                $candidates[(int) $cotation->getId()] = $cotation;
            }
        }
        foreach ($this->partenaire->getClients() as $client) {
            foreach ($client->getPistes() as $piste) {
                foreach ($piste->getCotations() as $cotation) {
                    $candidates[(int) $cotation->getId()] = $cotation;
                }
            }
        }

        // LE MOTEUR TRANCHE, PAS NOUS.
        return array_filter(
            $candidates,
            fn (Cotation $cotation) => $this->helper->isSamePartenaire(
                $this->helper->getCotationPartenaire($cotation),
                $this->partenaire,
            ),
        );
    }

    public function montantDu(?Cotation $cotation): float
    {
        return $this->helper->getCotationMontantRetrocommissionsPayableParCourtier($cotation, $this->partenaire, -1);
    }

    /** Rien à précharger : le payé se lit dans le graphe des notes déjà en mémoire. */
    public function prechargerVersements(array $avenants): void
    {
    }

    public function montantPaye(?Cotation $cotation, ?Avenant $avenant): float
    {
        return $this->helper->getCotationMontantRetrocommissionsPayableParCourtierPayee($cotation, $this->partenaire);
    }

    public function montantExigible(?Cotation $cotation, ?Avenant $avenant): float
    {
        if ($cotation === null || $avenant === null || !$this->helper->isCotationBound($cotation)) {
            return 0.0;
        }

        $cle = (int) $cotation->getId();
        if (isset($this->exigibles[$cle])) {
            return $this->exigibles[$cle];
        }

        $exigible = 0.0;
        foreach ($cotation->getTranches() as $tranche) {
            $exigible += (float) ($this->strategieTranche->calculate($tranche)['retroCommissionExigible'] ?? 0.0);
        }

        return $this->exigibles[$cle] = round($exigible, 2);
    }

    /**
     * La commission pure des revenus PARTAGEABLES — pleine, sans déduction préalable :
     * le partenaire se sert avant tout le monde.
     */
    public function assiette(?Cotation $cotation): float
    {
        return $this->helper->getCotationMontantCommissionHt($cotation, -1, true)
            - $this->helper->getCotationMontantTaxeCourtier($cotation, true);
    }

    /**
     * La cascade du partenaire : condition propre à l'affaire ▸ condition du partenaire ▸
     * aucune (son taux habituel s'applique alors). Elle se lit sur un REVENU — c'est à
     * cette maille que le taux est décidé ; on interroge donc le premier revenu partagé de
     * l'affaire, celui-là même dont le moteur tire l'argent.
     */
    public function conditionRetenue(?Cotation $cotation): ?ConditionPartage
    {
        if ($cotation === null) {
            return null;
        }

        foreach ($cotation->getRevenus() as $revenu) {
            if ($revenu->getTypeRevenu()?->isShared() !== true) {
                continue;
            }

            return $this->helper->conditionPartageRetenue($revenu);
        }

        return null;
    }

    public function note(): string
    {
        return 'RÉTROCOMMISSION DE PARTENAIRE EXTERNE (pas d\'agent interne) : '
            . 'assiette = la commission pure des revenus PARTAGEABLES (HT moins la taxe due par le '
            . 'courtier). Le partenaire se sert AVANT les agents du cabinet, qui ne partagent que '
            . 'le reliquat. Le taux est celui de la condition retenue — condition propre à '
            . 'l\'affaire, sinon condition du partenaire, sinon sa part habituelle ; sous son seuil, '
            . 'une condition ne partage RIEN et il n\'y a pas de repli sur le taux par défaut. '
            . 'Le partenaire APPORTE l\'affaire : il n\'en est pas le gestionnaire. '
            . '« due » naît à la souscription ; « exigible » n\'est réclamable qu\'une fois la '
            . 'commission partageable encaissée par le cabinet. Les affaires « en attente » sont '
            . 'des PROJECTIONS : aucun montant n\'y est dû. '
            . 'Ce circuit passe par une NOTE DE CRÉDIT : le payé s\'en déduit au prorata des '
            . 'règlements, et se comptabilise en rétrocommissions (SYSCOHADA 632). Le payé et '
            . 'l\'exigible se lisent à la maille de la TRANCHE, non de l\'avenant.';
    }
}
