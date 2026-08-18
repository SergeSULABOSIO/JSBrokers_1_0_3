<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Tranche;
use App\Entity\Taxe;
use App\Entity\Note;
use App\Repository\TaxeRepository;
use App\Service\Partage\Reserve;
use App\Services\ServiceDates;
use App\Entity\Entreprise;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\ServiceMonnaies;
use Symfony\Contracts\Service\ResetInterface;

class TrancheIndicatorStrategy implements IndicatorCalculationStrategyInterface, ResetInterface
{
    /**
     * Le paramétrage fiscal de l'entreprise, mémorisé le temps d'une requête.
     *
     * POURQUOI. Quatre lectures de la table `taxe` étaient émises PAR TRANCHE (les deux
     * taux, puis les deux noms d'autorité) — un findOneBy touche la base même quand
     * l'entité est déjà dans l'identity map. Sur une page de vingt tranches, cela faisait
     * quatre-vingts requêtes pour lire deux lignes de configuration qui ne changent pas
     * pendant la requête. Mesuré depuis l'outil paiements_prime, qui hydrate désormais les
     * tranches de sa page : cinq requêtes marginales par tranche, dont ces quatre.
     *
     * @var array<string, Taxe|null> "id d'entreprise:redevable" => taxe (null mémorisé aussi)
     */
    private array $taxeCache = [];

    public function __construct(
        private ServiceDates $serviceDates,
        private ServiceMonnaies $serviceMonnaies,
        private TaxeRepository $taxeRepository,
        private IndicatorCalculationHelper $calculationHelper,
        private EntityManagerInterface $em
    ) {
    }

    /** Le conteneur vide ce cache à chaque requête : la configuration peut changer entre deux. */
    public function reset(): void
    {
        $this->taxeCache = [];
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Tranche::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Tranche $entity */

        // Initialisation forcée si l'objet est un Proxy non chargé
        $this->em->initializeObject($entity);
        if ($entity->getCotation()) {
            $this->em->initializeObject($entity->getCotation());
        }

        $cotation = $entity->getCotation();
        
        $isBound = $this->calculationHelper->isCotationBound($cotation);

        $nomComplet = $entity->getNom() ?? 'Tranche sans nom';
        if ($isBound) {
            $refPolice = $this->calculationHelper->getCotationReferencePolice($cotation);
            $risqueCode = $cotation?->getPiste()?->getRisque()?->getCode() ?? 'N/A';
            $assureurNom = $cotation?->getAssureur()?->getNom() ?? 'N/A';

            // Format: Tranche n°1 - Police: 124578... - RC Auto / SFA
            $nomComplet = sprintf('%s - Police: %s - %s / %s', $nomComplet, $refPolice, $risqueCode, $assureurNom);
        } else {
            $nomComplet .= ' (Projet)';
        }

        $montantCommissionTTC = round($this->getTrancheMontantTTC($entity), 2);
        $montantTaxeCourtier = round($this->getTrancheTaxeCourtierMontant($entity), 2);
        $montantTaxeAssureur = round($this->getTrancheTaxeAssureurMontant($entity), 2);
        $montantRetroCommission = round($this->getTrancheRetroCommission($entity), 2);
        $monnaieCode = $this->serviceMonnaies->getCodeMonnaieAffichage();
        $urgence = $this->getTrancheUrgence($entity);
        $retroExigible = $this->getTrancheRetroExigible($entity);
        $commissionExigible = $this->getTrancheCommissionExigible($entity);
        return [
            'nomCompletAvecStatut' => $nomComplet,
            'clientDescription' => $this->calculationHelper->getClientDescriptionFromCotation($cotation),
            'risqueDescription' => $this->calculationHelper->getRisqueDescriptionFromCotation($cotation),
            'ageTranche' => $this->calculateTrancheAge($entity),
            'joursRestantsAvantEcheance' => $this->calculateTrancheJoursRestants($entity),
            'contexteParent' => $cotation ? (string) $cotation : 'N/A',
            'pourcentageAffiche' => $this->getTrancheTauxDisplay($entity),
            'clientNom' => $cotation?->getPiste()?->getClient()?->getNom() ?? 'N/A',
            'cotationNom' => $cotation?->getNom() ?? 'N/A',
            'referencePolice' => $cotation ? $this->calculationHelper->getCotationReferencePolice($cotation) : 'N/A',
            'periodeCouverture' => $cotation ? $this->calculationHelper->getCotationPeriodeCouverture($cotation) : 'N/A',
            'assureurNom' => $cotation?->getAssureur()?->getNom() ?? 'N/A',
            'primeTranche' => round($this->getTranchePrime($entity), 2),
            'primePayee' => round($this->calculationHelper->getTranchePrimePayee($entity), 2),
            'primeSoldeDue' => round($this->getTranchePrime($entity) - $this->calculationHelper->getTranchePrimePayee($entity), 2),
            'tauxTranche' => $this->getTrancheTauxDisplay($entity),
            'montantCalculeHT' => round($this->getTrancheMontantHT($entity), 2), // Maintenu pour compatibilité
            'montantCalculeTTC' => $montantCommissionTTC,
            'descriptionCalcul' => $this->getTrancheDescriptionCalcul($entity),
            'taxeCourtierMontant' => $montantTaxeCourtier,
            'taxeCourtierTaux' => $this->getTrancheTaxeCourtierTaux($entity),
            'taxeAssureurMontant' => $montantTaxeAssureur,
            'taxeAssureurTaux' => $this->getTrancheTaxeAssureurTaux($entity),
            'montant_du' => $montantCommissionTTC,
            'montant_paye' => round($this->calculationHelper->getTrancheMontantCommissionEncaissee($entity), 2),
            'solde_restant_du' => $montantCommissionTTC - round($this->calculationHelper->getTrancheMontantCommissionEncaissee($entity), 2),
            'taxeCourtierPayee' => round($this->calculationHelper->getTrancheMontantTaxePayee($entity, false), 2),
            'taxeCourtierSolde' => $montantTaxeCourtier - round($this->calculationHelper->getTrancheMontantTaxePayee($entity, false), 2),
            'taxeAssureurPayee' => round($this->calculationHelper->getTrancheMontantTaxePayee($entity, true), 2),
            'taxeAssureurSolde' => $montantTaxeAssureur - round($this->calculationHelper->getTrancheMontantTaxePayee($entity, true), 2),
            'estPartageable' => $this->getTrancheEstPartageable($entity),
            'montantPur' => round($this->getTrancheMontantPur($entity), 2),
            'partPartenaire' => $this->getTranchePartPartenaire($entity),
            'retroCommission' => $montantRetroCommission,
            'retroCommissionReversee' => round($this->calculationHelper->getTrancheMontantRetrocommissionsPayableParCourtierPayee($entity), 2),
            'retroCommissionSolde' => $montantRetroCommission - round($this->calculationHelper->getTrancheMontantRetrocommissionsPayableParCourtierPayee($entity), 2),
            // Réserve : formule UNIQUE du projet (App\Service\Partage\Reserve), jamais
            // réécrite sur place — c'est ce qui garantit que la tranche, l'avenant, le
            // revenu et les agrégats globaux répondent tous la même chose.
            // Rétrocommission des AGENTS INTERNES, au prorata de la tranche — même
            // traitement que la rétro partenaire, dont elle partage la maille.
            'retroAgentDue' => round($this->getTrancheRetroAgent($entity), 2),
            // Termes NON arrondis : l'arrondi est celui du résultat, une seule fois. Passer
            // ici la rétro déjà arrondie déplacerait la réserve d'un centime sur certaines
            // affaires — une régression invisible et impossible à expliquer au courtier.
            'reserve' => Reserve::calculer(
                $this->getTrancheMontantPur($entity),
                $this->getTrancheRetroCommission($entity),
                $this->getTrancheRetroAgent($entity),
            ),
            'statutPaiement' => $this->getTrancheStatutPaiement($entity),
            'urgenceRecouvrement' => $urgence['libelle'],
            'urgenceNiveau' => $urgence['niveau'],
            'retroCommissionExigible' => $retroExigible,
            'retroAPayerAffiche' => $retroExigible > 0
                ? sprintf('Rétro partenaire à payer · %s %s', number_format($retroExigible, 2, ',', ' '), $monnaieCode)
                : '',
            'commissionExigible' => $commissionExigible,
            'commissionExigibleAffiche' => $commissionExigible > 0
                ? sprintf('Commission exigible · %s %s', number_format($commissionExigible, 2, ',', ' '), $monnaieCode)
                : '',
            'primeDeclareePayee' => round($this->calculationHelper->getTranchePrimeDeclareePayee($entity), 2),
            'tauxAvancement' => $this->getTrancheTauxAvancement($entity),
            'resteAPayer' => round($this->getTranchePrime($entity) - $this->calculationHelper->getTranchePrimePayee($entity), 2),
            'retardPaiement' => $this->getTrancheRetardPaiement($entity),
            'dateDernierEncaissement' => $this->getTrancheDateDernierEncaissement($entity),

            // Nouveaux indicateurs pour l'affichage en liste
            'taxeCourtierAffichee' => sprintf('%s (%s %s)', $this->getTaxeAutoriteNom($entity, Taxe::REDEVABLE_COURTIER), number_format($montantTaxeCourtier, 2), $monnaieCode),
            'taxeAssureurAffichee' => sprintf('%s (%s %s)', $this->getTaxeAutoriteNom($entity, Taxe::REDEVABLE_ASSUREUR), number_format($montantTaxeAssureur, 2), $monnaieCode),
            'commissionTTCAffichee' => sprintf('Com TTC (%s %s)', number_format($montantCommissionTTC, 2), $monnaieCode),
            'retroCommissionAffichee' => sprintf('RétroCom (%s %s)', number_format($montantRetroCommission, 2), $monnaieCode),
        ];
    }

    private function calculateTrancheAge(Tranche $tranche): string
    {
        if (!$tranche->getCreatedAt()) return 'N/A';
        $jours = $this->serviceDates->daysEntre($tranche->getCreatedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function calculateTrancheJoursRestants(Tranche $tranche): string
    {
        if (!$tranche->getEcheanceAt()) return 'N/A';
        $now = new DateTimeImmutable();
        if ($tranche->getEcheanceAt() < $now) return 'Échue';
        $jours = $this->serviceDates->daysEntre($now, $tranche->getEcheanceAt()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function calculateTrancheTauxFactor(Tranche $tranche): float
    {
        return $this->calculationHelper->getTrancheTauxFactor($tranche);
    }

    private function getTrancheTauxDisplay(Tranche $tranche): float
    {
        return $this->calculateTrancheTauxFactor($tranche) * 100;
    }

    private function getTrancheDescriptionCalcul(Tranche $tranche): string
    {
        if ($tranche->getPourcentage() !== null && $tranche->getPourcentage() > 0) {
            return "Basé sur le taux défini de " . $this->getTrancheTauxDisplay($tranche) . "%";
        }
        if ($tranche->getMontantFlat() !== null && $tranche->getMontantFlat() > 0) {
            return "Calculé : Montant fixe (" . $tranche->getMontantFlat() . ") / Prime Totale";
        }
        return "Taux non défini (0%)";
    }

    private function getTranchePrime(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $primeTotale = $this->calculationHelper->getCotationMontantPrimePayableParClient($tranche->getCotation());
        return $primeTotale * $taux;
    }

    private function getTrancheMontantHT(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $cotationHT = $this->calculationHelper->getCotationMontantCommissionHt($tranche->getCotation(), -1, false);
        return $cotationHT * $taux;
    }

    private function getTrancheMontantTTC(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $cotationTTC = $this->calculationHelper->getCotationMontantCommissionTtc($tranche->getCotation(), -1, false);
        return $cotationTTC * $taux;
    }

    private function getTrancheTaxeCourtierMontant(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $cotationTaxe = $this->calculationHelper->getCotationMontantTaxeCourtier($tranche->getCotation(), false);
        return $cotationTaxe * $taux;
    }

    private function getTrancheTaxeAssureurMontant(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $cotationTaxe = $this->calculationHelper->getCotationMontantTaxeAssureur($tranche->getCotation(), false);
        return $cotationTaxe * $taux;
    }

    private function getTrancheTaxeCourtierTaux(Tranche $tranche): float
    {
        return $this->getTrancheTaxeTaux($tranche, Taxe::REDEVABLE_COURTIER);
    }

    private function getTrancheTaxeAssureurTaux(Tranche $tranche): float
    {
        return $this->getTrancheTaxeTaux($tranche, Taxe::REDEVABLE_ASSUREUR);
    }

    /** Taux en POINTS (16 = 16 %) de la taxe SUR LA COMMISSION due par ce redevable. */
    private function getTrancheTaxeTaux(Tranche $tranche, int $redevable): float
    {
        $taxe = $this->getTaxe($tranche, $redevable);
        if (!$taxe) return 0.0;
        $isIARD = $this->calculationHelper->isIARD($tranche->getCotation());
        $rate = $isIARD ? $taxe->getTauxIARD() : $taxe->getTauxVIE();
        return (float)($rate ?? 0.0);
    }

    /**
     * La taxe paramétrée pour ce redevable dans l'entreprise de la tranche — lue UNE fois
     * par entreprise et par redevable, pas une fois par tranche (cf. $taxeCache).
     */
    private function getTaxe(Tranche $tranche, int $redevable): ?Taxe
    {
        $entreprise = $tranche->getCotation()?->getPiste()?->getInvite()?->getEntreprise();
        $cle = ($entreprise instanceof Entreprise ? (string) $entreprise->getId() : '') . ':' . $redevable;

        // array_key_exists, jamais ?? : une entreprise SANS taxe paramétrée mémorise null,
        // et un `??=` reposerait la question à chaque tranche — le cas le plus coûteux
        // serait alors celui qui n'a rien à lire.
        if (!array_key_exists($cle, $this->taxeCache)) {
            $this->taxeCache[$cle] = $this->taxeRepository->findOneBy(
                ['redevable' => $redevable, 'entreprise' => $entreprise],
            );
        }

        return $this->taxeCache[$cle];
    }

    private function getTrancheEstPartageable(Tranche $tranche): string
    {
        $cotation = $tranche->getCotation();
        if ($cotation) {
            foreach ($cotation->getRevenus() as $revenu) {
                if ($revenu->getTypeRevenu() && $revenu->getTypeRevenu()->isShared()) {
                    return 'Oui';
                }
            }
        }
        return 'Non';
    }

    private function getTrancheMontantPur(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $cotationPure = $this->calculationHelper->getCotationMontantCommissionPure($tranche->getCotation(), -1, false);
        return $cotationPure * $taux;
    }

    private function getTranchePartPartenaire(Tranche $tranche): float
    {
        $partenaire = $this->calculationHelper->getCotationPartenaire($tranche->getCotation());
        return $partenaire ? ($partenaire->getPart() ?? 0.0) : 0.0;
    }

    /**
     * Rétrocommission DUE aux agents internes, ramenée à la quote-part de la tranche.
     *
     * Seul le DÛ est proratisé. Le « versé », lui, ne l'est pas et n'apparaît pas ici :
     * un reversement est un fait rattaché à un AVENANT (un virement réel, daté, référencé),
     * pas une grandeur qu'on découpe. L'écran qui répond « combien lui reste-t-il dû ? »
     * est la fiche de l'avenant et le rapport de production, jamais la tranche.
     */
    private function getTrancheRetroAgent(Tranche $tranche): float
    {
        return $this->calculationHelper->getCotationMontantRetroAgent($tranche->getCotation())
            * $this->calculateTrancheTauxFactor($tranche);
    }

    private function getTrancheRetroCommission(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $cotationRetro = $this->calculationHelper->getCotationMontantRetrocommissionsPayableParCourtier($tranche->getCotation(), null, -1);
        return $cotationRetro * $taux;
    }

    /**
     * Statut de règlement combiné : une tranche n'est « Payée » que si la prime client
     * est encaissée ET la commission collectée. Les deux dettes ont des débiteurs
     * différents (client / assureur), leurs soldes ne se compensent donc jamais.
     */
    private function getTrancheStatutPaiement(Tranche $tranche): string
    {
        // Tant que la proposition n'est pas VALIDÉE par le client (aucun avenant lié), la
        // tranche n'est qu'un PROJET : elle ne compte pas encore et ne fait l'objet d'AUCUN
        // suivi de recouvrement — même si sa date d'effet est atteinte ou dépassée. Le suivi
        // ne commence qu'à la concrétisation du contrat (avenant). Source unique : ce statut
        // 'N/A' exclut la tranche des filtres impayées/échues et de la vigie d'urgence.
        if (!$this->calculationHelper->isCotationBound($tranche->getCotation())) {
            return 'N/A';
        }

        $prime = round($this->getTranchePrime($tranche), 2);
        $commission = round($this->getTrancheMontantTTC($tranche), 2);

        if ($prime <= 0 && $commission <= 0) return 'N/A';

        $primePayee = round($this->calculationHelper->getTranchePrimePayee($tranche), 2);
        $commissionEncaissee = round($this->calculationHelper->getTrancheMontantCommissionEncaissee($tranche), 2);
        $primeSoldee = $primePayee >= $prime;
        $commissionSoldee = $commissionEncaissee >= $commission;

        if ($primeSoldee && $commissionSoldee) return 'Payée';
        if ($primeSoldee) return 'Prime payée, commission due';
        if ($primePayee > 0 || $commissionEncaissee > 0) return 'Partiellement payée';
        return 'Non payée';
    }

    private function getTrancheTauxAvancement(Tranche $tranche): float
    {
        $prime = $this->getTranchePrime($tranche);
        if ($prime <= 0) return 0.0;
        return round(($this->calculationHelper->getTranchePrimePayee($tranche) / $prime) * 100, 2);
    }

    private function getTrancheRetardPaiement(Tranche $tranche): string
    {
        // Solde exigible combiné : prime due par le client + commission due par l'assureur.
        // Chaque solde est plafonné à 0 (un trop-perçu ne compense pas l'autre dette).
        $soldePrime = $this->getTranchePrime($tranche) - $this->calculationHelper->getTranchePrimePayee($tranche);
        $soldeCommission = $this->getTrancheMontantTTC($tranche) - $this->calculationHelper->getTrancheMontantCommissionEncaissee($tranche);
        $solde = round(max(0, $soldePrime) + max(0, $soldeCommission), 2);
        if ($solde <= 0) return 'Non';

        $echeance = $tranche->getEcheanceAt();
        if (!$echeance) return 'N/A';

        $now = new DateTimeImmutable();
        if ($echeance < $now) {
            $jours = $this->serviceDates->daysEntre($echeance, $now);
            return "Oui (" . $jours . " jours)";
        }
        return 'Non';
    }

    /**
     * Niveau d'urgence du recouvrement (prime client et/ou commission à collecter).
     * Un recouvrement méthodique s'ANTICIPE : les échéances qui approchent sont graduées
     * avant même le retard avéré, pour maintenir le niveau d'encaissement.
     *
     * - critique : solde exigible ET échéance dépassée (retard avéré) ;
     * - elevee   : solde exigible, échéance sous 7 jours ;
     * - moderee  : solde exigible, échéance sous 30 jours ;
     * - faible   : solde exigible, échéance lointaine ou non renseignée ;
     * - reglee   : prime et commission soldées (rien à recouvrer).
     *
     * @return array{niveau: string, libelle: string} niveau '' = pas de badge (N/A).
     */
    private function getTrancheUrgence(Tranche $tranche): array
    {
        $statut = $this->getTrancheStatutPaiement($tranche);
        if ($statut === 'N/A') {
            return ['niveau' => '', 'libelle' => ''];
        }
        if ($statut === 'Payée') {
            return ['niveau' => 'reglee', 'libelle' => 'Réglée'];
        }

        $echeance = $tranche->getEcheanceAt();
        if (!$echeance) {
            return ['niveau' => 'faible', 'libelle' => 'Faible · sans échéance'];
        }

        $now = new DateTimeImmutable();
        if ($echeance < $now) {
            $jours = $this->serviceDates->daysEntre($echeance, $now) ?? 0;

            return ['niveau' => 'critique', 'libelle' => 'Critique · retard ' . $jours . ' j'];
        }

        $jours = $this->serviceDates->daysEntre($now, $echeance) ?? 0;
        if ($jours <= 7) {
            return ['niveau' => 'elevee', 'libelle' => 'Élevée · échéance J-' . $jours];
        }
        if ($jours <= 30) {
            return ['niveau' => 'moderee', 'libelle' => 'Modérée · échéance J-' . $jours];
        }

        return ['niveau' => 'faible', 'libelle' => 'Faible · échéance J-' . $jours];
    }

    /**
     * Rétrocommission partenaire EXIGIBLE : solde de rétro dû (rétro due − reversée),
     * mais seulement une fois la commission de courtage PARTAGEABLE correspondante
     * intégralement encaissée — avant cela, la dette envers le partenaire n'est pas
     * encore née. Permet au courtier de payer ses partenaires au bon moment.
     */
    private function getTrancheRetroExigible(Tranche $tranche): float
    {
        // Proposition non validée (aucun avenant) : projet, aucune dette rétro à surveiller.
        if (!$this->calculationHelper->isCotationBound($tranche->getCotation())) {
            return 0.0;
        }

        $soldeRetro = round(
            $this->getTrancheRetroCommission($tranche)
            - $this->calculationHelper->getTrancheMontantRetrocommissionsPayableParCourtierPayee($tranche),
            2
        );
        if ($soldeRetro <= 0) {
            return 0.0;
        }

        $facteur = $this->calculateTrancheTauxFactor($tranche);
        $duePartageable = round(
            $this->calculationHelper->getCotationMontantCommissionTtc($tranche->getCotation(), -1, true) * $facteur,
            2
        );
        if ($duePartageable <= 0) {
            return 0.0;
        }

        $encaisseePartageable = round($this->getTrancheMontantCommissionPartageableEncaissee($tranche), 2);

        // Circuit bordereau sans articles : un bordereau de production couvrant la
        // tranche et intégralement encaissé prouve que la commission (partageable
        // comprise) a été perçue — la dette rétro est donc née.
        if ($encaisseePartageable >= $duePartageable
            || $this->calculationHelper->isTrancheCouverteParBordereau($tranche, true)) {
            return $soldeRetro;
        }

        return 0.0;
    }

    /**
     * Commission de courtage EXIGIBLE auprès de l'assureur : solde de commission dû,
     * mais seulement une fois la prime intégralement payée par l'assuré — facturation
     * du courtier OU signalement déclaratif (PaiementPrime). Tant que l'assureur n'a
     * pas encaissé la prime, la commission n'est pas exigible, malgré sa date due.
     * Cas sans prime (affaire à honoraires purs) : la commission est exigible d'office.
     */
    private function getTrancheCommissionExigible(Tranche $tranche): float
    {
        // Proposition non validée (aucun avenant) : projet, aucune commission à recouvrer.
        if (!$this->calculationHelper->isCotationBound($tranche->getCotation())) {
            return 0.0;
        }

        $soldeCommission = round(
            $this->getTrancheMontantTTC($tranche)
            - $this->calculationHelper->getTrancheMontantCommissionEncaissee($tranche),
            2
        );
        if ($soldeCommission <= 0) {
            return 0.0;
        }

        $prime = round($this->getTranchePrime($tranche), 2);
        if ($prime <= 0) {
            return $soldeCommission;
        }

        $primePayee = round($this->calculationHelper->getTranchePrimePayee($tranche), 2);

        return $primePayee >= $prime ? $soldeCommission : 0.0;
    }

    /**
     * Commission encaissée sur les seuls revenus PARTAGEABLES de la tranche (miroir de
     * IndicatorCalculationHelper::getTrancheMontantCommissionEncaissee, filtré isShared).
     */
    private function getTrancheMontantCommissionPartageableEncaissee(Tranche $tranche): float
    {
        $montant = 0.0;
        foreach ($tranche->getArticles() as $article) {
            $note = $article->getNote();
            $revenu = $article->getRevenuFacture();
            if (!$note || !$revenu || !$revenu->getTypeRevenu()?->isShared()) {
                continue;
            }
            if (!in_array($note->getAddressedTo(), [Note::TO_ASSUREUR, Note::TO_CLIENT], true)) {
                continue;
            }
            $montantPayableNote = $this->calculationHelper->getNoteMontantPayable($note);
            if ($montantPayableNote > 0) {
                $proportionPaiement = $this->calculationHelper->getNoteMontantPaye($note) / $montantPayableNote;
                $montant += $proportionPaiement * $this->calculationHelper->getArticleMontant($article);
            }
        }

        return $montant;
    }

    private function getTrancheDateDernierEncaissement(Tranche $tranche): ?\DateTimeInterface
    {
        $lastDate = null;
        foreach ($tranche->getArticles() as $article) {
            $note = $article->getNote();
            if ($note && $note->getAddressedTo() === Note::TO_CLIENT) {
                foreach ($note->getPaiements() as $paiement) {
                    if ($paiement->getPaidAt() && (!$lastDate || $paiement->getPaidAt() > $lastDate)) {
                        $lastDate = $paiement->getPaidAt();
                    }
                }
            }
        }
        // Paiements de prime SIGNALÉS (l'assureur a encaissé — date d'information du courtier).
        foreach ($tranche->getPaiementsPrime() as $paiementPrime) {
            if ($paiementPrime->getPaidAt() && (!$lastDate || $paiementPrime->getPaidAt() > $lastDate)) {
                $lastDate = $paiementPrime->getPaidAt();
            }
        }
        return $lastDate;
    }

    private function getTaxeAutoriteNom(Tranche $tranche, int $redevable): string
    {
        if (!$tranche->getCotation()?->getPiste()?->getInvite()?->getEntreprise()) return 'N/A';

        $taxe = $this->getTaxe($tranche, $redevable);
        if (!$taxe) return 'N/A';

        $autorite = $taxe->getAutoriteFiscales()->first();
        if (!$autorite) return $taxe->getCode() ?? 'Taxe';

        // On privilégie l'abréviation si elle existe
        return $autorite->getAbreviation() ?: $autorite->getNom();
    }
}