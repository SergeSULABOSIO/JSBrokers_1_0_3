<?php

namespace App\Service\RetroAgent;

use App\Entity\Avenant;
use App\Entity\Cotation;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Repository\ReversementRetroAgentRepository;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\Search\CotationSouscriptionScope;
use App\Services\ServiceMonnaies;

/**
 * LE RAPPORT DE PRODUCTION D'UN AGENT INTERNE — ligne par ligne, de la prime au solde dû.
 *
 * Ce que le courtier doit pouvoir suivre du regard, affaire par affaire : la prime du
 * client, la commission TTC, sa part HT, les taxes, la commission pure, l'assiette
 * partageable, ce qui part chez un partenaire externe, ce qui revient à CET agent, ce qui
 * lui a déjà été versé, et ce qui reste dû.
 *
 * ── CE SERVICE NE CALCULE RIEN ──────────────────────────────────────────────────────
 * Il LIT IndicatorCalculationHelper — la source unique du projet, celle qui alimente les
 * fiches, les listes et Ket — et met en forme. Un chiffre du rapport est donc, au centime
 * près, celui de l'écran de l'affaire. Recalculer ici aurait produit un second jeu de
 * formules, et la première divergence n'aurait été découverte que par un agent contestant
 * sa rémunération.
 *
 * ── DEUX FAMILLES DE LIGNES, ET POURQUOI ELLES NE SE MÉLANGENT PAS ──────────────────
 * « Souscrites » : les AVENANTS — des polices concrétisées, dont la rétrocommission est
 * réellement due. « En attente » : les COTATIONS encore sans avenant — des projections,
 * utiles pour piloter l'effort commercial en cours, mais qui ne doivent jamais entrer dans
 * un montant dû. Les caduques (une concurrente a emporté le marché) sont écartées : elles
 * ne demandent plus aucun effort et gonfleraient artificiellement le pipeline.
 */
final class RapportProductionAgentBuilder
{
    public function __construct(
        private readonly IndicatorCalculationHelper $helper,
        private readonly ReversementRetroAgentRepository $reversements,
        private readonly EligibiliteRetroAgent $eligibilite,
        private readonly ServiceMonnaies $serviceMonnaies,
    ) {
    }

    /**
     * @param string $statut l'un des CotationSouscriptionScope::STATUT_*
     *
     * @return array<string, mixed>
     */
    public function build(Invite $agent, Entreprise $entreprise, string $statut = CotationSouscriptionScope::STATUT_SOUSCRITES): array
    {
        if (!CotationSouscriptionScope::estValide($statut)) {
            $statut = CotationSouscriptionScope::STATUT_SOUSCRITES;
        }

        $lignes = $statut === CotationSouscriptionScope::STATUT_SOUSCRITES
            ? $this->lignesSouscrites($agent)
            : $this->lignesEnAttente($agent, $statut);

        return [
            'agent'      => $agent,
            'entreprise' => $entreprise,
            'statut'     => $statut,
            'statuts'    => CotationSouscriptionScope::VALEURS,
            'monnaie'    => $this->serviceMonnaies->getCodeMonnaieAffichage(),
            'lignes'     => $lignes,
            'totaux'     => $this->totaux($lignes),
            // Une projection ne se confond pas avec un dû : le gabarit s'en sert pour
            // afficher un avertissement au-dessus du tableau plutôt que de laisser croire
            // que ces montants sont exigibles.
            'projection' => $statut !== CotationSouscriptionScope::STATUT_SOUSCRITES,
        ];
    }

    /**
     * Les AVENANTS portant une condition de cet agent : ses affaires réellement souscrites.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lignesSouscrites(Invite $agent): array
    {
        $avenants = [];
        foreach ($this->cotationsDeLAgent($agent) as $cotation) {
            foreach ($cotation->getAvenants() as $avenant) {
                $avenants[] = $avenant;
            }
        }

        // Une lecture UNIQUE des reversements pour tout le lot : les interroger avenant par
        // avenant coûterait une requête par ligne du rapport.
        $verses = $this->reversements->totauxParAvenant($agent, $avenants);

        $lignes = [];
        foreach ($avenants as $avenant) {
            $lignes[] = $this->ligne($avenant->getCotation(), $agent, $avenant, $verses[$avenant->getId()] ?? 0.0);
        }

        return $this->trier($lignes);
    }

    /**
     * Les COTATIONS encore sans avenant — le pipeline. Les caduques sont écartées : une
     * proposition dont une concurrente a emporté le marché ne demande plus d'effort.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lignesEnAttente(Invite $agent, string $statut): array
    {
        $lignes = [];
        foreach ($this->cotationsDeLAgent($agent) as $cotation) {
            if ($this->helper->isCotationBound($cotation)) {
                continue;
            }
            $caduque = $this->helper->isCotationConcurrenteCaduque($cotation);
            if ($caduque !== ($statut === CotationSouscriptionScope::STATUT_CADUQUES)) {
                continue;
            }
            $lignes[] = $this->ligne($cotation, $agent, null, 0.0);
        }

        return $this->trier($lignes);
    }

    /**
     * Toutes les affaires dont cet agent est BÉNÉFICIAIRE — c'est-à-dire dont la piste
     * porte l'une de ses conditions de partage. Jamais celles qu'il gère : les deux axes
     * sont indépendants.
     *
     * @return array<int, Cotation>
     */
    private function cotationsDeLAgent(Invite $agent): array
    {
        $cotations = [];
        foreach ($agent->getConditionsPartageAgent() as $condition) {
            foreach ($condition->getPistesAffectees() as $piste) {
                foreach ($piste->getCotations() as $cotation) {
                    // Dédoublonnage : deux conditions du même agent peuvent viser la même
                    // affaire (seule la première s'applique, mais toutes deux la désignent).
                    $cotations[(int) $cotation->getId()] = $cotation;
                }
            }
        }

        return array_values($cotations);
    }

    /**
     * UNE LIGNE DU RAPPORT. Tous les montants viennent du helper ; aucun n'est recalculé.
     *
     * @return array<string, mixed>
     */
    private function ligne(?Cotation $cotation, Invite $agent, ?Avenant $avenant, float $verse): array
    {
        $piste = $cotation?->getPiste();
        $condition = $this->helper->getCotationConditionsAgent($cotation, $agent)[$agent->getId()] ?? null;

        $commissionHt = $this->helper->getCotationMontantCommissionHt($cotation, -1, false);
        $commissionTtc = $this->helper->getCotationMontantCommissionTtc($cotation, -1, false);
        $commissionPure = $this->helper->getCotationMontantCommissionPure($cotation, -1, false);
        $partageable = $this->helper->getCotationMontantCommissionHt($cotation, -1, true)
            - $this->helper->getCotationMontantTaxeCourtier($cotation, true);
        $retroPartenaire = $this->helper->getCotationMontantRetrocommissionsPayableParCourtier($cotation, null, -1);
        $retroAgent = $this->helper->getCotationMontantRetroAgent($cotation, $agent);

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
            'retroPartenaire'  => round($retroPartenaire, 2),
            'retroAgentDue'    => round($retroAgent, 2),
            'retroAgentPayee'  => round($verse, 2),
            'retroAgentSolde'  => round(max(0.0, $retroAgent - $verse), 2),
            'retroAgentExigible' => $avenant !== null
                ? round($this->helper->getAvenantRetroAgentExigible($avenant, $agent), 2)
                : 0.0,

            'conditionNom'  => $condition?->getNom(),
            'conditionTaux' => $condition?->getTaux(),
            // Verdict N-1, purement indicatif — le gestionnaire garde la décision.
            'eligibilite'   => $piste !== null ? $this->eligibilite->verdict($piste) : EligibiliteRetroAgent::INDETERMINE,
            'eligibiliteLibelle' => $piste !== null
                ? $this->eligibilite->libelle($this->eligibilite->verdict($piste))
                : $this->eligibilite->libelle(EligibiliteRetroAgent::INDETERMINE),
            'avertissement' => $piste !== null ? $this->eligibilite->avertissement($piste) : null,
        ];
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
            'retroAgentDue', 'retroAgentPayee', 'retroAgentSolde', 'retroAgentExigible',
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
            $this->lignesSouscrites($agent),
            static fn (array $ligne) => $ligne['retroAgentExigible'] > 0.0,
        ));
    }
}
