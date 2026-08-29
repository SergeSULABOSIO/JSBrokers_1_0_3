<?php

namespace App\Service\RetroAgent;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\ReversementRetroAgent;
use App\Entity\Partenaire;
use App\Service\Retro\BeneficiaireRetro;
use App\Service\Retro\BeneficiaireRetroFactory;
use App\Service\Retro\RapportProductionBuilder;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\Canvas\Indicator\TrancheIndicatorStrategy;
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
        private readonly BeneficiaireRetroFactory $beneficiaires,
        // L'exigible d'une ÉCHÉANCE se lit sur son indicateur — un par famille, même règle.
        private readonly TrancheIndicatorStrategy $strategieTranche,
    ) {
    }

    /**
     * @param string $statut l'un des CotationSouscriptionScope::STATUT_*
     *
     * @return array<string, mixed>
     */
    public function build(Invite $agent, Entreprise $entreprise, string $statut = CotationSouscriptionScope::STATUT_SOUSCRITES): array
    {
        $rapport = $this->pour($this->beneficiaire($agent), $entreprise, $statut);
        $rapport['agent'] = $agent;

        return $rapport;
    }

    /**
     * LE MÊME RAPPORT, POUR L'UN OU L'AUTRE BÉNÉFICIAIRE.
     *
     * L'écran ne connaissait que l'agent, alors que le socle sait rendre les deux depuis
     * l'extraction. Le partenaire n'avait donc AUCUN rapport à lui — ses chiffres
     * n'existaient qu'en agrégat sur sa fiche, et seul l'assistant savait les détailler.
     *
     * Le descripteur `beneficiaire` porte ce dont le gabarit a besoin pour se rendre sans
     * connaître la famille : un identifiant, un nom, un type et le préfixe d'URL de ses
     * propres actions. Sans lui, le gabarit aurait dû tester la famille à chaque ligne.
     *
     * @return array<string, mixed>
     */
    public function pour(
        BeneficiaireRetro $beneficiaire,
        Entreprise $entreprise,
        string $statut = CotationSouscriptionScope::STATUT_SOUSCRITES,
    ): array {
        $rapport = $this->builder->build($beneficiaire, $entreprise, $statut);

        $estAgent = $beneficiaire->type() === BeneficiaireRetro::TYPE_AGENT;
        $rapport['beneficiaire'] = [
            'id' => $beneficiaire->id(),
            'nom' => $beneficiaire->nom(),
            'type' => $beneficiaire->type(),
            'estAgent' => $estAgent,
            // Le préfixe des routes qui le concernent : l'agent garde les siennes
            // historiques, le partenaire a les siennes propres.
            'prefixe' => $estAgent
                ? '/admin/retro-agent/' . $beneficiaire->id()
                : '/admin/retro-agent/partenaire/' . $beneficiaire->id(),
        ];
        $rapport['lignes'] = array_map([$this, 'aliaser'], $rapport['lignes']);
        $rapport['totaux'] = $this->aliaser($rapport['totaux']);

        return $rapport;
    }

    /**
     * Les lignes où le bénéficiaire a un solde EXIGIBLE — la matière du picker.
     * On ne propose que ce qui est réclamable : le dû non encore encaissé par le cabinet
     * n'a pas à être versé, et cette garde vaut pour les deux familles.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lignesAVerser(Invite $agent): array
    {
        return $this->lignesAVerserPour($this->beneficiaire($agent), $agent->getEntreprise());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function lignesAVerserPour(BeneficiaireRetro $beneficiaire, Entreprise $entreprise): array
    {
        return array_values(array_filter(
            $this->pour($beneficiaire, $entreprise, CotationSouscriptionScope::STATUT_SOUSCRITES)['lignes'],
            static fn (array $ligne) => $ligne['retroAgentExigible'] > 0.0,
        ));
    }

    /**
     * LES ÉCHÉANCES À RÉGLER — la matière du picker, à la maille où l'argent circule.
     *
     * La prime et la commission se paient par tranche : c'est à ce rythme que
     * l'intermédiaire est rémunéré, et c'est donc une ÉCHÉANCE qu'on règle, pas une affaire.
     * Proposer l'affaire obligeait ensuite à répartir le versement sur ses échéances — une
     * règle que personne n'a écrite, et qu'il aurait fallu inventer.
     *
     * L'exigible se lit sur l'indicateur de la tranche, différent selon la famille :
     * l'agent partage le reliquat, le partenaire la commission partageable. Les deux
     * suivent la même règle de NAISSANCE de la dette — la commission de CETTE échéance
     * encaissée — depuis que l'exigibilité de l'agent a rejoint celle du partenaire.
     *
     * @return array<int, array<string, mixed>> une ligne par échéance réglable
     */
    public function echeancesAVerser(BeneficiaireRetro $beneficiaire, Entreprise $entreprise): array
    {
        $cle = $beneficiaire->type() === BeneficiaireRetro::TYPE_AGENT
            ? 'retroAgentExigible'
            : 'retroCommissionExigible';

        $echeances = [];
        foreach ($beneficiaire->cotations() as $cotation) {
            $piste = $cotation->getPiste();
            foreach ($cotation->getTranches() as $tranche) {
                $exigible = round((float) ($this->strategieTranche->calculate($tranche)[$cle] ?? 0.0), 2);
                if ($exigible <= 0.0) {
                    continue;
                }

                // L'AFFAIRE reste portée par la ligne : elle dit SUR QUOI porte le
                // versement, quand l'échéance dit QUAND. Les deux voyagent ensemble
                // jusqu'à l'enregistrement, où l'invariant les vérifie.
                $avenant = $cotation->getAvenants()->first() ?: null;

                $echeances[] = [
                    'trancheId' => $tranche->getId(),
                    'trancheNom' => $tranche->getNom(),
                    'echeanceAt' => $tranche->getEcheanceAt(),
                    'avenantId' => $avenant?->getId(),
                    'reference' => $avenant?->getReferencePolice() ?: 'Sans référence',
                    'client' => $piste?->getClient()?->getNom() ?? 'N/A',
                    'risque' => $piste?->getRisque()?->getCode() ?? 'N/A',
                    'exigible' => $exigible,
                    // Le picker lit cette clé depuis l'origine : la garder évite de
                    // toucher au gabarit et au contrôleur Stimulus pour un renommage.
                    'retroAgentExigible' => $exigible,
                ];
            }
        }

        return $echeances;
    }

    /**
     * LES ÉCHÉANCES D'UN VIREMENT QU'ON ROUVRE — celles qu'il règle, et celles qu'il
     * pourrait régler.
     *
     * Ouvrir un virement en édition demande l'UNION de deux ensembles :
     *
     *   — les échéances que ce virement règle DÉJÀ, cochées, au montant enregistré ;
     *   — celles qui restent exigibles, décochées, comme à la création.
     *
     * ⚠ LE PLAFOND D'UNE LIGNE DÉJÀ RÉGLÉE DOIT ÊTRE ROUVERT. Son exigible vaut zéro
     * précisément PARCE QUE ce virement l'a payée : laisser ce zéro comme maximum aurait
     * interdit de conserver son propre montant — le champ se serait vidé à la première
     * validation, et le virement aurait fondu sans que rien ne l'explique. Le plafond
     * d'une ligne du lot est donc « ce qui reste exigible + ce que CE virement y a déjà
     * versé ».
     *
     * @param ReversementRetroAgent[] $membres les lignes du virement en cours d'édition
     *
     * @return array{lignes: array<int, array<string, mixed>>, cochees: array<int, string>}
     *         `cochees` porte les clés « trancheId:avenantId » des lignes déjà réglées
     */
    public function echeancesDuVirement(
        BeneficiaireRetro $beneficiaire,
        Entreprise $entreprise,
        array $membres,
    ): array {
        // LA CLÉ D'UNE LIGNE EST LE COUPLE (échéance, affaire) : c'est ce couple que
        // l'écriture rapproche, et il ne peut y avoir qu'un versement de ce virement pour
        // un même couple.
        $verse = [];
        $porteuses = [];
        foreach ($membres as $membre) {
            $cle = self::cleDeLigne($membre->getTranche()?->getId(), $membre->getAvenant()?->getId());
            $verse[$cle] = round(($verse[$cle] ?? 0.0) + (float) $membre->getMontant(), 2);
            $porteuses[$cle] = $membre;
        }

        $lignes = [];
        $vues = [];
        foreach ($this->echeancesAVerser($beneficiaire, $entreprise) as $ligne) {
            $cle = self::cleDeLigne($ligne['trancheId'] ?? null, $ligne['avenantId'] ?? null);
            $vues[$cle] = true;
            if (isset($verse[$cle])) {
                $ligne['retroAgentExigible'] = round($ligne['retroAgentExigible'] + $verse[$cle], 2);
                $ligne['exigible'] = $ligne['retroAgentExigible'];
                $ligne['montantVerse'] = $verse[$cle];
            }
            $lignes[] = $ligne;
        }

        // LES ÉCHÉANCES DU VIREMENT QUE L'EXIGIBLE NE RAMÈNE PLUS. Une échéance
        // intégralement réglée par ce virement n'a plus rien d'exigible : elle sort de
        // `echeancesAVerser`, et l'oublier ici l'aurait fait disparaître de son propre
        // virement — donc supprimer à la première validation.
        foreach ($verse as $cle => $montant) {
            if (isset($vues[$cle])) {
                continue;
            }
            $lignes[] = $this->ligneDepuisMembre($porteuses[$cle], $montant);
        }

        return ['lignes' => $lignes, 'cochees' => array_keys($verse)];
    }

    /** La clé d'une ligne : le couple (échéance, affaire), tel que l'écriture le rapproche. */
    public static function cleDeLigne(?int $trancheId, ?int $avenantId): string
    {
        return ($trancheId ?? 0) . ':' . ($avenantId ?? 0);
    }

    /**
     * Une ligne du picker reconstituée depuis un versement déjà écrit — pour les échéances
     * que ce virement a soldées, et que l'exigible ne ramène donc plus.
     *
     * @return array<string, mixed>
     */
    private function ligneDepuisMembre(ReversementRetroAgent $membre, float $montant): array
    {
        $tranche = $membre->getTranche();
        $avenant = $membre->getAvenant();
        $piste = $tranche?->getCotation()?->getPiste() ?? $avenant?->getCotation()?->getPiste();

        return [
            'trancheId' => $tranche?->getId(),
            'trancheNom' => $tranche?->getNom() ?? 'Toute la police',
            'echeanceAt' => $tranche?->getEcheanceAt(),
            'avenantId' => $avenant?->getId(),
            'reference' => $avenant?->getReferencePolice() ?: 'Sans référence',
            'client' => $piste?->getClient()?->getNom() ?? 'N/A',
            'risque' => $piste?->getRisque()?->getCode() ?? 'N/A',
            'exigible' => $montant,
            'retroAgentExigible' => $montant,
            'montantVerse' => $montant,
        ];
    }

    /**
     * Le bénéficiaire, quelle que soit sa famille — la fabrique est le seul endroit qui
     * sait de quoi chacune est faite.
     */
    public function beneficiaire(Invite|Partenaire $cible): BeneficiaireRetro
    {
        return $this->beneficiaires->pour($cible);
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
