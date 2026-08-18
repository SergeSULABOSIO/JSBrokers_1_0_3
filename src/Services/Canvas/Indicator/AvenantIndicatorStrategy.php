<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Avenant;
use App\Entity\Entreprise;
use App\Repository\CotationRepository;
use App\Service\Partage\Reserve;
use App\Services\AvenantRenouvellementResolver;
use App\Services\Search\AvenantEcheanceScope;
use App\Services\Search\AvenantSuccessionScope;
use App\Services\ServiceDates;
use DateTimeImmutable;

class AvenantIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    /** @var array<int, array<string, array<array{id:int, startingAt:\DateTimeInterface}>>> */
    private array $typeAffaireBatch = [];

    public function __construct(
        private ServiceDates $serviceDates,
        private IndicatorCalculationHelper $calculationHelper,
        private CotationRepository $cotationRepository,
        private AvenantRenouvellementResolver $renouvellementResolver
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Avenant::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Avenant $entity */
        $cotation = $entity->getCotation();
        // QU'EST DEVENUE CETTE POLICE ? En TÊTE de fiche, avant tout chiffre : c'est
        // la question la plus posée sur un avenant, et la seule dont une réponse
        // fausse fait perdre un renouvellement. hasPisteDerivee ci-dessous ne dit
        // que l'existence d'un mouvement, jamais son aboutissement.
        $renouvellement = $this->renouvellementResolver->resoudre($entity);
        // ET D'OÙ VIENT-ELLE ? Le sens inverse, pour la même raison : sans lui, une
        // police NÉE d'un renouvellement se lit comme une affaire nouvelle et personne
        // — l'assistante la première — ne sait la relier à celle qu'elle remplace.
        $origine = $this->renouvellementResolver->origine($entity);
        if (!$cotation) {
            $urgenceSeule = $this->getUrgenceEcheance($entity, $renouvellement);

            return $this->nonRenouvelableIndicateurs($entity) + [
                'statutRenouvellement' => $renouvellement['statut'],
                'suiteDeLaPolice' => $renouvellement['phrase'],
                'origineDeLaPolice' => $origine['phrase'] ?? null,
                'hasPisteDerivee' => $entity->getPisteDeRenouvellement() !== null,
                'pisteDeriveeLibelle' => $entity->getPisteDeRenouvellement() !== null ? 'Piste dérivée' : null,
                'dureeCouverture' => $this->calculateDureeCouvertureAvenant($entity),
                'joursRestants' => $this->calculateJoursRestantsAvenant($entity),
                'urgenceEcheance' => $urgenceSeule['libelle'],
                'urgenceEcheanceNiveau' => $urgenceSeule['niveau'],
                'ageAvenant' => $this->calculateAgeAvenant($entity),
                'typeAffaire' => $this->getAvenantTypeAffaire($entity),
                'periodeCouverture' => $this->getAvenantPeriodeCouverture($entity),
                'clientDescription' => 'N/A',
                'risqueDescription' => 'N/A',
                'risqueCode' => 'N/A',
                'titrePrincipal' => ($entity->getReferencePolice() ?? 'N/A'),
            ];
        }

        // PERF : chaque getCotation*() ci-dessous parcourt le graphe revenus/tranches/
        // chargements de la cotation et déclenche ses propres requêtes. L'implémentation
        // précédente rappelait plusieurs de ces méthodes avec des arguments IDENTIQUES
        // (jusqu'à 4 fois pour getCotationPartenaire, 3 fois pour la commission TTC et
        // la rétrocommission), soit ~18 parcours redondants PAR LIGNE de liste.
        // On les évalue donc une seule fois ici. Attention en modifiant ce bloc : les
        // soldes soustraient les valeurs NON arrondies avant d'arrondir le résultat,
        // ce qui n'est pas équivalent à soustraire les valeurs déjà arrondies.
        $pisteDerivee = $entity->getPisteDeRenouvellement();
        $urgence      = $this->getUrgenceEcheance($entity, $renouvellement);
        $piste        = $cotation->getPiste();
        $tauxSP       = $this->calculationHelper->getCotationTauxSP($cotation);

        $primeTotale = $this->calculationHelper->getCotationMontantPrimePayableParClient($cotation);
        $primePayee  = $this->calculationHelper->getCotationMontantPrimePayableParClientPayee($cotation);

        $commissionHt        = $this->calculationHelper->getCotationMontantCommissionHt($cotation, -1, false);
        $commissionTtc       = $this->calculationHelper->getCotationMontantCommissionTtc($cotation, -1, false);
        $commissionEncaissee = $this->calculationHelper->getCotationMontantCommissionEncaissee($cotation);
        $commissionPure      = $this->calculationHelper->getCotationMontantCommissionPure($cotation, -1, false);

        $taxeCourtier      = $this->calculationHelper->getCotationMontantTaxeCourtier($cotation, false);
        $taxeCourtierPayee = $this->calculationHelper->getCotationMontantTaxeCourtierPayee($cotation);
        $taxeAssureur      = $this->calculationHelper->getCotationMontantTaxeAssureur($cotation, false);
        $taxeAssureurPayee = $this->calculationHelper->getCotationMontantTaxeAssureurPayee($cotation);

        // Le partenaire conditionne les trois indicateurs de rétrocommission ET la réserve :
        // on ne paie le parcours du graphe des rétrocommissions que s'il existe.
        $partenaire   = $this->calculationHelper->getCotationPartenaire($cotation);
        $retro        = $partenaire ? $this->calculationHelper->getCotationMontantRetrocommissionsPayableParCourtier($cotation, null, -1) : 0.0;
        $retroReverse = $partenaire ? $this->calculationHelper->getCotationMontantRetrocommissionsPayableParCourtierPayee($cotation, null) : 0.0;

        // Rétrocommission des AGENTS INTERNES : second bénéficiaire du partage, sur une
        // assiette différente (ce qui reste au cabinet après les partenaires). Elle pèse
        // sur la réserve exactement comme la rétro externe.
        $retroAgent        = $this->calculationHelper->getAvenantMontantRetroAgent($entity);
        $retroAgentReverse = $this->calculationHelper->getAvenantMontantRetroAgentReversee($entity);
        $retroAgentExigible = $this->calculationHelper->getAvenantRetroAgentExigible($entity);

        $montantsBordereau = $this->calculationHelper->getAvenantMontantsBordereau($entity);

        return $this->nonRenouvelableIndicateurs($entity) + [
            // Indicateurs de base de l'avenant
            'statutRenouvellement' => $renouvellement['statut'],
            'suiteDeLaPolice' => $renouvellement['phrase'],
            'origineDeLaPolice' => $origine['phrase'] ?? null,
            'hasPisteDerivee' => $pisteDerivee !== null,
            'pisteDeriveeLibelle' => $pisteDerivee !== null ? 'Piste dérivée' : null,
            'dureeCouverture' => $this->calculateDureeCouvertureAvenant($entity),
            'joursRestants' => $this->calculateJoursRestantsAvenant($entity),
            'urgenceEcheance' => $urgence['libelle'],
            'urgenceEcheanceNiveau' => $urgence['niveau'],
            'ageAvenant' => $this->calculateAgeAvenant($entity),
            'typeAffaire' => $this->getAvenantTypeAffaire($entity),
            'periodeCouverture' => $this->getAvenantPeriodeCouverture($entity),
            'clientDescription' => $this->calculationHelper->getClientDescriptionFromCotation($cotation),
            'risqueDescription' => $this->calculationHelper->getRisqueDescriptionFromCotation($cotation),
            'risqueCode' => $piste?->getRisque()?->getCode() ?? 'N/A',
            'titrePrincipal' => ($entity->getReferencePolice() ?? 'N/A') . ' • ' . ($piste?->getClient()?->getNom() ?? 'N/A'),

            // Indicateurs hérités de la Cotation parente
            'contextePiste' => $this->calculationHelper->getCotationContextePiste($cotation),
            'indemnisationDue' => round($this->calculationHelper->getCotationIndemnisationDue($cotation), 2),
            'indemnisationVersee' => round($this->calculationHelper->getCotationIndemnisationVersee($cotation), 2),
            'indemnisationSolde' => round($this->calculationHelper->getCotationIndemnisationSolde($cotation), 2),
            'tauxSP' => $tauxSP,
            'tauxSPInterpretation' => $this->calculationHelper->getCotationTauxSPInterpretation($cotation),
            'dateDernierReglement' => $this->calculationHelper->getCotationDateDernierReglement($cotation),
            'vitesseReglement' => $this->calculationHelper->getCotationVitesseReglement($cotation),
            'nombreTranches' => $this->calculationHelper->calculateNombreTranches($cotation),
            'montantMoyenTranche' => $this->calculationHelper->calculateMontantMoyenTranche($cotation),
            'primeTotale' => round($primeTotale, 2),
            'primePayee' => round($primePayee, 2),
            'primeSoldeDue' => round($primeTotale - $primePayee, 2),
            // Ce que les bordereaux de production ont réclamé à l'assureur SUR CETTE POLICE,
            // et ce qui est effectivement rentré dessus. Valeurs DÉRIVÉES des lignes
            // d'analyse : rien n'est stocké sur l'avenant, qui peut figurer dans plusieurs
            // bordereaux successifs — un champ propre serait écrasé et perdrait l'historique.
            'commissionReclameeParBordereau' => $montantsBordereau['reclame'],
            'commissionEncaisseeParBordereau' => $montantsBordereau['encaisse'],
            'tauxCommission' => $tauxSP, // Ancienne implémentation pour éviter régression
            'montantHT' => round($commissionHt, 2),
            'montantTTC' => round($commissionTtc, 2),
            'detailCalcul' => "Basé sur la cotation associée",
            'taxeCourtierMontant' => round($taxeCourtier, 2),
            'taxeAssureurMontant' => round($taxeAssureur, 2),
            'montant_du' => round($commissionTtc, 2),
            'montant_paye' => round($commissionEncaissee, 2),
            'solde_restant_du' => round($commissionTtc - $commissionEncaissee, 2),
            'taxeCourtierPayee' => round($taxeCourtierPayee, 2),
            'taxeCourtierSolde' => round($taxeCourtier - $taxeCourtierPayee, 2),
            'taxeAssureurPayee' => round($taxeAssureurPayee, 2),
            'taxeAssureurSolde' => round($taxeAssureur - $taxeAssureurPayee, 2),
            'montantPur' => round($commissionPure, 2),
            // CORRECTION : On utilise la méthode du helper pour obtenir le partenaire et on vérifie son existence.
            'retroCommission' => $partenaire ? round($retro, 2) : 0.0,
            'retroCommissionReversee' => $partenaire ? round($retroReverse, 2) : 0.0,
            'retroCommissionSolde' => $partenaire ? round($retro - $retroReverse, 2) : 0.0,
            'retroAgentDue' => round($retroAgent, 2),
            'retroAgentPayee' => round($retroAgentReverse, 2),
            'retroAgentSolde' => round(max(0.0, $retroAgent - $retroAgentReverse), 2),
            'retroAgentExigible' => round($retroAgentExigible, 2),
            // Réserve : formule UNIQUE du projet (App\Service\Partage\Reserve).
            'reserve' => Reserve::calculer($commissionPure, $retro, $retroAgent),
            // Le cabinet rétrocède-t-il plus qu'il ne garde ? Cumul de taux mal paramétré :
            // on l'AFFICHE plutôt que d'écrêter la réserve à zéro en silence.
            'reserveDeficitaire' => Reserve::estDeficitaire($commissionPure, $retro, $retroAgent),
        ];
    }

    private function calculateDureeCouvertureAvenant(Avenant $avenant): string
    {
        if (!$avenant->getStartingAt() || !$avenant->getEndingAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($avenant->getStartingAt(), $avenant->getEndingAt()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function calculateJoursRestantsAvenant(Avenant $avenant): string
    {
        if (!$avenant->getEndingAt()) {
            return 'N/A';
        }
        if ($avenant->getEndingAt() < new DateTimeImmutable()) {
            return 'Expiré';
        }
        $jours = $this->serviceDates->daysEntre(new DateTimeImmutable(), $avenant->getEndingAt()) ?? 0;
        return $jours . ' jour(s)';
    }

    /**
     * DÉCISION DE NON-RENOUVELLEMENT, rendue lisible partout où la police s'affiche.
     *
     * SOURCE UNIQUE des libellés : liste, bandeau du dialogue, colonne d'attributs calculés,
     * fiches et listes de l'assistante lisent tous CES valeurs. Aucun gabarit ne réécrit la
     * phrase — sinon deux surfaces finiraient par ne plus dire la même chose.
     *
     * SEUL LE BOOLÉEN GOUVERNE. Après une levée, la trace (motif, auteur, date) subsiste dans
     * les colonnes : elle alimente alors nonRenouvelableHistorique, jamais le badge. Une police
     * dont le marquage a été retiré est redevenue une police ordinaire.
     *
     * @return array{nonRenouvelableBadge: ?string, nonRenouvelableNiveau: ?string, nonRenouvelableDetail: ?string, nonRenouvelableHistorique: ?string}
     */
    private function nonRenouvelableIndicateurs(Avenant $avenant): array
    {
        $vide = [
            'nonRenouvelableBadge' => null,
            'nonRenouvelableNiveau' => null,
            'nonRenouvelableDetail' => null,
            'nonRenouvelableHistorique' => null,
        ];

        $motif  = trim((string) $avenant->getNonRenouvelableMotif());
        $auteur = $avenant->getNonRenouvelablePar()?->getNom();
        $le     = $avenant->getNonRenouvelableLe();

        if (!$avenant->isNonRenouvelable()) {
            $leve = $avenant->getNonRenouvelableLeveLe();
            if ($leve === null) {
                return $vide;
            }

            // Marquage posé puis RETIRÉ : plus de badge, mais l'historique reste consultable
            // pour qui rouvre le dossier et se demande pourquoi la police avait disparu du
            // pipeline pendant plusieurs mois.
            //
            // L'union de tableaux garde la valeur de GAUCHE sur une clé commune : la ligne
            // calculée doit donc précéder $vide, jamais l'inverse.
            return ['nonRenouvelableHistorique' => sprintf(
                'Marquage « non renouvelable » levé le %s%s%s.',
                $leve->format('d/m/Y'),
                $le !== null ? ' — décision du ' . $le->format('d/m/Y') : '',
                $motif !== '' ? sprintf(' : « %s »', $motif) : '',
            )] + $vide;
        }

        return [
            'nonRenouvelableBadge' => 'Non renouvelable',
            'nonRenouvelableNiveau' => 'faible',
            'nonRenouvelableDetail' => sprintf(
                'Non renouvelable — décidé%s%s%s',
                $le !== null ? ' le ' . $le->format('d/m/Y') : '',
                $auteur !== null && $auteur !== '' ? ' par ' . $auteur : '',
                $motif !== '' ? sprintf(' : %s', $motif) : '.',
            ),
            'nonRenouvelableHistorique' => null,
        ];
    }

    /**
     * Urgence d'échéance pour le badge de la liste (libellé + niveau CSS). Source unique des
     * seuils : AvenantEcheanceScope (mêmes bornes que le filtre SQL des chips). Un avenant sans
     * échéance renvoie des valeurs nulles → aucun badge rendu.
     *
     * POLICE AU SORT SCELLÉ : le badge d'urgence n'a plus de sens et devient NEUTRE. Afficher
     * « Expiré depuis 183 j » en rouge sur une police que la même application vient de déclarer
     * toujours couverte — et qu'elle retire des chips d'échéance — serait se contredire à
     * l'écran. Même règle que le filtre SQL du pipeline (AvenantSuccessionScope).
     *
     * @param array<string, mixed> $renouvellement sortie d'AvenantRenouvellementResolver::resoudre()
     *
     * @return array{libelle: ?string, niveau: ?string}
     */
    private function getUrgenceEcheance(Avenant $avenant, array $renouvellement): array
    {
        // Une police signalée non renouvelable n'est pas EN RETARD : c'est une décision, pas
        // un oubli. Elle perd donc l'alarme rouge « Expiré depuis N j ». Le badge dédié
        // « Non renouvelable » (nonRenouvelableBadge) prend le relais à côté, pour qu'elle
        // reste repérable en un coup d'œil — elle a quitté tous les chips sauf « Toutes ».
        if ($avenant->isNonRenouvelable()) {
            return ['libelle' => null, 'niveau' => null];
        }

        if ($avenant->getEndingAt() !== null && AvenantSuccessionScope::estScelle((int) $renouvellement['code'])) {
            return $this->badgeSortScelle($renouvellement);
        }

        $classe = AvenantEcheanceScope::classifier($avenant->getEndingAt(), new DateTimeImmutable('today'));

        return [
            'libelle' => $classe['libelle'] ?? null,
            'niveau' => $classe['niveau'] ?? null,
        ];
    }

    /**
     * Badge d'une police dont le sort est scellé : vert « en règle » quand la couverture se
     * poursuit sous un successeur (l'affaire est faite), gris neutre quand la police a pris fin
     * par décision. Le numéro du successeur est nommé : c'est ce qui permet de le rejoindre.
     *
     * @param array<string, mixed> $renouvellement
     *
     * @return array{libelle: ?string, niveau: ?string}
     */
    private function badgeSortScelle(array $renouvellement): array
    {
        $successeur = $renouvellement['avenantsIssus'][0]['id'] ?? null;
        $suffixe    = $successeur !== null ? sprintf(' · avenant #%d', $successeur) : '';

        return match ((int) $renouvellement['code']) {
            Avenant::RENEWAL_STATUS_RENEWED  => ['libelle' => 'Reprise' . $suffixe, 'niveau' => 'reglee'],
            Avenant::RENEWAL_STATUS_EXTENDED => ['libelle' => 'Prorogée' . $suffixe, 'niveau' => 'reglee'],
            default                          => ['libelle' => 'Résiliée' . $suffixe, 'niveau' => 'faible'],
        };
    }

    private function calculateAgeAvenant(Avenant $avenant): string
    {
        if (!$avenant->getCreatedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($avenant->getCreatedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function getAvenantPeriodeCouverture(Avenant $avenant): string
    {
        if ($avenant->getStartingAt() && $avenant->getEndingAt()) {
            return sprintf("Du %s au %s", $avenant->getStartingAt()->format('d/m/Y'), $avenant->getEndingAt()->format('d/m/Y'));
        }
        return 'Période incomplète';
    }

    private function getAvenantTypeAffaire(Avenant $avenant): string
    {
        $cotation = $avenant->getCotation();
        if (!$cotation) return "Indéterminé (Cotation manquante)";

        $piste = $cotation->getPiste();
        if (!$piste) return "Indéterminé (Piste manquante)";

        $client     = $piste->getClient();
        $risque     = $piste->getRisque();
        $startingAt = $avenant->getStartingAt();

        $missing = [];
        if (!$client) $missing[] = 'Client';
        if (!$risque) $missing[] = 'Risque';
        if (!$startingAt) $missing[] = 'Date d\'effet';
        if (!empty($missing)) return "Indéterminé (" . implode('/', $missing) . " manquant)";

        $entreprise = $avenant->getEntreprise();
        if ($entreprise !== null) {
            $entId = $entreprise->getId();
            if (!isset($this->typeAffaireBatch[$entId])) {
                $this->loadTypeAffaireBatch($entreprise);
            }
            $key     = $client->getId() . ':' . $risque->getId();
            $entries = $this->typeAffaireBatch[$entId][$key] ?? [];
            foreach ($entries as $entry) {
                if ($entry['id'] !== $avenant->getId() && $entry['startingAt'] < $startingAt) {
                    return "Affaire existante";
                }
            }
            return "Nouvelle affaire";
        }

        // Fallback (avenant sans entreprise directe — cas rare)
        $count = $this->cotationRepository->createQueryBuilder('c')
            ->select('count(a.id)')
            ->join('c.piste', 'p')
            ->join('c.avenants', 'a')
            ->where('p.client = :client')->setParameter('client', $client)
            ->andWhere('p.risque = :risque')->setParameter('risque', $risque)
            ->andWhere('a.id != :currentAvenantId')->setParameter('currentAvenantId', $avenant->getId())
            ->andWhere('a.startingAt < :currentStartingAt')->setParameter('currentStartingAt', $startingAt)
            ->getQuery()->getSingleScalarResult();

        return ($count > 0) ? "Affaire existante" : "Nouvelle affaire";
    }

    private function loadTypeAffaireBatch(Entreprise $entreprise): void
    {
        $rows = $this->cotationRepository->createQueryBuilder('c')
            ->select('cl.id AS clientId, r.id AS risqueId, a.id AS avenantId, a.startingAt AS startingAt')
            ->join('c.piste', 'p')
            ->join('p.invite', 'inv')
            ->join('p.client', 'cl')
            ->join('p.risque', 'r')
            ->join('c.avenants', 'a')
            ->where('inv.entreprise = :entreprise')
            ->setParameter('entreprise', $entreprise)
            ->andWhere('a.startingAt IS NOT NULL')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $key       = $row['clientId'] . ':' . $row['risqueId'];
            $map[$key][] = ['id' => (int) $row['avenantId'], 'startingAt' => $row['startingAt']];
        }
        $this->typeAffaireBatch[$entreprise->getId()] = $map;
    }

    public function getAvenantStatutRenouvellementString(?Avenant $avenant): ?string
    {
        if ($avenant === null || $avenant->getRenewalStatus() === null) {
            return "Non défini";
        }
        return match ($avenant->getRenewalStatus()) {
            Avenant::RENEWAL_STATUS_LOST => "Perdu",
            Avenant::RENEWAL_STATUS_ONCE_OFF => "Unique (sans renouvellement)",
            Avenant::RENEWAL_STATUS_RENEWED => "Renouvelé",
            Avenant::RENEWAL_STATUS_EXTENDED => "Prorogé",
            Avenant::RENEWAL_STATUS_RUNNING => "En cours",
            Avenant::RENEWAL_STATUS_RENEWING => "En renouvellement",
            Avenant::RENEWAL_STATUS_CANCELLED => "Annulé",
            default => "Inconnu",
        };
    }

    /**
     * Dérive les métriques de production à partir d'un montant TTC encaissé et des taux de taxe.
     *
     * Production HT = TTC / (1 + tauxAssureur/100)
     * Taxe assureur = HT × tauxAssureur/100
     * Taxe courtier = HT × tauxCourtier/100
     * Commission pure = HT − Taxe courtier
     */
    public static function computeProductionMetrics(
        float $productionTtc,
        float $tauxAssureur,
        float $tauxCourtier
    ): array {
        $ht = $tauxAssureur > 0.0
            ? $productionTtc / (1.0 + $tauxAssureur / 100.0)
            : $productionTtc;
        $taxeAss = round($ht * $tauxAssureur / 100.0, 2);
        $taxeCou = round($ht * $tauxCourtier / 100.0, 2);
        return [
            'taxeAssureur'   => $taxeAss,
            'taxeCourtier'   => $taxeCou,
            'commissionPure' => round($ht - $taxeCou, 2),
        ];
    }

    /**
     * Prime produite = prime TTC de l'avenant × (commission encaissée / commission TTC totale de l'avenant).
     * Représente la portion de la prime correspondant à ce qui a été effectivement facturé/encaissé.
     */
    public static function computePrimeProduite(
        float $productionTtc,
        float $commissionTtcAvenant,
        float $primeTtcAvenant
    ): float {
        if ($commissionTtcAvenant <= 0.0) return 0.0;
        return round($primeTtcAvenant * ($productionTtc / $commissionTtcAvenant), 2);
    }

    /**
     * Rétrocommission due au partenaire = taux partenaire (décimal) × assiette produite (commission pure).
     * Le taux est la valeur de Partenaire::getPart() — facteur décimal (ex: 0.15 = 15 %).
     */
    public static function computeRetrocommission(float $commissionPure, float $tauxPartenaire): float
    {
        return round($commissionPure * $tauxPartenaire, 2);
    }
}