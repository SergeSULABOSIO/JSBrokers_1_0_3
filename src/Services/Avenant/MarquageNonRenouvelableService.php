<?php

namespace App\Services\Avenant;

use App\Entity\Avenant;
use App\Entity\Invite;
use App\Services\Canvas\CalculationProvider;
use App\Services\ServiceMonnaies;

/**
 * SIGNALER QU'UNE POLICE NE SERA PAS RENOUVELÉE — source unique des règles du marquage.
 *
 * Appelé par la route de l'écran (AvenantController) ET par le constructeur du plan de
 * l'assistante (PreparerMarquageNonRenouvelableTool) : les deux chemins ne peuvent donc pas
 * diverger sur ce qu'ils exigent, ce qu'ils datent, ni sur ce qu'ils avertissent.
 *
 * TROIS GESTES, PAS DEUX. Marquer, corriger le motif, lever. Le motif s'affine avec le temps
 * — le client annonce en mars ce qui se produira en décembre — et le corriger ne doit pas
 * obliger à démarquer/remarquer, ce qui écraserait la date de la décision d'origine.
 *
 * CE QUE LE MARQUAGE NE FAIT PAS. Il ne touche pas renewalStatus : la police reste ACTIVE et
 * sa prime reste dans les totaux jusqu'à son terme. Il ne solde rien non plus — d'où
 * avertissements(), qui met sous les yeux ce qui reste à recouvrer AVANT de confirmer.
 */
final class MarquageNonRenouvelableService
{
    public function __construct(
        private readonly CalculationProvider $calculationProvider,
        private readonly ServiceMonnaies $serviceMonnaies,
    ) {
    }

    /**
     * Signale que la police ne sera pas renouvelée.
     *
     * Le motif est OBLIGATOIRE : c'est lui, et non le drapeau, qui a de la valeur pour le
     * collègue qui rouvrira le dossier dans huit mois. Un marquage sans raison est un trou
     * dans le pipeline que personne ne saura interpréter.
     *
     * @throws \InvalidArgumentException si le motif est vide
     */
    public function marquer(Avenant $avenant, ?string $motif, Invite $acteur): void
    {
        $motif = trim((string) $motif);
        if ($motif === '') {
            throw new \InvalidArgumentException(
                'Le motif est obligatoire : indiquez pourquoi cette police ne sera pas renouvelée.'
            );
        }

        // L'ordre compte : setNonRenouvelable() horodate le changement d'état et remet à null
        // la date de levée éventuelle (re-marquage après révision).
        $avenant->setNonRenouvelable(true);
        $avenant->setNonRenouvelableMotif($motif);
        $avenant->setNonRenouvelablePar($acteur);
    }

    /**
     * Corrige le seul motif d'un marquage existant. La date de la décision et son auteur
     * restent ceux de l'origine — c'est la décision qui est datée, pas sa formulation.
     * AuditableTrait::$updatedAt porte la trace de la retouche.
     *
     * @throws \InvalidArgumentException si le motif est vide ou la police non marquée
     */
    public function modifierMotif(Avenant $avenant, ?string $motif): void
    {
        if (!$avenant->isNonRenouvelable()) {
            throw new \InvalidArgumentException("Cette police n'est pas signalée non renouvelable.");
        }

        $motif = trim((string) $motif);
        if ($motif === '') {
            throw new \InvalidArgumentException('Le motif ne peut pas être vidé.');
        }

        $avenant->setNonRenouvelableMotif($motif);
    }

    /**
     * Retire le marquage : la police redevient renouvelable et réintègre INTÉGRALEMENT le
     * pipeline d'échéance — chips, tableau de bord, vigie, programme du jour, boussole —
     * sans aucun code de restauration, puisque tout ce monde lit le même prédicat SQL.
     *
     * LA TRACE SURVIT. Effacer motif et auteur supprimerait exactement ce que ce dispositif
     * existe pour garder : pourquoi on avait cru que le client partait, et qui l'avait
     * consigné. Seule la date de levée est posée (par le setter) ; l'historique reste lisible
     * dans les attributs calculés.
     */
    public function lever(Avenant $avenant): void
    {
        $avenant->setNonRenouvelable(false);
    }

    /**
     * CE QUI RESTE DÛ SUR CETTE POLICE, mis sous les yeux avant confirmation.
     *
     * Retirer une police du pipeline de RENOUVELLEMENT ne la retire d'aucun suivi de
     * RECOUVREMENT : prime exigible, commission à facturer, taxes et rétrocommissions
     * continuent d'être réclamées. Le dire ici évite la lecture « c'est classé, on n'y
     * revient plus », qui ferait perdre de l'argent déjà gagné.
     *
     * Ne calcule RIEN : lit les indicateurs déjà produits par AvenantIndicatorStrategy.
     *
     * @return list<string> phrases prêtes à afficher (vide = plus rien à réclamer)
     */
    public function avertissements(Avenant $avenant): array
    {
        try {
            $i = $this->calculationProvider->getIndicateursSpecifics($avenant);
        } catch (\Throwable) {
            // Un indicateur en défaut ne doit jamais empêcher de consigner la décision.
            return [];
        }

        $monnaie = $this->serviceMonnaies->getCodeMonnaieAffichage();
        $lignes  = [];

        foreach ([
            ['primeSoldeDue', 'de prime encore due par le client', 'le suivi des impayés reste actif'],
            ['solde_restant_du', 'de commission facturée non encaissée', 'le recouvrement reste actif'],
            ['taxeCourtierSolde', 'de taxe courtier restant à reverser', 'le suivi fiscal reste actif'],
            ['taxeAssureurSolde', 'de taxe assureur restant à reverser', 'le suivi fiscal reste actif'],
            ['retroCommissionSolde', 'de rétrocommission restant à payer au partenaire', 'le suivi reste actif'],
        ] as [$code, $quoi, $consequence]) {
            $montant = (float) ($i[$code] ?? 0.0);
            if ($montant <= 0.005) {
                continue;
            }

            $lignes[] = sprintf(
                'Il reste %s %s %s : %s.',
                number_format($montant, 2, ',', ' '),
                $monnaie,
                $quoi,
                $consequence,
            );
        }

        return $lignes;
    }
}
