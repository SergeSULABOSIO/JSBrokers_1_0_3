<?php

namespace App\Services\Canvas\Indicator;

use App\Service\Partage\RattachementDuPartage;
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
        private EntityManagerInterface $em,
        // LE VOYANT « effort commercial » : une seule autorité le calcule, pour les
        // quatre écrans de l'arbre d'une affaire.
        private RattachementDuPartage $rattachement,
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
        // LE DÛ ET LE VERSÉ DE L'AGENT, calculés UNE fois : le solde est leur différence,
        // et rappeler les deux méthodes pour l'obtenir aurait fait deux parcours de plus
        // par ligne de liste — le genre de N+1 qui ne se voit qu'à soixante lignes.
        $montantRetroAgent = round($this->getTrancheRetroAgent($entity), 2);
        $retroAgentReversee = round($this->calculationHelper->getTrancheMontantRetroAgentReversee($entity), 2);
        $commissionExigible = $this->getTrancheCommissionExigible($entity);
        return [
            // LE VOYANT DE LA LIGNE, et le drapeau des actions de partage : une seule
            // valeur pour une seule information — deux champs finiraient par se
            // contredire. `null` pour une affaire du cabinet seul, qui est le cas normal.
            // LE VOYANT DU PARTAGE et les deux drapeaux de ses actions, en UNE traversée.
            // Un seul voyant nomme les deux familles : une affaire peut être APPORTÉE par
            // un partenaire et TRAVAILLÉE par un agent, et deux colonnes auraient été vides
            // l'une comme l'autre sur la plupart des lignes.
            ...$this->rattachement->indicateurs($entity),
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
            'retroAgentDue' => $montantRetroAgent,
            // LE VERSÉ D'UNE ÉCHÉANCE, en clair sur ses reversements. Il n'existait pas :
            // un reversement était rattaché à un AVENANT, et cette colonne était donc
            // indérivable. Depuis que le versement s'enregistre par tranche — le rythme
            // réel des paiements de prime et de commission — elle est EXACTE, et ce n'est
            // pas un prorata : c'est la somme des faits portés par cette échéance.
            'retroAgentReversee' => $retroAgentReversee,
            'retroAgentSolde' => round($montantRetroAgent - $retroAgentReversee, 2),
            // La part RÉCLAMABLE de cette échéance : le dû, une fois SA commission
            // encaissée. Même rythme que la rétro partenaire ci-dessus.
            'retroAgentExigible' => round($this->calculationHelper->getTrancheRetroAgentExigible($entity), 2),
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
            'primePayeeLe' => $this->getTranchePrimePayeeLe($entity),
            'primePayeeOrigine' => $this->getTranchePrimePayeeOrigine($entity),

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
     * Seul le DÛ est proratisé — et cela reste vrai. Ce commentaire disait en revanche que
     * le « versé » n'avait pas sa place ici, « un reversement étant un fait rattaché à un
     * AVENANT, pas une grandeur qu'on découpe ». Le raisonnement était juste, la prémisse
     * ne l'est plus : le versement s'enregistre désormais à la maille de la TRANCHE, parce
     * que c'est par échéance que la prime et la commission sont payées, et donc à ce rythme
     * que l'intermédiaire est rémunéré.
     *
     * Le versé d'une échéance n'est donc toujours PAS un découpage : c'est la SOMME des
     * faits qu'elle porte. Le dû est proratisé, le versé est constaté — les deux se lisent
     * maintenant au même endroit, ce qui est la seule façon d'en tirer un solde.
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
        // LA FORMULE VIT DANS LE HELPER, aux côtés de son miroir agent. Elle était privée
        // ici, donc invisible des agrégats : la rubrique Intermédiaires ne pouvait pas
        // dire à un partenaire ce qui lui est exigible. Sans partenaire cible, la réponse
        // est celle de cet écran — l'échéance, tous bénéficiaires confondus.
        return $this->calculationHelper->getTrancheRetroExigible($tranche);
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
        return $this->calculationHelper->getTrancheMontantCommissionPartageableEncaissee($tranche);
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

    /**
     * QUAND LA PRIME A ÉTÉ RÉGLÉE — la date qui manquait à tout le monde.
     *
     * ── L'INCIDENT DU 2026-09-25, ET POURQUOI CET INDICATEUR EXISTE ──────────────────
     * Un courtier demande à l'assistant : « quelle prime le client a-t-il payée, quel
     * jour, et combien de jours se sont écoulés depuis ? ». L'écran, lui, affichait la
     * tranche en « Prime payée, commission due », et le signalement — 102 $, réglés le
     * 20/09/2026 — figurait noir sur blanc dans le formulaire d'édition de la tranche.
     * L'assistant a pourtant répondu qu'« aucune date de paiement n'est associée à cette
     * transaction dans les données », puis a conseillé d'aller vérifier la saisie.
     *
     * Ce n'était ni une hallucination ni un défaut de droits : les outils qui raisonnent
     * à la maille de la TRANCHE (suivi des impayés, vigie des échéances, économie de la
     * tranche) rendaient le statut, la prime payée et la commission exigible — et pas une
     * seule date. La date existait, à quelques lignes d'ici, dans
     * `getTrancheDateDernierEncaissement` : posée sur la même entité, dans le même
     * processus, par la même passe de calcul. Personne ne la NOMMAIT.
     *
     * C'est la troisième occurrence du même défaut (cf. les incidents des 2026-08-10 et
     * 2026-08-11) et la règle est toujours la même : le modèle n'a que deux issues devant
     * une information absente du résultat — la taire, ou l'inventer. Ici il l'a tue, et
     * a mis en doute une saisie parfaitement correcte.
     *
     * ── CE QUE CETTE DATE EST, ET CE QU'ELLE N'EST PAS ──────────────────────────────
     * C'est la date du DERNIER fait qui établit le règlement de la prime par l'assuré —
     * jamais une date de commission. Trois sources, dans l'ordre de certitude, miroir
     * exact des branches de `getTranchePrimePayee` :
     *   1. le dernier encaissement d'une note CLIENT ou un paiement de prime SIGNALÉ
     *      (tous deux datés à la main : ce sont des faits déclarés) ;
     *   2. à défaut, la réception du BORDEREAU de production qui réconcilie la police —
     *      l'assureur y déclare détenir la prime. C'est une date d'ATTESTATION, pas de
     *      règlement : `primePayeeOrigine` le dit, pour que la nuance voyage avec elle.
     *
     * `null` tant que la prime n'est pas réputée payée : une date sans paiement serait
     * pire que pas de date.
     */
    private function getTranchePrimePayeeLe(Tranche $tranche): ?\DateTimeInterface
    {
        if (round($this->calculationHelper->getTranchePrimePayee($tranche), 2) <= 0) {
            return null;
        }

        // Les faits DATÉS À LA MAIN d'abord : encaissement d'une note client, signalement
        // de paiement de prime. Ce sont eux que le courtier a sous les yeux.
        if (($date = $this->getTrancheDateDernierEncaissement($tranche)) !== null) {
            return $date;
        }

        // À défaut, l'attestation de l'assureur. On retient la PLUS RÉCENTE : c'est celle
        // à partir de laquelle la prime est, sans conteste, entre ses mains.
        $attestation = null;
        foreach ($this->calculationHelper->getBordereauxAttestantTranche($tranche) as $bordereau) {
            // Réception d'abord (le jour où le cabinet a eu la déclaration en main), fin de
            // période ensuite. Le Bordereau ne porte pas de date de création : pas de
            // troisième repli, et une attestation sans date reste sans date.
            $date = $bordereau->getReceivedAt() ?? $bordereau->getPeriodeFin();
            if ($date !== null && (!$attestation || $date > $attestation)) {
                $attestation = $date;
            }
        }

        return $attestation;
    }

    /**
     * D'OÙ VIENT CETTE DATE — parce qu'un « payée le 20/09 » qui serait en réalité la
     * réception d'un bordereau ne vaut pas un reçu, et que l'assistant comme le courtier
     * doivent pouvoir faire la différence sans ouvrir la fiche.
     *
     * `N/A` (et non une chaîne vide) quand la prime n'est pas réputée payée : c'est la
     * valeur que les projections de l'assistant écartent déjà d'office.
     */
    private function getTranchePrimePayeeOrigine(Tranche $tranche): string
    {
        if (round($this->calculationHelper->getTranchePrimePayee($tranche), 2) <= 0) {
            return 'N/A';
        }

        $origines = [];
        if (!$tranche->getPaiementsPrime()->isEmpty()) {
            $origines[] = 'paiement de prime signalé';
        }
        foreach ($tranche->getArticles() as $article) {
            $note = $article->getNote();
            if ($note && $note->getAddressedTo() === Note::TO_CLIENT && $this->calculationHelper->getNoteMontantPaye($note) > 0) {
                $origines[] = 'facture client encaissée';
                break;
            }
        }
        if ($origines === [] && $this->calculationHelper->isTrancheCommissionAssureurSoldee($tranche)) {
            $origines[] = "commission reversée par l'assureur";
        }
        if ($origines === [] && $this->calculationHelper->isTrancheCouverteParBordereau($tranche)) {
            $origines[] = 'bordereau de production réconcilié';
        }

        return $origines === [] ? 'N/A' : implode(' + ', $origines);
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