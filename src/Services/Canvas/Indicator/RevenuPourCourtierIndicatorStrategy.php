<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\ConditionPartage;
use App\Entity\Partenaire;
use App\Entity\Piste;
use App\Entity\RevenuPourCourtier;
use App\Entity\Note;
use App\Entity\Risque;
use App\Entity\Taxe;
use App\Util\Pourcentage;
use App\Repository\TaxeRepository;
use App\Service\Partage\Reserve;
use Doctrine\ORM\EntityManagerInterface;

class RevenuPourCourtierIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private IndicatorCalculationHelper $calculationHelper,
        private TaxeRepository $taxeRepository,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RevenuPourCourtier::class;
    }

    public function calculate(object $entity): array
    {
        /** @var RevenuPourCourtier $entity */
        $montantHT = $this->calculationHelper->getRevenuMontantHt($entity);
        $taxeCourtier = $this->calculationHelper->getRevenuMontantTaxeCourtier($entity);

        // On s'assure que l'entité et sa cotation sont chargées (Proxies Doctrine)
        $this->em->initializeObject($entity);
        if ($entity->getCotation()) {
            $this->em->initializeObject($entity->getCotation());
        }

        $cotation = $entity->getCotation();
        $clientNom = $cotation?->getPiste()?->getClient()?->getNom() ?? 'N/A';
        $refPolice = $cotation ? $this->calculationHelper->getCotationReferencePolice($cotation) : 'N/A';
        $nomComplet = sprintf("%s sur Police #%s", $entity->getNom(), $refPolice);

        return [
            'nomCompletAvecStatut' => $nomComplet,
            'referencePolice' => $refPolice,
            'clientNom' => $clientNom,
            'typeRevenuNom' => $entity->getTypeRevenu()?->getNom() ?? 'N/A',
            'clientDescription' => $this->calculationHelper->getClientDescriptionFromCotation($entity->getCotation()),
            'risqueDescription' => $this->calculationHelper->getRisqueDescriptionFromCotation($entity->getCotation()),
            'montantCalculeTTC' => round($this->calculationHelper->getRevenuMontantTTC($entity), 2),
            'descriptionCalcul' => $this->getRevenuPourCourtierDescriptionCalcul($entity),
            'montant_du' => round($this->calculationHelper->getRevenuMontantTTC($entity), 2),
            'montant_paye' => round($this->getRevenuPourCourtierMontantPaye($entity), 2),
            'solde_restant_du' => round($this->calculationHelper->getRevenuMontantTTC($entity) - $this->getRevenuPourCourtierMontantPaye($entity), 2),            
            'montantPur' => round($this->calculationHelper->getRevenuMontantPure($entity), 2),
            'retroCommissionReversee' => round($this->getRevenuRetroCommissionReversee($entity), 2),
            'retroCommissionSolde' => round($this->calculationHelper->getRevenuMontantRetrocommissionsPayableParCourtier($entity, null, -1) - $this->getRevenuRetroCommissionReversee($entity), 2),
            'taxeCourtierMontant' => round($this->calculationHelper->getRevenuMontantTaxeCourtier($entity), 2),
            // Taux via le VO Pourcentage (source unique de la convention) : pourcent()
            // rend le nombre à afficher (16.0), quelle que soit la convention de stockage.
            'taxeCourtierTaux' => round($this->getTaxeTaux($entity, Taxe::REDEVABLE_COURTIER)->pourcent(), 2),
            'taxeAssureurMontant' => round($this->calculationHelper->getRevenuMontantTaxeAssureur($entity), 2),
            'taxeAssureurTaux' => round($this->getTaxeTaux($entity, Taxe::REDEVABLE_ASSUREUR)->pourcent(), 2),
            'estPartageable' => ($entity->getTypeRevenu() && $entity->getTypeRevenu()->isShared()) ? 'Oui' : 'Non',
            'taxeCourtierPayee' => round($this->getRevenuTaxePayee($entity, Taxe::REDEVABLE_COURTIER), 2),
            'taxeCourtierSolde' => round($this->calculationHelper->getRevenuMontantTaxeCourtier($entity) - $this->getRevenuTaxePayee($entity, Taxe::REDEVABLE_COURTIER), 2),
            'taxeAssureurPayee' => round($this->getRevenuTaxePayee($entity, Taxe::REDEVABLE_ASSUREUR), 2),
            'taxeAssureurSolde' => round($this->calculationHelper->getRevenuMontantTaxeAssureur($entity) - $this->getRevenuTaxePayee($entity, Taxe::REDEVABLE_ASSUREUR), 2),
            'montantCalculeHT' => $montantHT,
            // Part partenaire = FRACTION (0.35) → pourcent() pour l'affichage (via le VO).
            'partPartenaire' => round(Pourcentage::fromFraction($this->getRevenuPartPartenaire($entity))->pourcent(), 2),
            'retroCommission' => $this->calculationHelper->getRevenuMontantRetrocommissionsPayableParCourtier($entity, null, -1),
            // UNE COLONNE ANNONCÉE EST UNE COLONNE RENDUE : sans elle, la réserve baisserait
            // sans que rien n'explique où l'argent est passé.
            'retroAgentDue' => round($this->calculationHelper->getRevenuMontantRetroAgent($entity), 2),
            'reserve' => $this->getReserveCourtier($entity),
        ];
    }
    private function getRevenuPourCourtierMontantPaye(RevenuPourCourtier $revenu): float
    {
        $montantPaye = 0.0;

        foreach ($revenu->getArticles() as $article) {
            $note = $article->getNote();
            // CORRECTION : On ne comptabilise que les paiements sur les notes de commission
            // (adressées au client ou à l'assureur).
            if ($note && in_array($note->getAddressedTo(), [Note::TO_CLIENT, Note::TO_ASSUREUR])) {
                $montantPayableNote = $this->calculationHelper->getNoteMontantPayable($note);
                if ($montantPayableNote > 0) {
                    $proportionPaiement = $this->calculationHelper->getNoteMontantPaye($note) / $montantPayableNote;
                    
                    // Utilisation centralisée
                    $montantArticle = $this->calculationHelper->getArticleMontant($article);
                    $montantPaye += $proportionPaiement * $montantArticle;
                }
            }
        }
        return $montantPaye;
    }

    /**
     * Réserve : formule UNIQUE du projet (App\Service\Partage\Reserve).
     *
     * LES TROIS TERMES, PAS DEUX. Le troisième — la part des agents internes — manquait :
     * la fiche d'un revenu annonçait donc une réserve que le cabinet ne gardait pas, tandis
     * que la fiche de l'avenant, elle, la déduisait. Deux réserves cohabitaient pour le même
     * argent, et c'est la plus flatteuse qui s'affichait au plus près de la saisie.
     */
    private function getReserveCourtier(RevenuPourCourtier $revenu): float
    {
        return Reserve::calculer(
            $this->calculationHelper->getRevenuMontantPure($revenu),
            $this->calculationHelper->getRevenuMontantRetrocommissionsPayableParCourtier($revenu, null, -1),
            $this->calculationHelper->getRevenuMontantRetroAgent($revenu),
        );
    }

    private function getRevenuPourCourtierDescriptionCalcul(RevenuPourCourtier $revenu): string
    {
        $typeRevenu = $revenu->getTypeRevenu();
        if (!$typeRevenu) return "Type de revenu non défini";

        // Ces taux (exceptionnel, type de revenu, risque) sont stockés en POINTS →
        // affichage via le VO Pourcentage::fromPourcent (jamais de ×100 à la main).
        if ($revenu->getTauxExceptionel() !== null && $revenu->getTauxExceptionel() != 0) {
            return "Taux exceptionnel de " . Pourcentage::fromPourcent($revenu->getTauxExceptionel())->format(2);
        }
        if ($revenu->getMontantFlatExceptionel()) {
            return "Montant fixe exceptionnel de " . $revenu->getMontantFlatExceptionel();
        }
        if ($typeRevenu->getPourcentage() !== null && $typeRevenu->getPourcentage() != 0) {
            return "Taux par défaut de " . Pourcentage::fromPourcent($typeRevenu->getPourcentage())->format(2);
        }
        if ($typeRevenu->getMontantflat()) {
            return "Montant fixe par défaut de " . $typeRevenu->getMontantflat();
        }
        if ($typeRevenu->isAppliquerPourcentageDuRisque() && $revenu->getCotation()?->getPiste()?->getRisque()) {
            $tauxRisque = $revenu->getCotation()->getPiste()->getRisque()->getPourcentageCommissionSpecifiqueHT();
            return "Taux du risque de " . Pourcentage::fromPourcent($tauxRisque)->format(2);
        }
        return "Logique de calcul non spécifiée";
    }

    /**
     * Calcule le taux de rétrocommission (part du partenaire) pour un revenu donné.
     * Cette méthode est publique pour être réutilisable par le Helper.
     *
     * @param RevenuPourCourtier $revenu
     * @return float Le taux de partage sous forme de facteur (ex: 0.35).
     */
    public function getRevenuPartPartenaire(RevenuPourCourtier $revenu): float
    {
        // Si le revenu n'est pas partageable, le taux est 0.
        if (!$revenu->getTypeRevenu() || !$revenu->getTypeRevenu()->isShared()) {
            return 0.0;
        }

        $cotation = $revenu->getCotation();
        if (!$cotation || !$cotation->getPiste()) {
            return 0.0;
        }

        $partenaire = $this->calculationHelper->getCotationPartenaire($cotation);

        // S'il n'y a pas de partenaire associé à l'affaire, pas de partage.
        if (!$partenaire) {
            return 0.0;
        }

        $condition = $this->conditionRetenue($revenu);

        if ($condition !== null) {
            // ET SON SEUIL EST ENFIN HONORÉ. Le taux de la condition était pris tel quel :
            // `formule`, `seuil` et `uniteMesure` n'étaient consultés nulle part sur ce
            // chemin — celui de l'argent réellement versé au partenaire. Une condition
            // écrite pour ne récompenser qu'au-delà d'un volume s'appliquait donc dès le
            // premier franc. Sous le seuil, la condition ne partage RIEN : elle ne
            // retombe pas sur le taux par défaut du partenaire, qu'elle remplace.
            return $this->calculationHelper->conditionFranchitSonSeuil($condition, $revenu)
                ? $condition->getFraction()
                : 0.0;
        }

        // Aucune condition : le taux par défaut du partenaire (facteur = fraction).
        return $partenaire->getFraction();
    }

    /**
     * LA CONDITION DE PARTAGE QUI L'EMPORTE pour ce revenu — SOURCE UNIQUE de la cascade.
     *
     * Ordre, repris de l'implémentation d'origine (Constante::
     * Revenu_getMontant_retrocommissions_payable_par_courtier) : la condition portée par
     * la PISTE l'emporte ; à défaut celle portée par le PARTENAIRE ; à défaut, aucune —
     * et c'est alors le taux par défaut du partenaire qui s'applique.
     *
     * (Les conditions du partenaire ne modulaient rien : elles n'étaient jamais
     * consultées, alors que la rubrique Partenaire annonce noir sur blanc qu'elles
     * « modulent le calcul de sa rétro-commission ».)
     *
     * PUBLIQUE PARCE QU'ELLE A DEUX CONSOMMATEURS. Le second est la rubrique Condition de
     * partage, dont les indicateurs d'impact imputaient à CHAQUE condition la
     * rétrocommission qu'elle aurait produite seule — sans vérifier qu'elle l'emporte.
     * Une condition de partenaire masquée par une condition de piste annonçait donc un
     * « Total rétrocommission » et des « dossiers concernés » qui ne correspondaient à
     * aucun franc versé. Les deux passent désormais par ici.
     */
    public function conditionRetenue(RevenuPourCourtier $revenu): ?ConditionPartage
    {
        $piste = $revenu->getCotation()?->getPiste();
        if ($piste === null) {
            return null;
        }

        $partenaire = $this->calculationHelper->getCotationPartenaire($revenu->getCotation());
        $risqueActuel = $piste->getRisque();

        // ── L'ÉTAGE DU MILIEU : LA CONDITION RATTACHÉE ──────────────────────────────
        //
        // Une condition PARTAGÉE peut désormais être rattachée à des affaires choisies,
        // exactement comme celle d'un agent — « ces trois affaires-ci relèvent de l'accord
        // SUNU 20 % ». Sans cet étage, le rattachement serait écrit en base et n'aurait
        // AUCUN effet sur l'argent : le pire des silences.
        //
        // Sa place est dictée par la cascade d'origine, qu'on ne déplace pas : ce qui est
        // porté par l'AFFAIRE l'emporte sur ce qui est porté par le PARTENAIRE. Entre les
        // deux étages de l'affaire, l'exceptionnelle passe d'abord — elle a été écrite POUR
        // cette affaire-là, quand la rattachée sert aussi ailleurs.
        //
        // ⚠ ET ELLE DOIT APPARTENIR À L'INTERMÉDIAIRE DE L'AFFAIRE. Le rattachement le
        // garantit au moment du geste, mais l'intermédiaire peut CHANGER ensuite : une
        // condition rattachée qui nommerait l'ancien paierait alors le nouveau au taux du
        // précédent. `Piste::setPartenaire()` recentre les conditions PROPRES de l'affaire
        // et ne peut pas toucher aux partagées — elles servent d'autres affaires. Le filtre
        // est donc ici, à la lecture.
        return $this->premiereConditionApplicable($piste->getConditionsPartageExceptionnelles(), $risqueActuel)
            ?? $this->premiereConditionApplicable(
                $this->rattacheesDeLIntermediaire($piste, $partenaire),
                $risqueActuel,
            )
            ?? ($partenaire !== null
                ? $this->premiereConditionApplicable($partenaire->getConditionPartages(), $risqueActuel)
                : null);
    }

    /**
     * Les conditions RATTACHÉES à cette affaire qui rétrocèdent bien à son intermédiaire.
     *
     * La collection est commune aux deux familles depuis l'unification du rattachement ;
     * `premiereConditionApplicable()` écarte déjà les conditions d'agent. Ce qu'on ajoute
     * ici est le second filtre, celui que la collection ne peut pas porter : le
     * bénéficiaire nommé doit être l'intermédiaire de l'affaire du jour.
     *
     * @return array<int, ConditionPartage>
     */
    private function rattacheesDeLIntermediaire(Piste $piste, ?Partenaire $partenaire): array
    {
        if ($partenaire === null) {
            return [];
        }

        $retenues = [];
        foreach ($piste->getConditionsPartageAgent() as $condition) {
            if ($this->calculationHelper->isSamePartenaire($condition->getPartenaire(), $partenaire)) {
                $retenues[] = $condition;
            }
        }

        return $retenues;
    }

    /**
     * La première condition de la collection qui vise ce risque, ou null.
     *
     * @param iterable<ConditionPartage> $conditions
     */
    private function premiereConditionApplicable(iterable $conditions, ?Risque $risque): ?ConditionPartage
    {
        foreach ($conditions as $condition) {
            // UNE CONDITION D'AGENT NE PAIE PAS UN PARTENAIRE.
            //
            // Les deux natures de bénéficiaire cohabitent dans les conditions d'une affaire :
            // rien n'empêche d'y saisir la part d'un agent interne. Sans ce filtre, une ligne
            // « Alice 15 % » devenait candidate ICI, masquait la condition du partenaire et le
            // payait au taux d'Alice — avant d'être recomptée par la cascade agent, qui lit
            // délibérément la même collection. La même ligne alimentait deux circuits d'argent.
            //
            // Cette méthode ne sert QUE la part partenaire, et ses deux appelants (conditions
            // de la piste, conditions du partenaire) demandent la même exclusion : c'est donc
            // ici, et nulle part ailleurs, que le tri se fait.
            if ($condition->estPourAgent()) {
                continue;
            }

            // Règle d'applicabilité centralisée sur l'entité (cf. ConditionPartage::sappliqueAuRisque),
            // partagée avec la reconduction du partage sur les avenants dérivés.
            if ($condition->sappliqueAuRisque($risque)) {
                return $condition;
            }
        }

        return null;
    }

    private function getRevenuRetroCommissionReversee(RevenuPourCourtier $revenu): float
    {
        $montantPaye = 0.0;
        foreach ($revenu->getArticles() as $article) {
            $note = $article->getNote();
            if ($note && $note->getAddressedTo() === Note::TO_PARTENAIRE) {
                $montantPayableNote = $this->calculationHelper->getNoteMontantPayable($note);
                if ($montantPayableNote > 0) {
                    $proportionPaiement = $this->calculationHelper->getNoteMontantPaye($note) / $montantPayableNote;
                    
                    // Calcul robuste
                    $montantArticle = $this->calculationHelper->getArticleMontant($article);
                    $montantPaye += $proportionPaiement * $montantArticle;
                }
            }
        }
        return $montantPaye;
    }

    private function getTaxeTaux(RevenuPourCourtier $revenu, int $redevable): Pourcentage
    {
        $isIARD = $this->calculationHelper->isIARD($revenu->getCotation()); // ex: true
        $entreprise = $revenu->getTypeRevenu()?->getEntreprise();
        // Fallback si le type de revenu n'a pas d'entreprise (ex: création dynamique)
        if (!$entreprise) $entreprise = $revenu->getCotation()?->getPiste()?->getInvite()?->getEntreprise();
        $taxe = $this->taxeRepository->findOneBy(['redevable' => $redevable, 'entreprise' => $entreprise]);

        return $taxe ? $taxe->tauxPourcentage($isIARD) : Pourcentage::zero();
    }

    private function getRevenuTaxePayee(RevenuPourCourtier $revenu, int $targetRedevable): float
    {
        $montantPaye = 0.0;

        foreach ($revenu->getArticles() as $article) {
            $note = $article->getNote();
            if ($note && $note->getAddressedTo() === Note::TO_AUTORITE_FISCALE) {
                $taxe = $note->getAutoritefiscale()?->getTaxe();
                if ($taxe && $taxe->getRedevable() === $targetRedevable) {
                    $montantPayableNote = $this->calculationHelper->getNoteMontantPayable($note);
                    if ($montantPayableNote > 0) {
                        $proportionPaiement = $this->calculationHelper->getNoteMontantPaye($note) / $montantPayableNote;
                        $montantArticle = $this->calculationHelper->getArticleMontant($article);
                        $montantPaye += $proportionPaiement * $montantArticle;
                    }
                }
            }
        }
        return $montantPaye;
    }
}