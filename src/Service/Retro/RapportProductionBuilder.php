<?php

namespace App\Service\Retro;

use App\Entity\Avenant;
use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Service\RetroAgent\EligibiliteRetroAgent;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\Search\CotationSouscriptionScope;
use App\Services\ServiceMonnaies;
use DateTimeInterface;

/**
 * LE RAPPORT DE PRODUCTION D'UN BÉNÉFICIAIRE — ligne par ligne, de la prime au solde dû.
 *
 * Ce que le courtier doit pouvoir suivre du regard, affaire par affaire : la prime du
 * client, la commission TTC, sa part HT, les taxes, la commission pure, l'assiette
 * partageable, ce qui revient au bénéficiaire, ce qui lui a été versé, et ce qui reste dû.
 *
 * ── CE SERVICE NE CALCULE RIEN ──────────────────────────────────────────────────────
 * Il LIT IndicatorCalculationHelper — la source unique du projet, celle qui alimente les
 * fiches, les listes et Ket — et met en forme. Un chiffre du rapport est donc, au centime
 * près, celui de l'écran de l'affaire. Recalculer ici aurait produit un second jeu de
 * formules, et la première divergence n'aurait été découverte que par un bénéficiaire
 * contestant sa rémunération.
 *
 * ── UN SEUL SQUELETTE POUR LES DEUX CAMPS ───────────────────────────────────────────
 * Agent interne et partenaire externe ne diffèrent que sur ce que BeneficiaireRetro
 * délègue : où sont ses affaires, ce qu'elles lui doivent, ce qui lui a été payé, ce qui
 * est exigible, quelle condition s'applique, sur quelle assiette. Tout le reste est
 * commun, et c'est délibéré : deux rapports jumeaux entretenus à la main auraient dérivé
 * au premier ajout de colonne.
 *
 * ── DEUX FAMILLES DE LIGNES, ET POURQUOI ELLES NE SE MÉLANGENT PAS ──────────────────
 * « Souscrites » : les AVENANTS — des polices concrétisées, dont la rétrocommission est
 * réellement due. « En attente » : les COTATIONS encore sans avenant — des projections,
 * utiles pour piloter l'effort commercial en cours, mais qui ne doivent jamais entrer dans
 * un montant dû. Les caduques (une concurrente a emporté le marché) sont écartées : elles
 * ne demandent plus aucun effort et gonfleraient artificiellement le pipeline.
 */
final class RapportProductionBuilder
{
    /**
     * Libellés des unités de mesure d'un seuil.
     *
     * Recopiés de Constante::ConditionPartage_getUniteMesureString() — la version d'écran
     * vit dans une classe de ~375 méthodes qu'on n'injecte pas dans un service propre. La
     * ligne « agent » y manque d'ailleurs, et tombe sur « Non définie ».
     */
    private const UNITES = [
        ConditionPartage::UNITE_SOMME_COMMISSION_PURE_RISQUE => 'Com. pure du risque',
        ConditionPartage::UNITE_SOMME_COMMISSION_PURE_CLIENT => 'Com. pure du client',
        ConditionPartage::UNITE_SOMME_COMMISSION_PURE_PARTENAIRE => 'Com. pure du partenaire',
        ConditionPartage::UNITE_SOMME_COMMISSION_PURE_AGENT => 'Com. pure de l\'agent',
    ];

    public function __construct(
        private readonly IndicatorCalculationHelper $helper,
        private readonly EligibiliteRetroAgent $eligibilite,
        private readonly ServiceMonnaies $serviceMonnaies,
    ) {
    }

    /**
     * L'entreprise n'est qu'un rappel de contexte réémis au lecteur : le périmètre, lui,
     * est déjà tenu par le bénéficiaire, dont on ne parcourt que les affaires.
     *
     * @param string $statut l'un des CotationSouscriptionScope::STATUT_*
     * @param ?DateTimeInterface $du borne basse (incluse), sur la date d'effet
     * @param ?DateTimeInterface $au borne haute (incluse)
     *
     * @return array<string, mixed>
     */
    public function build(
        BeneficiaireRetro $beneficiaire,
        ?Entreprise $entreprise,
        string $statut = CotationSouscriptionScope::STATUT_SOUSCRITES,
        ?DateTimeInterface $du = null,
        ?DateTimeInterface $au = null,
    ): array {
        if (!CotationSouscriptionScope::estValide($statut)) {
            $statut = CotationSouscriptionScope::STATUT_SOUSCRITES;
        }

        $lignes = $statut === CotationSouscriptionScope::STATUT_SOUSCRITES
            ? $this->lignesSouscrites($beneficiaire)
            : $this->lignesEnAttente($beneficiaire, $statut);

        $lignes = $this->filtrerParPeriode($lignes, $du, $au);

        return [
            'beneficiaire' => $beneficiaire,
            'entreprise' => $entreprise,
            'statut'     => $statut,
            'statuts'    => CotationSouscriptionScope::VALEURS,
            'monnaie'    => $this->serviceMonnaies->getCodeMonnaieAffichage(),
            'lignes'     => $lignes,
            'totaux'     => $this->totaux($lignes),
            'periode'    => ($du === null && $au === null) ? null : [
                'du' => $du?->format('Y-m-d'),
                'au' => $au?->format('Y-m-d'),
            ],
            // Une projection ne se confond pas avec un dû : le gabarit s'en sert pour
            // afficher un avertissement au-dessus du tableau plutôt que de laisser croire
            // que ces montants sont exigibles.
            'projection' => $statut !== CotationSouscriptionScope::STATUT_SOUSCRITES,
        ];
    }

    /**
     * Les AVENANTS portant une condition de ce bénéficiaire : ses affaires réellement
     * souscrites.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lignesSouscrites(BeneficiaireRetro $beneficiaire): array
    {
        $avenants = [];
        foreach ($beneficiaire->cotations() as $cotation) {
            foreach ($cotation->getAvenants() as $avenant) {
                $avenants[] = $avenant;
            }
        }

        $beneficiaire->prechargerVersements($avenants);

        $lignes = [];
        foreach ($avenants as $avenant) {
            $lignes[] = $this->ligne($avenant->getCotation(), $avenant, $beneficiaire);
        }

        return $this->trier($lignes);
    }

    /**
     * Les COTATIONS encore sans avenant — le pipeline. Les caduques sont écartées : une
     * proposition dont une concurrente a emporté le marché ne demande plus d'effort.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lignesEnAttente(BeneficiaireRetro $beneficiaire, string $statut): array
    {
        $lignes = [];
        foreach ($beneficiaire->cotations() as $cotation) {
            if ($this->helper->isCotationBound($cotation)) {
                continue;
            }
            $caduque = $this->helper->isCotationConcurrenteCaduque($cotation);
            if ($caduque !== ($statut === CotationSouscriptionScope::STATUT_CADUQUES)) {
                continue;
            }
            $lignes[] = $this->ligne($cotation, null, $beneficiaire);
        }

        return $this->trier($lignes);
    }

    /**
     * UNE LIGNE DU RAPPORT. Tous les montants viennent du helper ou du bénéficiaire ;
     * aucun n'est recalculé.
     *
     * @return array<string, mixed>
     */
    private function ligne(?Cotation $cotation, ?Avenant $avenant, BeneficiaireRetro $beneficiaire): array
    {
        $piste = $cotation?->getPiste();
        $condition = $beneficiaire->conditionRetenue($cotation);

        $commissionHt = $this->helper->getCotationMontantCommissionHt($cotation, -1, false);
        $commissionTtc = $this->helper->getCotationMontantCommissionTtc($cotation, -1, false);
        $commissionPure = $this->helper->getCotationMontantCommissionPure($cotation, -1, false);
        $partageable = $this->helper->getCotationMontantCommissionHt($cotation, -1, true)
            - $this->helper->getCotationMontantTaxeCourtier($cotation, true);
        $retroPartenaire = $this->helper->getCotationMontantRetrocommissionsPayableParCourtier($cotation, null, -1);

        $due = $beneficiaire->montantDu($cotation);
        $payee = $beneficiaire->montantPaye($cotation, $avenant);

        return [
            'avenant'   => $avenant,
            'cotation'  => $cotation,
            'piste'     => $piste,
            'client'    => $piste?->getClient()?->getNom() ?? 'N/A',
            'risque'    => $piste?->getRisque()?->getCode() ?? 'N/A',
            'assureur'  => $cotation?->getAssureur()?->getNom() ?? 'N/A',
            'reference' => $avenant !== null
                ? ($avenant->getReferencePolice() ?: 'Sans référence')
                : ($cotation?->getNom() ?? 'Proposition'),
            // Le GESTIONNAIRE de l'affaire : rappel visible que le bénéficiaire n'est pas
            // forcément celui qui la suit au quotidien.
            'gestionnaire' => $piste?->getInvite()?->getNom() ?? 'N/A',
            'debut'     => $avenant?->getStartingAt(),
            'fin'       => $avenant?->getEndingAt(),

            'prime'            => round($this->helper->getCotationMontantPrimePayableParClient($cotation), 2),
            'commissionTtc'    => round($commissionTtc, 2),
            'commissionHt'     => round($commissionHt, 2),
            'taxeAssureur'     => round($this->helper->getCotationMontantTaxeAssureur($cotation, false), 2),
            'taxeCourtier'     => round($this->helper->getCotationMontantTaxeCourtier($cotation, false), 2),
            'commissionPure'   => round($commissionPure, 2),
            'partageable'      => round($partageable, 2),
            // Pour un agent : ce qui part chez les intermédiaires externes, et qui explique
            // que son assiette soit plus mince. Pour un partenaire : sa propre part.
            'retroPartenaire'  => round($retroPartenaire, 2),
            'due'              => round($due, 2),
            'payee'            => round($payee, 2),
            'solde'            => round(max(0.0, $due - $payee), 2),
            'exigible'         => round($beneficiaire->montantExigible($cotation, $avenant), 2),

            // ── LA CHAÎNE DE CALCUL, pour JUSTIFIER un montant contesté ──────────────
            // Le moteur connaît tout ceci puis le jette. Sans ces clés, un chiffre ne peut
            // qu'être affirmé ; avec elles, il s'explique.
            'conditionNom'     => $condition?->getNom(),
            'conditionTaux'    => $condition?->getTaux(),
            'conditionOrigine' => $this->origineDeLaCondition($condition, $beneficiaire),
            'assiette'         => round($beneficiaire->assiette($cotation), 2),
            'seuilFranchi'     => $this->seuilFranchi($condition, $cotation),
            'uniteMesure'      => $condition !== null
                ? (self::UNITES[$condition->getUniteMesure()] ?? 'Non définie')
                : null,

            // Verdict N-1, purement indicatif — le gestionnaire garde la décision.
            'eligibilite'   => $piste !== null ? $this->eligibilite->verdict($piste) : EligibiliteRetroAgent::INDETERMINE,
            'eligibiliteLibelle' => $piste !== null
                ? $this->eligibilite->libelle($this->eligibilite->verdict($piste))
                : $this->eligibilite->libelle(EligibiliteRetroAgent::INDETERMINE),
            'avertissement' => $piste !== null ? $this->eligibilite->avertissement($piste) : null,
        ];
    }

    /**
     * D'où vient le taux appliqué — la question que pose tout bénéficiaire qui conteste.
     *
     * Une condition rattachée à une PISTE est propre à cette affaire ; sinon elle appartient
     * au bénéficiaire et sert sur toutes les siennes. Aucune condition : pour un partenaire,
     * c'est sa part habituelle qui s'applique ; pour un agent, il n'y a tout simplement rien.
     */
    private function origineDeLaCondition(?ConditionPartage $condition, BeneficiaireRetro $beneficiaire): ?string
    {
        if ($condition === null) {
            return $beneficiaire->type() === BeneficiaireRetro::TYPE_PARTENAIRE
                ? 'part habituelle du partenaire'
                : null;
        }

        return $condition->getPiste() !== null
            ? 'condition propre à cette affaire'
            : 'condition du bénéficiaire';
    }

    /**
     * Le seuil est-il franchi ? Null quand la question ne se pose pas (pas de condition,
     * ou formule qui ignore le seuil). Le verdict se rend sur un REVENU : c'est à cette
     * maille que le moteur le tranche.
     */
    private function seuilFranchi(?ConditionPartage $condition, ?Cotation $cotation): ?bool
    {
        if ($condition === null || $cotation === null) {
            return null;
        }
        if ($condition->getFormule() === ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL) {
            return null;
        }

        foreach ($cotation->getRevenus() as $revenu) {
            return $this->helper->conditionFranchitSonSeuil($condition, $revenu, -1);
        }

        return null;
    }

    /**
     * La période porte sur la DATE D'EFFET de la police — c'est elle qui date le revenu.
     * Une projection n'en a pas : on retient alors la date de création de la proposition,
     * seule date qu'elle possède. Sans bornes, aucune ligne n'est écartée : le rapport
     * garde exactement le comportement qu'il a toujours eu.
     *
     * @param array<int, array<string, mixed>> $lignes
     *
     * @return array<int, array<string, mixed>>
     */
    private function filtrerParPeriode(array $lignes, ?DateTimeInterface $du, ?DateTimeInterface $au): array
    {
        if ($du === null && $au === null) {
            return $lignes;
        }

        return array_values(array_filter($lignes, static function (array $ligne) use ($du, $au): bool {
            $date = $ligne['debut'] ?? $ligne['cotation']?->getCreatedAt();
            if (!$date instanceof DateTimeInterface) {
                // Sans date, on ne peut ni l'inclure ni l'exclure honnêtement : on
                // l'écarte, puisque l'utilisateur a explicitement demandé une période.
                return false;
            }
            if ($du !== null && $date < $du) {
                return false;
            }

            return $au === null || $date <= $au;
        }));
    }

    /** Les plus récentes d'abord ; les propositions (sans date d'effet) en tête. */
    private function trier(array $lignes): array
    {
        usort($lignes, static function (array $a, array $b): int {
            return ($b['debut']?->getTimestamp() ?? PHP_INT_MAX) <=> ($a['debut']?->getTimestamp() ?? PHP_INT_MAX);
        });

        return $lignes;
    }

    /**
     * Pied de tableau : la somme de chaque colonne monétaire.
     *
     * Somme franche, sans dédoublonnage : une ligne = un avenant, et deux avenants d'une
     * même cotation sont deux lignes qui portent chacune les montants de leur affaire.
     * Le lecteur doit le savoir — d'où l'avertissement du gabarit quand une même police
     * apparaît plusieurs fois.
     *
     * @return array<string, float|int>
     */
    private function totaux(array $lignes): array
    {
        $colonnes = [
            'prime', 'commissionTtc', 'commissionHt', 'taxeAssureur', 'taxeCourtier',
            'commissionPure', 'partageable', 'retroPartenaire',
            'due', 'payee', 'solde', 'exigible',
        ];

        $totaux = array_fill_keys($colonnes, 0.0);
        $totaux['nbLignes'] = count($lignes);
        foreach ($lignes as $ligne) {
            foreach ($colonnes as $colonne) {
                $totaux[$colonne] += (float) $ligne[$colonne];
            }
        }

        return array_map(static fn ($v) => is_float($v) ? round($v, 2) : $v, $totaux);
    }
}
