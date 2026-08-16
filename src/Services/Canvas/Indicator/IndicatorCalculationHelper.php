<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Assureur;
use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Cotation;
use App\Entity\Groupe;
use App\Entity\Portefeuille;
use App\Entity\Risque;
use App\Entity\Tranche;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Entity\Partenaire;
use App\Entity\RevenuPourCourtier;
use App\Entity\Article;
use App\Entity\ConditionPartage;
use App\Entity\TypeRevenu;
use App\Entity\Chargement;
use App\Entity\ChargementPourPrime;
use App\Entity\Taxe;
use App\Entity\Document;
use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
use App\Entity\Bordereau; // Import Bordereau
use App\Repository\CotationRepository;
use App\Repository\NotificationSinistreRepository;
use App\Repository\TaxeRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\ServiceDates;
use App\Services\ServiceTaxes;
use DateTimeImmutable;
use Symfony\Contracts\Service\ResetInterface;

class IndicatorCalculationHelper implements ResetInterface
{
    private array $claimsCache = [];
    private array $commissionHtCache = [];

    /**
     * Couverture bordereaux par entreprise (id) : avenants attestés par une ligne
     * réconciliée d'un bordereau de production, et sous-ensemble dont le bordereau
     * est intégralement encaissé. Voir getCouvertureBordereaux().
     *
     * @var array<int, array{couverts: array<int, true>, couvertsSoldes: array<int, true>}>
     */
    private array $couvertureBordereauxCache = [];

    /**
     * Sinistres déjà lus, par identifiant d'entreprise : ils étaient relus une fois par
     * ligne de rubrique, pour n'être ensuite filtrés que sur des références de police.
     *
     * @var array<int, NotificationSinistre[]>
     */
    private array $sinistresParEntrepriseCache = [];

    /**
     * Identifiants de cotation dont le graphe a DÉJÀ été préchargé pendant cette requête.
     *
     * Sans ce mémo, afficher vingt partenaires rejouait vingt fois les six requêtes de
     * preloadDepuisCotationIds() sur un sous-graphe très largement commun. Le mémo est
     * revalidé contre l'identity map avant usage : après un em->clear(), les collections
     * ne sont plus hydratées et il FAUT recharger, sinon l'économie se paierait en
     * chargements paresseux ligne à ligne — l'inverse du but.
     *
     * @var array<int, true>
     */
    private array $cotationsPrechargees = [];

    /**
     * Unités de mesure des conditions de partage (cf. sommeCommissionPureDeLUnite) : des
     * sommes qui balaient tout le portefeuille d'un partenaire, et qu'une rubrique
     * réclamerait sinon une fois par revenu et par ligne.
     *
     * @var array<string, float>
     */
    private array $uniteMesureCache = [];

    /**
     * La stratégie « revenu », qui porte le calcul du taux de partage. Elle était
     * instanciée À CHAQUE REVENU de chaque cotation — un objet neuf par ligne de calcul.
     */
    private ?RevenuPourCourtierIndicatorStrategy $strategieRevenu = null;

    public function __construct(
        private CotationRepository $cotationRepository,
        private NotificationSinistreRepository $notificationSinistreRepository,
        private TaxeRepository $taxeRepository,
        private ServiceTaxes $serviceTaxes,
        private ServiceDates $serviceDates,
        private EntityManagerInterface $em
    ) {
    }

    public function reset(): void
    {
        $this->claimsCache = [];
        $this->commissionHtCache = [];
        $this->couvertureBordereauxCache = [];
        $this->sinistresParEntrepriseCache = [];
        $this->cotationsPrechargees = [];
        $this->uniteMesureCache = [];
    }

    public function getInterpretationTauxSP(float $taux): string
    {
        if ($taux == 0) return "Aucun sinistre enregistré ou prime nulle.";
        if ($taux < 70) return "Excellent. Le portefeuille est très rentable.";
        if ($taux <= 80) return "Sain. Équilibre classique.";
        if ($taux <= 100) return "Prudence. Rentabilité faible.";
        return "Déficitaire. Pertes techniques.";
    }

    public function getInterpretationIndiceSolvabilite(float $indice): string
    {
        if ($indice == 0) return "Aucune prime émise ou aucun paiement enregistré.";
        if ($indice >= 95) return "Excellent. Le client honore quasi intégralement ses primes.";
        if ($indice >= 80) return "Bon. La grande majorité des primes est réglée.";
        if ($indice >= 60) return "Moyen. Un suivi du recouvrement est recommandé.";
        return "Faible. Risque d'impayés élevé, recouvrement à engager.";
    }

    public function getRoleAccessString(object $entity, array $params): string
    {
        if (empty($params[0])) return 'Paramètre manquant';
        $fieldCode = $params[0];
        $getter = 'get' . ucfirst($fieldCode);

        if (!method_exists($entity, $getter)) return 'Champ d\'accès invalide';
        $accessArray = $entity->{$getter}();
        if (!is_array($accessArray) || empty($accessArray)) return 'Aucun accès défini';

        $permissionMap = [
            0 => 'read',   'read'   => 'read',
            1 => 'create', 'create' => 'create',
            2 => 'update', 'update' => 'update',
            3 => 'delete', 'delete' => 'delete',
        ];
        
        $permissionLabels = [
            'create' => 'Ecriture',
            'read'   => 'Lecture',
            'update' => 'Modification',
            'delete' => 'Suppression',
        ];

        $labels = [];
        foreach ($accessArray as $permission) {
            $permissionKey = $permissionMap[$permission] ?? null;
            if ($permissionKey && isset($permissionLabels[$permissionKey])) {
                $labels[] = $permissionLabels[$permissionKey];
            }
        }
        return empty($labels) ? 'Aucun accès valide' : implode(', ', $labels);
    }

    public function Chargement_getFonctionString(?Chargement $chargement): ?string
    {
        if ($chargement === null) return null;
        return match ($chargement->getFonction()) {
            Chargement::FONCTION_PRIME_NETTE => "Prime nette",
            Chargement::FONCTION_FRONTING => "Fronting",
            Chargement::FONCTION_FRAIS_ADMIN => "Frais administratifs",
            Chargement::FONCTION_TAXE => "Taxe",
            default => "Non définie",
        };
    }

    public function getDocumentTypeFichier(Document $document): string
    {
        $nomFichier = $document->getNomFichierStocke();
        if (!$nomFichier) return 'Inconnu';
        return pathinfo($nomFichier, PATHINFO_EXTENSION);
    }

    public function getClientDescriptionFromCotation(?Cotation $cotation): string
    {
        if (!$cotation || !$cotation->getPiste() || !$cotation->getPiste()->getClient()) return 'N/A';
        return $cotation->getPiste()->getClient()->getNom();
    }

    public function getRisqueDescriptionFromCotation(?Cotation $cotation): string
    {
        if (!$cotation || !$cotation->getPiste() || !$cotation->getPiste()->getRisque()) return 'N/A';
        return $cotation->getPiste()->getRisque()->getNomComplet();
    }

    public function getCotationContextePiste(Cotation $cotation): string
    {
        $piste = $cotation->getPiste();
        if (!$piste) return "Cette cotation n'est rattachée à aucune piste.";
        $pisteNom = $piste->getNom() ?? 'N/A';
        $clientNom = $piste->getClient() ? $piste->getClient()->getNom() : 'non défini';
        return sprintf("Piste '%s' pour le client '%s'", $pisteNom, $clientNom);
    }

    public function isCotationBound(?Cotation $cotation): bool
    {
        return $cotation && !$cotation->getAvenants()->isEmpty();
    }

    /**
     * Une cotation « concurrente caduque » (sans suite) : elle n'est PAS souscrite, mais une
     * AUTRE cotation de la MÊME piste l'est déjà. Plusieurs cotations d'une piste sont des
     * propositions concurrentes (assureurs rivaux) pour un seul marché ; dès qu'une est validée
     * (avenant = police), le marché est attribué et les autres ont perdu l'affaire. Corollaire de
     * isCotationBound : sert à ne plus présenter ces propositions comme des opportunités à relancer.
     */
    public function isCotationConcurrenteCaduque(?Cotation $cotation): bool
    {
        if ($cotation === null || $this->isCotationBound($cotation)) {
            return false;
        }
        $piste = $cotation->getPiste();
        if ($piste === null) {
            return false;
        }
        foreach ($piste->getCotations() as $soeur) {
            if ($soeur !== $cotation && $this->isCotationBound($soeur)) {
                return true;
            }
        }

        return false;
    }

    public function getCotationReferencePolice(Cotation $cotation): string
    {
        if ($cotation->getAvenants()->isEmpty()) return 'Nulle';
        return $cotation->getAvenants()->first()->getReferencePolice() ?? 'Nulle';
    }

    public function getCotationPeriodeCouverture(Cotation $cotation): string
    {
        if ($cotation->getAvenants()->isEmpty()) return 'Aucune';
        $avenant = $cotation->getAvenants()->first();
        if ($avenant->getStartingAt() && $avenant->getEndingAt()) {
            return sprintf("Du %s au %s", $avenant->getStartingAt()->format('d/m/Y'), $avenant->getEndingAt()->format('d/m/Y'));
        }
        return 'Période incomplète';
    }

    public function getCotationClaims(Cotation $cotation): array
    {
        $id = $cotation->getId();
        if ($id === null) {
            $ref = $this->getCotationReferencePolice($cotation);
            return ($ref === 'Nulle') ? [] : $this->notificationSinistreRepository->findBy(['referencePolice' => $ref]);
        }
        if (!array_key_exists($id, $this->claimsCache)) {
            $ref = $this->getCotationReferencePolice($cotation);
            $this->claimsCache[$id] = ($ref === 'Nulle') ? [] : $this->notificationSinistreRepository->findBy(['referencePolice' => $ref]);
        }
        return $this->claimsCache[$id];
    }

    public function getCotationIndemnisationDue(Cotation $cotation): float
    {
        $claims = $this->getCotationClaims($cotation);
        $total = 0.0;
        foreach ($claims as $claim) {
            $total += $this->getNotificationSinistreCompensation($claim);
        }
        return $total;
    }

    public function getCotationIndemnisationVersee(Cotation $cotation): float
    {
        $claims = $this->getCotationClaims($cotation);
        $total = 0.0;
        foreach ($claims as $claim) {
            $total += $this->getNotificationSinistreCompensationVersee($claim);
        }
        return $total;
    }

    public function getCotationIndemnisationSolde(Cotation $cotation): float
    {
        return $this->getCotationIndemnisationDue($cotation) - $this->getCotationIndemnisationVersee($cotation);
    }

    public function getCotationTauxSP(Cotation $cotation): float
    {
        $prime = $this->getCotationMontantPrimePayableParClient($cotation);
        $sinistre = $this->getCotationIndemnisationDue($cotation);
        if ($prime > 0) return round(($sinistre / $prime) * 100, 2);
        return 0.0;
    }

    public function getCotationTauxSPInterpretation(Cotation $cotation): string
    {
        $taux = $this->getCotationTauxSP($cotation);
        $indemnisationDue = $this->getCotationIndemnisationDue($cotation);
        if ($indemnisationDue == 0) return "Aucun sinistre indemnisable enregistré pour cette police.";
        if ($taux == 0 && $indemnisationDue > 0) return "La prime étant nulle ou négative, le ratio est infini.";
        return $this->getInterpretationTauxSP($taux);
    }

    public function getCotationDateDernierReglement(Cotation $cotation): ?\DateTimeInterface
    {
        $claims = $this->getCotationClaims($cotation);
        $lastDate = null;
        foreach ($claims as $claim) {
            $date = $this->getNotificationSinistreDateDernierReglement($claim);
            if ($date && ($lastDate === null || $date > $lastDate)) {
                $lastDate = $date;
            }
        }
        return $lastDate;
    }

    public function getCotationVitesseReglement(Cotation $cotation): string
    {
        $solde = $this->getCotationIndemnisationSolde($cotation);
        if ($solde > 0) return "Traitement encours";
        
        $claims = $this->getCotationClaims($cotation);
        if (empty($claims)) return "Aucun sinistre";

        $lastPaymentDate = null;
        $associatedClaim = null;

        foreach ($claims as $claim) {
            $date = $this->getNotificationSinistreDateDernierReglement($claim);
            if ($date && ($lastPaymentDate === null || $date > $lastPaymentDate)) {
                $lastPaymentDate = $date;
                $associatedClaim = $claim;
            }
        }

        if ($lastPaymentDate && $associatedClaim && $associatedClaim->getNotifiedAt()) {
            $days = $this->serviceDates->daysEntre($associatedClaim->getNotifiedAt(), $lastPaymentDate);
            return $days . " jour(s)";
        }
        return "N/A";
    }

    public function calculateNombreTranches(Cotation $cotation): int
    {
        return $cotation->getTranches()->count();
    }

    public function calculateMontantMoyenTranche(Cotation $cotation): float
    {
        $nombreTranches = $this->calculateNombreTranches($cotation);
        if ($nombreTranches === 0) return 0.0;
        $primeTotale = 0.0;
        foreach ($cotation->getChargements() as $chargement) {
            $primeTotale += $chargement->getMontantFlatExceptionel() ?? 0;
        }
        if ($primeTotale > 0) return round($primeTotale / $nombreTranches, 2);
        return 0.0;
    }

    public function getChargementPourPrimePoidsSurPrime(ChargementPourPrime $chargement): ?float
    {
        $cotation = $chargement->getCotation();
        if (!$cotation) return null;

        $montantChargement = $chargement->getMontantFlatExceptionel() ?? 0.0;
        $primeTotale = $this->getCotationMontantPrimePayableParClient($cotation);

        if ($primeTotale > 0) return round(($montantChargement / $primeTotale) * 100, 2);
        return 0.0;
    }

    public function getIndicateursGlobaux(Entreprise $entreprise, bool $isBound, array $options = []): array
    {
        $totals = array_fill_keys([
            'prime_totale', 'prime_totale_payee', 'commission_totale', 'commission_totale_encaissee',
            'commission_nette', 'commission_pure', 'prime_nette', 'commission_partageable', 'reserve',
            'retro_commission_partenaire', 'retro_commission_partenaire_payee', 'taxe_courtier',
            'taxe_courtier_payee', 'taxe_assureur', 'taxe_assureur_payee', 'sinistre_payable', 'sinistre_paye'
        ], 0.0);
        extract($totals);

        $pisteCible = $options['pisteCible'] ?? null;
        $cotationCible = $options['cotationCible'] ?? null;
        $assureurCible = $options['assureurCible'] ?? null;
        $risqueCible = $options['risqueCible'] ?? null;
        $partenaireCible = $options['partenaireCible'] ?? null;
        $inviteCible = $options['inviteCible'] ?? null;
        $groupeCible = $options['groupeCible'] ?? null;
        $portefeuilleCible = $options['portefeuilleCible'] ?? null;
        $avenantCible = $options['avenantCible'] ?? null;
        $clientCible = $options['clientCible'] ?? null;
        $trancheCible = $options['trancheCible'] ?? null;
        $brancheCible = $options['brancheCible'] ?? null;
        $reper = $options['reper'] ?? null;
        $dateA_str = $options['entre'] ?? null;
        $dateB_str = $options['et'] ?? null;
        $typeRevenuCible = $options['typeRevenuCible'] ?? null;
        $revenuPourCourtierCible = $options['revenuPourCourtierCible'] ?? null;
        $paiementCible = $options['paiementCible'] ?? null;
        $notificationSinistreCible = $options['notificationSinistreCible'] ?? null;
        $conditionPartageCible = $options['conditionPartageCible'] ?? null;

        // LA REQUÊTE RACINE NE RAMÈNE QUE DES COTATIONS. Elle joignait auparavant quinze
        // relations, TOUTES en addSelect — dont six collections to-many simultanées
        // (avenants, revenus, tranches, articles, paiements, chargements) : exactement le
        // produit cartésien que le docblock de preloadAvenantRelations interdit
        // formellement quelques centaines de lignes plus bas. Le graphe est désormais
        // hydraté après coup par preloadDepuisCotationIds(), en un nombre FIXE de
        // requêtes, une seule collection to-many chacune. Ne subsistent ici que les
        // jointures dont un FILTRE a besoin, et seulement quand ce filtre est actif.
        $qb = $this->cotationRepository->createQueryBuilder('c')
            ->select('c')
            ->join('c.piste', 'p')
            ->join('p.invite', 'i')
            ->where('i.entreprise = :entreprise')
            ->setParameter('entreprise', $entreprise)
            ->distinct();

        // LES PROPOSITIONS NE SONT PLUS HYDRATÉES POUR ÊTRE JETÉES. Les deux seules
        // boucles qui consomment ce résultat commencent l'une et l'autre par écarter les
        // cotations non souscrites, et isCotationBound() vaut exactement
        // « avenants non vide ». Filtrer en SQL est donc sans effet sur les chiffres, et
        // épargne l'hydratation de toutes les propositions en cours.
        // $isBound est conservé pour la compatibilité des appelants : il ne décide plus
        // rien, la règle métier étant désormais appliquée dans tous les cas.
        $qb->andWhere('SIZE(c.avenants) > 0');
        if ($pisteCible) $qb->andWhere('p = :pisteCible')->setParameter('pisteCible', $pisteCible);
        if ($cotationCible) $qb->andWhere('c = :cotationCible')->setParameter('cotationCible', $cotationCible);
        if ($assureurCible) {
            if ($assureurCible->getId() === null) $qb->andWhere('1=0');
            else $qb->andWhere('c.assureur = :assureurCible')->setParameter('assureurCible', $assureurCible);
        }
        if ($risqueCible) {
            if ($risqueCible->getId() === null) $qb->andWhere('1=0');
            else $qb->andWhere('p.risque = :risqueCible')->setParameter('risqueCible', $risqueCible);
        }
        if ($inviteCible) $qb->andWhere('p.invite = :inviteCible')->setParameter('inviteCible', $inviteCible);
        if ($clientCible) {
            if ($clientCible->getId() === null) $qb->andWhere('1=0');
            else $qb->andWhere('p.client = :clientCible')->setParameter('clientCible', $clientCible);
        }
        if ($groupeCible) $qb->join('p.client', 'cl_g')->andWhere('cl_g.groupe = :groupeCible')->setParameter('groupeCible', $groupeCible);
        if ($portefeuilleCible) {
            if ($portefeuilleCible->getId() === null) $qb->andWhere('1=0');
            else $qb->join('p.client', 'cl_pf')->andWhere('cl_pf.portefeuille = :portefeuilleCible')->setParameter('portefeuilleCible', $portefeuilleCible);
        }
        if ($partenaireCible) {
            if ($partenaireCible->getId() === null) $qb->andWhere('1=0');
            else {
                // Un partenaire s'attache à la PISTE ou au CLIENT : les deux chemins comptent.
                $qb->leftJoin('p.client', 'cl')
                    ->leftJoin('p.partenaires', 'pa')
                    ->leftJoin('cl.partenaires', 'clpa')
                    ->andWhere('pa = :partenaireCible OR clpa = :partenaireCible')
                    ->setParameter('partenaireCible', $partenaireCible);
            }
        }
        if ($avenantCible) $qb->leftJoin('c.avenants', 'av')->andWhere('av = :avenantCible')->setParameter('avenantCible', $avenantCible);
        if ($trancheCible) $qb->leftJoin('c.tranches', 't')->andWhere('t = :trancheCible')->setParameter('trancheCible', $trancheCible);
        if ($revenuPourCourtierCible) $qb->join('c.revenus', 'rpc')->andWhere('rpc = :revenuPourCourtierCible')->setParameter('revenuPourCourtierCible', $revenuPourCourtierCible);
        if ($typeRevenuCible) $qb->join('c.revenus', 'rpc_tr')->andWhere('rpc_tr.typeRevenu = :typeRevenuCible')->setParameter('typeRevenuCible', $typeRevenuCible);

        if ($brancheCible) {
            $brancheCode = ($brancheCible === 'IARD') ? Risque::BRANCHE_IARD_OU_NON_VIE : (($brancheCible === 'VIE') ? Risque::BRANCHE_VIE : -1);
            if ($brancheCode !== -1) {
                $qb->join('p.risque', 'r_b')->andWhere('r_b.branche = :brancheCode')->setParameter('brancheCode', $brancheCode);
            }
        }

        if ($conditionPartageCible) {
            $qb->join('p.conditionsPartageExceptionnelles', 'cp')->andWhere('cp = :conditionPartageCible')->setParameter('conditionPartageCible', $conditionPartageCible);
        }

        if ($reper && $dateA_str && $dateB_str) {
            $dateA = DateTimeImmutable::createFromFormat('d/m/Y', $dateA_str);
            $dateB = DateTimeImmutable::createFromFormat('d/m/Y', $dateB_str);
            if ($dateA && $dateB) {
                $qb->join('c.avenants', 'av_date')
                    ->andWhere($qb->expr()->between(($reper === 'dateEffet' ? 'av_date.startingAt' : 'av_date.endingAt'), ':dateA', ':dateB'))
                    ->setParameter('dateA', $dateA->setTime(0, 0, 0))
                    ->setParameter('dateB', $dateB->setTime(23, 59, 59));
            }
        }

        if ($notificationSinistreCible && $notificationSinistreCible->getReferencePolice()) {
            $qb->join('c.avenants', 'av_sin')->andWhere('av_sin.referencePolice = :refPolice')->setParameter('refPolice', $notificationSinistreCible->getReferencePolice());
        }

        if ($paiementCible) {
            if ($note = $paiementCible->getNote()) {
                $qb->join('c.tranches', 't_payment')->join('t_payment.articles', 'a_payment')
                   ->andWhere('a_payment.note = :payment_note')
                   ->setParameter('payment_note', $note)
                   ->distinct(); 
            } else {
                $qb->andWhere('1=0');
            }
        }

        $cotationsAcalculer = $qb->getQuery()->getResult();

        // Le graphe que la boucle d'agrégation va parcourir, hydraté en six requêtes à
        // nombre fixe — ce que les addSelect faisaient auparavant en une seule, au prix
        // du produit cartésien. Sans cet appel, chaque cotation rallumerait ses propres
        // chargements paresseux : le remède serait pire que le mal.
        $this->preloadDepuisCotationIds(array_map(static fn (Cotation $c) => $c->getId(), $cotationsAcalculer));

        $policeReferences = [];
        foreach ($cotationsAcalculer as $cotation) {
            if (!$this->isCotationBound($cotation)) continue;
            foreach ($cotation->getAvenants() as $avenant) {
                if ($avenant->getReferencePolice()) {
                    $policeReferences[] = $avenant->getReferencePolice();
                }
            }
        }
        $policeReferences = array_unique($policeReferences);

                // LE CAS COURANT NE VAUT PLUS UNE REQUÊTE PAR LIGNE. Vingt partenaires affichés,
        // c'étaient vingt fois la même lecture des sinistres de l'entreprise, à la seule
        // différence de la liste de références de police. Celle-ci se filtre aussi bien en
        // mémoire, sur une collection lue une fois par requête HTTP.
        // Les deux cibles ci-dessous restreignent à UN sinistre nommé : trop rares et trop
        // particulières pour mériter le détour, elles gardent leur requête d'origine.
        $sinistresAcalculer = ($notificationSinistreCible === null && $paiementCible === null)
            ? $this->sinistresDesPolices($entreprise, $options === [] ? null : $policeReferences)
            : $this->sinistresFiltres($entreprise, $options, $policeReferences, $notificationSinistreCible, $paiementCible);

        foreach ($cotationsAcalculer as $cotation) {
            // RÈGLE MÉTIER : une cotation NON validée (proposition sans avenant) n'est qu'un
            // projet — ses primes, commissions, rétros, taxes et réserve ne sont que des
            // PROJECTIONS et ne doivent JAMAIS être agrégées (ni dans les listes du workspace,
            // ni chez Ket). Seules les cotations BOUND (police concrétisée) comptent. Le suivi
            // et le chiffre ne naissent qu'à la validation du client (avenant).
            if (!$this->isCotationBound($cotation)) continue;

            $prime_nette += $this->getCotationMontantPrimeNette($cotation);
            $prime_cotation = $this->getCotationMontantPrimePayableParClient($cotation);
            $prime_totale += $prime_cotation;
            // Prime encaissée : notes client réglées (prorata) + paiements SIGNALÉS
            // (PaiementPrime déclaratif). Était initialisé à 0 sans jamais être
            // accumulé → « solde de prime » toujours égal à la prime totale.
            $prime_totale_payee += $this->getCotationMontantPrimePayableParClientPayee($cotation);

            $commission_ttc_cotation = $this->getCotationMontantCommissionTtc($cotation, -1, false);
            $commission_totale += $commission_ttc_cotation;
            $commission_totale_encaissee += $this->getCotationMontantCommissionEncaissee($cotation);

            $cotation_com_nette = $this->getCotationMontantCommissionHt($cotation, -1, false);
            $commission_nette += $cotation_com_nette;

            $cotation_taxe_courtier = $this->getCotationMontantTaxeCourtier($cotation, false);
            $cotation_taxe_assureur = $this->getCotationMontantTaxeAssureur($cotation, false);
            $taxe_courtier += $cotation_taxe_courtier;
            $taxe_assureur += $cotation_taxe_assureur;
            $taxe_courtier_payee += $this->getCotationMontantTaxeCourtierPayee($cotation);
            $taxe_assureur_payee += $this->getCotationMontantTaxeAssureurPayee($cotation);

            $commission_pure += $cotation_com_nette - $cotation_taxe_courtier;

            $cotation_com_nette_partageable = $this->getCotationMontantCommissionHt($cotation, -1, true);
            $cotation_taxe_courtier_partageable = $this->getCotationMontantTaxeCourtier($cotation, true);
            $commission_partageable += $cotation_com_nette_partageable - $cotation_taxe_courtier_partageable;

            $retro_commission_partenaire += $this->getCotationMontantRetrocommissionsPayableParCourtier($cotation, $partenaireCible, -1);
            $retro_commission_partenaire_payee += $this->getCotationMontantRetrocommissionsPayableParCourtierPayee($cotation, $partenaireCible);
        }

        foreach ($sinistresAcalculer as $sinistre) {
            $sinistre_payable += $this->getNotificationSinistreCompensation($sinistre);
            $sinistre_paye += $this->getNotificationSinistreCompensationVersee($sinistre);
        }

        if ($trancheCible) {
            // Part de la tranche via getTrancheTauxFactor — JAMAIS le pourcentage brut :
            // il peut être stocké en points (100 = 100 %) et les tranches à montant fixe
            // n'ont pas de pourcentage du tout (le prorata = montantFlat / prime totale).
            // Le brut donnait ex. 1 381,48 × 100 = 138 148 de « prime totale » (Ket/visualisation).
            $facteur = $this->getTrancheTauxFactor($trancheCible);
            $prime_totale *= $facteur;
            $commission_totale *= $facteur;
            $commission_nette *= $facteur;
            $commission_pure *= $facteur;
            $commission_partageable *= $facteur;
            $prime_nette *= $facteur;
            $retro_commission_partenaire *= $facteur;
            $reserve *= $facteur;
            $taxe_courtier *= $facteur;
            $taxe_assureur *= $facteur;
            // Les montants ENCAISSÉS/PAYÉS d'une tranche sont des faits propres à la
            // tranche (notes de SES articles, paiements de prime SIGNALÉS sur elle) —
            // jamais un prorata de la cotation. Mêmes chiffres que la fiche/liste
            // (TrancheIndicatorStrategy), Ket et la visualisation restent cohérents.
            $prime_totale_payee = $this->getTranchePrimePayee($trancheCible);
            $commission_totale_encaissee = $this->getTrancheMontantCommissionEncaissee($trancheCible);
            $retro_commission_partenaire_payee = $this->getTrancheMontantRetrocommissionsPayableParCourtierPayee($trancheCible, $partenaireCible);
            $taxe_courtier_payee = $this->getTrancheMontantTaxePayee($trancheCible, false);
            $taxe_assureur_payee = $this->getTrancheMontantTaxePayee($trancheCible, true);
        }

        $reserve = $commission_pure - $retro_commission_partenaire;
        $prime_totale_solde = $prime_totale - $prime_totale_payee;
        $commission_totale_solde = $commission_totale - $commission_totale_encaissee;
        $retro_commission_partenaire_solde = $retro_commission_partenaire - $retro_commission_partenaire_payee;
        $taxe_courtier_solde = $taxe_courtier - $taxe_courtier_payee;
        $taxe_assureur_solde = $taxe_assureur - $taxe_assureur_payee;
        $sinistre_solde = $sinistre_payable - $sinistre_paye;
        $taux_sinistralite = ($prime_totale > 0) ? ($sinistre_payable / $prime_totale) * 100 : 0;
        // CORRECTION : Il manquait la multiplication par 100 pour obtenir un pourcentage.
        $taux_de_commission = ($prime_nette > 0) ? ($commission_nette / $prime_nette) * 100 : 0.0;
        $taux_de_retrocommission_effectif = ($commission_partageable > 0) ? ($retro_commission_partenaire / $commission_partageable) * 100 : 0;
        $taux_de_paiement_prime = ($prime_totale > 0) ? ($prime_totale_payee / $prime_totale) * 100 : 0;
        $taux_de_paiement_commission = ($commission_totale > 0) ? ($commission_totale_encaissee / $commission_totale) * 100 : 0;
        $taux_de_paiement_retro_commission = ($retro_commission_partenaire > 0) ? ($retro_commission_partenaire_payee / $retro_commission_partenaire) * 100 : 0;
        $taux_de_paiement_taxe_courtier = ($taxe_courtier > 0) ? ($taxe_courtier_payee / $taxe_courtier) * 100 : 0;
        $taux_de_paiement_taxe_assureur = ($taxe_assureur > 0) ? ($taxe_assureur_payee / $taxe_assureur) * 100 : 0;
        $taux_de_paiement_sinistre = ($sinistre_payable > 0) ? ($sinistre_paye / $sinistre_payable) * 100 : 0;

        return [
            'prime_totale' => $prime_totale,
            'prime_totale_payee' => $prime_totale_payee,
            'prime_totale_solde' => $prime_totale_solde,
            'commission_totale' => $commission_totale,
            'commission_totale_encaissee' => $commission_totale_encaissee,
            'commission_totale_solde' => $commission_totale_solde,
            'commission_nette' => $commission_nette,
            'commission_pure' => $commission_pure,
            'commission_partageable' => $commission_partageable,
            'prime_nette' => $prime_nette,
            'reserve' => $reserve,
            'retro_commission_partenaire' => $retro_commission_partenaire,
            'retro_commission_partenaire_payee' => $retro_commission_partenaire_payee,
            'retro_commission_partenaire_solde' => $retro_commission_partenaire_solde,
            'taxe_courtier' => $taxe_courtier,
            'taxe_courtier_payee' => $taxe_courtier_payee,
            'taxe_courtier_solde' => $taxe_courtier_solde,
            'taxe_assureur' => $taxe_assureur,
            'taxe_assureur_payee' => $taxe_assureur_payee,
            'taxe_assureur_solde' => $taxe_assureur_solde,
            'sinistre_payable' => $sinistre_payable,
            'sinistre_paye' => $sinistre_paye,
            'sinistre_solde' => $sinistre_solde,
            'taux_sinistralite' => $taux_sinistralite,
            'taux_de_commission' => $taux_de_commission,
            'taux_de_retrocommission_effectif' => $taux_de_retrocommission_effectif,
            'taux_de_paiement_prime' => $taux_de_paiement_prime,
            'taux_de_paiement_commission' => $taux_de_paiement_commission,
            'taux_de_paiement_retro_commission' => $taux_de_paiement_retro_commission,
            'taux_de_paiement_taxe_courtier' => $taux_de_paiement_taxe_courtier,
            'taux_de_paiement_taxe_assureur' => $taux_de_paiement_taxe_assureur,
            'taux_de_paiement_sinistre' => $taux_de_paiement_sinistre,
        ];
    }

    /**
     * Les sinistres de l'entreprise, restreints aux polices données.
     *
     * @param string[]|null $references null = toutes les polices (appel sans options)
     *
     * @return NotificationSinistre[]
     */
    private function sinistresDesPolices(Entreprise $entreprise, ?array $references): array
    {
        $tous = $this->sinistresDeLEntreprise($entreprise);

        if ($references === null) {
            return $tous;
        }
        if ($references === []) {
            // Une cible sans aucune police n'a aucun sinistre : c'était le « 1=0 » de la
            // requête d'origine.
            return [];
        }

        $index = array_flip($references);

        return array_values(array_filter(
            $tous,
            static fn (NotificationSinistre $ns) => isset($index[$ns->getReferencePolice()]),
        ));
    }

    /**
     * @return NotificationSinistre[]
     */
    private function sinistresDeLEntreprise(Entreprise $entreprise): array
    {
        $cle = (int) $entreprise->getId();
        $connus = $this->sinistresParEntrepriseCache[$cle] ?? null;

        // Un cache d'ENTITÉS ne survit pas à un em->clear() : les objets gardés seraient
        // détachés et leurs relations mortes. On le revalide donc plutôt que de le vider
        // à l'aveugle (même précaution que pour le mémo de préchargement).
        if ($connus !== null && ($connus === [] || $this->em->contains($connus[0]))) {
            return $connus;
        }

        $sinistres = $this->notificationSinistreRepository->createQueryBuilder('ns')
            ->join('ns.invite', 'i')
            ->where('i.entreprise = :entreprise')
            ->setParameter('entreprise', $entreprise)
            ->getQuery()
            ->getResult();

        return $this->sinistresParEntrepriseCache[$cle] = $sinistres;
    }

    /**
     * Le chemin d'origine, mot pour mot, pour les deux cibles qui désignent UN sinistre.
     *
     * @param string[] $policeReferences
     *
     * @return NotificationSinistre[]
     */
    private function sinistresFiltres(
        Entreprise $entreprise,
        array $options,
        array $policeReferences,
        ?NotificationSinistre $notificationSinistreCible,
        ?Paiement $paiementCible,
    ): array {
        $sinistresQb = $this->notificationSinistreRepository->createQueryBuilder('ns')
            ->join('ns.invite', 'i')
            ->where('i.entreprise = :entreprise')
            ->setParameter('entreprise', $entreprise);

        if (!empty($options)) {
            if (!empty($policeReferences)) {
                $sinistresQb->andWhere('ns.referencePolice IN (:policeReferences)')->setParameter('policeReferences', $policeReferences);
            } else {
                $sinistresQb->andWhere('1=0');
            }
        }

        if ($notificationSinistreCible) $sinistresQb->andWhere('ns = :notificationSinistreCible')->setParameter('notificationSinistreCible', $notificationSinistreCible);

        if ($paiementCible) {
            if ($offre = $paiementCible->getOffreIndemnisationSinistre()) {
                if ($sinistreDuPaiement = $offre->getNotificationSinistre()) {
                    $sinistresQb->andWhere('ns = :sinistreDuPaiement')->setParameter('sinistreDuPaiement', $sinistreDuPaiement);
                } else {
                    $sinistresQb->andWhere('1=0');
                }
            } else {
                $sinistresQb->andWhere('1=0');
            }
        }

        return $sinistresQb->getQuery()->getResult();
    }

    /**
     * L'UNITÉ DE MESURE d'une condition de partage : la somme de commission pure à
     * laquelle son SEUIL est comparé.
     *
     * La règle n'est pas inventée ici — elle est reprise de l'implémentation d'origine
     * (Constante::appliquerConditions et ses trois Cotation_getSommeCommissionPure*),
     * seule source de vérité de ce calcul : on somme la commission pure de toutes les
     * cotations de l'ENTREPRISE, du MÊME EXERCICE et du MÊME PARTENAIRE, restreintes
     * selon l'unité au même risque, au même client, ou à rien du tout.
     *
     * Le résultat est mémoïsé : sans cela, une rubrique interrogerait ces sommes une fois
     * par revenu et par ligne, sur des requêtes qui balaient tout le portefeuille.
     */
    private function sommeCommissionPureDeLUnite(ConditionPartage $condition, ?Cotation $cotation, $addressedTo, bool $onlySharable): float
    {
        $piste = $cotation?->getPiste();
        $entreprise = $piste?->getInvite()?->getEntreprise();
        if (!$piste || !$entreprise) return 0.0;

        $exercice = $piste->getExercice();
        $partenaire = $this->getCotationPartenaire($cotation);
        $unite = $condition->getUniteMesure();

        $cle = implode(':', [
            $unite,
            (int) $entreprise->getId(),
            (int) $exercice,
            (int) $partenaire?->getId(),
            (int) $piste->getRisque()?->getId(),
            (int) $piste->getClient()?->getId(),
            $addressedTo,
            $onlySharable ? '1' : '0',
        ]);
        if (array_key_exists($cle, $this->uniteMesureCache)) {
            return $this->uniteMesureCache[$cle];
        }

        $cotations = match ($unite) {
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_RISQUE => $piste->getRisque() === null ? []
                : $this->cotationRepository->loadCotationsWithPartnerRisque($exercice, $entreprise, $piste->getRisque(), $partenaire),
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_CLIENT => $piste->getClient() === null ? []
                : $this->cotationRepository->loadCotationsWithPartnerClient($exercice, $entreprise, $piste->getClient(), $partenaire),
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_PARTENAIRE
                => $this->cotationRepository->loadCotationsWithPartnerAll($exercice, $entreprise, $partenaire),
            default => [],
        };

        $somme = 0.0;
        foreach ($cotations as $proposition) {
            $somme += $this->getCotationMontantCommissionPure($proposition, $addressedTo, $onlySharable);
        }

        return $this->uniteMesureCache[$cle] = $somme;
    }

    public function getCotationMontantPrimeNette(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation) {
            foreach ($cotation->getChargements() as $chargement) {
                if ($chargement->getType() && $chargement->getType()->getFonction() === Chargement::FONCTION_PRIME_NETTE) {
                    $montant += $chargement->getMontantFlatExceptionel();
                }
            }
        }
        return $montant;
    }

    public function getCotationMontantPrimePayableParClient(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation && $cotation->getChargements()) {
            foreach ($cotation->getChargements() as $chargement) {
                $montant += $chargement->getMontantFlatExceptionel() ?? 0.0;
            }
        }
        return $montant;
    }

    public function getCotationMontantCommissionTtc(?Cotation $cotation, ?int $addressedTo, bool $onlySharable): float
    {
        if (!$cotation) return 0;
        $comTTCAssureur = $this->getCotationMontantCommissionTtcPayableParAssureur($cotation, $onlySharable);
        $comTTCClient = $this->getCotationMontantCommissionTtcPayableParClient($cotation, $onlySharable);
        return round($comTTCAssureur + $comTTCClient, 2);
    }

    public function getCotationMontantCommissionTtcPayableParAssureur(?Cotation $cotation, bool $onlySharable): float
    {
        if (!$cotation) return 0;
        $net = $this->getCotationMontantCommissionHt($cotation, TypeRevenu::REDEVABLE_ASSUREUR, $onlySharable);
        $taxe = $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), true);
        return $net + $taxe;
    }

    public function getCotationMontantCommissionTtcPayableParClient(?Cotation $cotation, bool $onlySharable): float
    {
        if (!$cotation) return 0;
        $net = $this->getCotationMontantCommissionHt($cotation, TypeRevenu::REDEVABLE_CLIENT, $onlySharable);
        $taxe = $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), true);
        return $net + $taxe;
    }

    public function getCotationMontantCommissionHt(?Cotation $cotation, $addressedTo, bool $onlySharable): float
    {
        if (!$cotation) return 0.0;
        $id = $cotation->getId();
        if ($id !== null) {
            $key = $id . ':' . $addressedTo . ':' . ($onlySharable ? '1' : '0');
            if (!array_key_exists($key, $this->commissionHtCache)) {
                $this->commissionHtCache[$key] = $this->computeCommissionHt($cotation, $addressedTo, $onlySharable);
            }
            return $this->commissionHtCache[$key];
        }
        return $this->computeCommissionHt($cotation, $addressedTo, $onlySharable);
    }

    private function computeCommissionHt(Cotation $cotation, $addressedTo, bool $onlySharable): float
    {
        $montant = 0;
        foreach ($cotation->getRevenus() as $revenu) {
            if ($onlySharable) {
                // Garde null : un revenu SANS type (typeRevenu non renseigné) ne peut
                // pas être « partageable » — on l'ignore plutôt que de crasher la vue
                // (même prudence qu'aux lignes getRevenuMontantHtAddressedTo/getRevenuMontantHtShared).
                $typeRevenu = $revenu->getTypeRevenu();
                if ($typeRevenu && $typeRevenu->isShared() == $onlySharable) {
                    $montant += $this->getRevenuMontantHtAddressedTo($addressedTo, $revenu);
                }
            } else {
                $montant += $this->getRevenuMontantHtAddressedTo($addressedTo, $revenu);
            }
        }
        return $montant;
    }

    public function getRevenuMontantHtAddressedTo($addressedTo, RevenuPourCourtier $revenu): float
    {
        $montant = 0;
        $typeRevenu = $revenu->getTypeRevenu();
        if ($addressedTo != -1 && $typeRevenu) {
            if ($typeRevenu->getRedevable() == $addressedTo) {
                $montant += $this->getRevenuMontantHt($revenu);
            }
        } else {
            $montant += $this->getRevenuMontantHt($revenu);
        }
        return $montant;
    }

    public function getRevenuMontantHt(?RevenuPourCourtier $revenu): float
    {
        $montant = 0;
        if ($revenu) {
            $typeRevenu = $revenu->getTypeRevenu();
            if ($typeRevenu) {
                $cotation = $revenu->getCotation();
                $montantChargementPrime = $this->getCotationMontantChargementPrime($cotation, $typeRevenu);

                // PRIORITÉ 1 : Exceptions sur l'instance de revenu (Overrule)
                if ($revenu->getTauxExceptionel() && $revenu->getTauxExceptionel() != 0) {
                    $montant = $montantChargementPrime * $revenu->getFraction();
                } elseif ($revenu->getMontantFlatExceptionel() && $revenu->getMontantFlatExceptionel() != 0) {
                    $montant = $revenu->getMontantFlatExceptionel();
                } 
                // PRIORITÉ 2 : Valeurs par défaut du Type de Revenu
                elseif ($typeRevenu->getPourcentage() && $typeRevenu->getPourcentage() != 0) {
                    $montant = $montantChargementPrime * $typeRevenu->getFraction();
                } elseif ($typeRevenu->getMontantflat() && $typeRevenu->getMontantflat() != 0) {
                    $montant = $typeRevenu->getMontantflat();
                }
                // PRIORITÉ 3 : Logique dynamique par Risque
                elseif ($typeRevenu->isAppliquerPourcentageDuRisque() && ($risque = $this->getCotationRisque($cotation))) {
                    $montant = $montantChargementPrime * $risque->getFraction();
                }
            }
        }
        return $montant;
    }

    public function getCotationMontantChargementPrime(?Cotation $cotation, ?TypeRevenu $typeRevenu)
    {
        $montantChargementCible = 0;
        if ($cotation != null && $typeRevenu != null && $typeRevenu->getTypeChargement()) {
            $targetTypeId = $typeRevenu->getTypeChargement()->getId();
            
            foreach ($cotation->getChargements() as $loading) {
                // Comparaison robuste par ID pour éviter les problèmes de Proxy Doctrine
                if ($loading->getType() && $loading->getType()->getId() === $targetTypeId) {
                    $montantChargementCible = $loading->getMontantFlatExceptionel() ?? 0.0;
                    break; // On a trouvé le chargement correspondant, on peut sortir
                }
            }
        }
        return $montantChargementCible;
    }

    public function getCotationRisque(?Cotation $cotation)
    {
        if ($cotation && $cotation->getPiste()) return $cotation->getPiste()->getRisque();
        return null;
    }

    public function isIARD(?Cotation $cotation): bool
    {
        if ($cotation && $cotation->getPiste() && $cotation->getPiste()->getRisque()) {
            return $cotation->getPiste()->getRisque()->getBranche() == Risque::BRANCHE_IARD_OU_NON_VIE;
        }
        return false;
    }

    public function getCotationMontantCommissionEncaissee(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation) {
            foreach ($cotation->getTranches() as $tranche) {
                $montant += $this->getTrancheMontantCommissionEncaissee($tranche);
            }
        }
        return $montant;
    }

    public function getTrancheMontantCommissionEncaissee(?Tranche $tranche): float
    {
        $montant = 0;
        if ($tranche) {
            foreach ($tranche->getArticles() as $article) {
                $note = $article->getNote();
                // CORRECTION : On s'assure que l'article facture bien un revenu (commission)
                // et que la note est adressée au client ou à l'assureur.
                // Cela empêche de compter les paiements de taxes ou de rétrocessions comme des encaissements de commission.
                if ($note && $article->getRevenuFacture() && ($note->getAddressedTo() == Note::TO_ASSUREUR || $note->getAddressedTo() == Note::TO_CLIENT)) {
                    $montantPayableNote = $this->getNoteMontantPayable($note);
                    if ($montantPayableNote > 0) {
                        $proportionPaiement = ($this->getNoteMontantPaye($note) ?? 0) / $montantPayableNote;
                        $montant += $proportionPaiement * $this->getArticleMontant($article);
                    }
                }
            }

            // Miroir « sans articles » du circuit bordereau : la note liée au bordereau
            // peut ne porter aucun article (repli sur les montants du bordereau). Ce que
            // le règlement de ce bordereau a fait rentrer sur CETTE tranche est alors
            // déterminé par imputation sur les plus anciennes (cf. getCouvertureBordereaux),
            // et non par un taux uniforme — sans quoi aucune tranche n'est jamais soldée
            // tant que le bordereau ne l'est pas, et le filtre « commission payée » ne peut
            // structurellement rien renvoyer.
            //
            // max() et non addition : les deux chemins décrivent le même argent, l'un par
            // les articles de la note, l'autre par le bordereau qui en tient lieu.
            $montant = max($montant, $this->getTrancheCommissionAllouee($tranche));
        }
        return $montant;
    }

    public function getNoteMontantPayable(?Note $note): float
    {
        $montant = 0;
        if ($note) {
            foreach ($note->getArticles() as $article) {
                $montant += $this->getArticleMontant($article);
            }
        }
        return $montant;
    }

    public function getNoteMontantTotal(?Note $note): float
    {
        if (!$note) return 0.0;
        if ($note->getArticles()->isEmpty() && $note->getBordereau() !== null) {
            $bordereau = $note->getBordereau();
            return ($bordereau->getMontantComHtPayableNow() ?? 0.0)
                 + ($bordereau->getMontantTaxePayableNow() ?? 0.0);
        }
        return $this->getNoteMontantPayable($note);
    }

    /**
     * NOUVEAU : Calcule le montant total HT d'une note.
     */
    public function getNoteMontantHT(?Note $note): float
    {
        $montant = 0;
        if ($note) {
            foreach ($note->getArticles() as $article) {
                // On utilise la méthode HT de l'article que nous avons créée précédemment.
                $montant += $this->getArticleMontantHT($article);
            }
        }
        return $montant;
    }

    public function getNoteMontantPaye(?Note $note): float
    {
        $montant = 0;
        if ($note) {
            foreach ($note->getPaiements() as $encaisse) {
                $montant += $encaisse->getMontant();
            }
        }
        return $montant;
    }

    public function getCotationMontantTaxeCourtier(?Cotation $cotation, bool $onlySharable): float
    {
        if (!$cotation) return 0;
        $net = $this->getCotationMontantCommissionHt($cotation, -1, $onlySharable);
        return $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), false);
    }

    public function getCotationMontantTaxeAssureur(?Cotation $cotation, bool $onlySharable): float
    {
        if (!$cotation) return 0;
        $net = $this->getCotationMontantCommissionHt($cotation, -1, $onlySharable);
        return $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), true);
    }

    /**
     * Taux (en POURCENTAGE, ex. 16) de la taxe SUR LA COMMISSION due par l'ASSUREUR
     * (la TVA). Somme des taxes assureur applicables à la branche de la cotation —
     * même périmètre que le montant (getCotationMontantTaxeAssureur), donc
     * taux × commissionHT / 100 ≈ montant. Exposé pour que Ket LISE le taux au lieu
     * de le déduire (à tort) d'un montant ÷ assiette supposée.
     */
    public function getCotationTauxTaxeAssureurPercent(?Cotation $cotation): float
    {
        return $this->sommeTauxTaxePercent($cotation, true);
    }

    /**
     * Taux (en POURCENTAGE) de la taxe SUR LA COMMISSION due par le COURTIER (ex. ARCA).
     */
    public function getCotationTauxTaxeCourtierPercent(?Cotation $cotation): float
    {
        return $this->sommeTauxTaxePercent($cotation, false);
    }

    private function sommeTauxTaxePercent(?Cotation $cotation, bool $assureur): float
    {
        if (!$cotation) return 0.0;
        $isIARD = $this->isIARD($cotation);
        $entreprise = $cotation->getEntreprise();
        $taxes = $assureur
            ? $this->serviceTaxes->getTaxesPayableParAssureur($entreprise)
            : $this->serviceTaxes->getTaxesPayableParCourtier($entreprise);
        $total = 0.0;
        foreach ($taxes as $taxe) {
            $total += $taxe->tauxPourcentage($isIARD)->pourcent();
        }
        return round($total, 2);
    }

    public function getCotationMontantTaxeCourtierPayee(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation) {
            foreach ($cotation->getTranches() as $tranche) {
                $montant += $this->getTrancheMontantTaxePayee($tranche, false);
            }
        }
        return $montant;
    }

    public function getTrancheMontantTaxePayee(?Tranche $tranche, bool $isTaxeAssureur): float
    {
        $montant = 0.0;
        if (!$tranche) return $montant;

        $targetRedevable = $isTaxeAssureur ? Taxe::REDEVABLE_ASSUREUR : Taxe::REDEVABLE_COURTIER;

        foreach ($tranche->getArticles() as $article) {
            $note = $article->getNote();
            if ($note && $note->getAddressedTo() === Note::TO_AUTORITE_FISCALE) {
                // La taxe est maintenant liée à l'AutoriteFiscale de la Note, et non plus directement à l'Article via idPoste.
                $taxe = $note->getAutoritefiscale()?->getTaxe();

                if ($taxe && $taxe->getRedevable() === $targetRedevable) {
                    $montantPayableNote = $this->getNoteMontantPayable($note);
                    if ($montantPayableNote > 0) {
                        $proportionPaiement = ($this->getNoteMontantPaye($note) ?? 0) / $montantPayableNote;
                        $montant += $proportionPaiement * $this->getArticleMontant($article);
                    }
                }
            }
        }
        return $montant;
    }

    public function getCotationMontantTaxeAssureurPayee(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation) {
            foreach ($cotation->getTranches() as $tranche) {
                $montant += $this->getTrancheMontantTaxePayee($tranche, true);
            }
        }
        return $montant;
    }

    public function getCotationMontantRetrocommissionsPayableParCourtier(?Cotation $cotation, ?Partenaire $partenaireCible, $addressedTo): float
    {
        if (!$cotation) return 0.0;
        $montant = 0.0;
        foreach ($cotation->getRevenus() as $revenu) {
            $montant += $this->getRevenuMontantRetrocommissionsPayableParCourtier($revenu, $partenaireCible, $addressedTo);
        }
        return $montant;
    }

    public function getRevenuMontantRetrocommissionsPayableParCourtier(?RevenuPourCourtier $revenu, ?Partenaire $partenaireCible, $addressedTo): float
    {
        if (!$revenu || !$revenu->getTypeRevenu() || !$revenu->getTypeRevenu()->isShared()) return 0.0;
        $cotation = $revenu->getCotation();
        if (!$cotation || !$cotation->getPiste()) return 0.0;

        $partenaireAffaire = $this->getCotationPartenaire($cotation);
        if (!$partenaireAffaire || !$this->isSamePartenaire($partenaireAffaire, $partenaireCible)) return 0.0;


        // La logique de taux vit dans la stratégie « revenu » : on la réutilise plutôt que
        // de la dupliquer. Une seule instance suffit — elle était créée à chaque revenu.
        $this->strategieRevenu ??= new RevenuPourCourtierIndicatorStrategy($this, $this->taxeRepository, $this->em);

        // On récupère le taux de partage (maintenant un facteur correct, ex: 0.15)
        $tauxPartage = $this->strategieRevenu->getRevenuPartPartenaire($revenu);
    
        // On calcule l'assiette (Commission Pure)
        $assiette = $this->getRevenuMontantPure($revenu, $addressedTo, true);
    
        // On applique le taux à l'assiette
        return $assiette * $tauxPartage;
    }

    public function getCotationPartenaire(?Cotation $cotation)
    {
        if ($cotation?->getPiste()) {
            if (!$cotation->getPiste()->getPartenaires()->isEmpty()) {
                return $cotation->getPiste()->getPartenaires()->first();
            }
            $client = $cotation->getPiste()->getClient();
            if ($client && !$client->getPartenaires()->isEmpty()) {
                return $client->getPartenaires()->first();
            }
        }
        return null;
    }

    public function isSamePartenaire(?Partenaire $partenaire, ?Partenaire $partenaireCible): bool
    {
        if ($partenaireCible == null) return true;
        return $partenaireCible == $partenaire;
    }

    public function applyRevenuConditionsSpeciales(?ConditionPartage $conditionPartage, RevenuPourCourtier $revenu, $addressedTo): float
    {
        if (!$conditionPartage) return 0.0;
        $piste = $revenu->getCotation()?->getPiste();
        if (!$piste) return 0.0;

        if (!$this->conditionFranchitSonSeuil($conditionPartage, $revenu, $addressedTo)) {
            return 0.0;
        }

        return $this->calculerRetroCommission(
            $piste->getRisque(),
            $conditionPartage,
            $this->getRevenuMontantPure($revenu, $addressedTo, true),
        );
    }

    /**
     * La condition passe-t-elle son SEUIL pour ce revenu ?
     *
     * Source unique de la formule, partagée par les deux consommateurs : le taux de
     * rétrocommission RÉELLEMENT appliqué (RevenuPourCourtierIndicatorStrategy) et les
     * indicateurs d'impact de la rubrique Condition de partage. Ils l'évaluaient
     * séparément — et l'un d'eux ne l'évaluait pas du tout.
     *
     * L'unité de mesure était auparavant lue dans un tableau toujours vide, donc toujours
     * nulle. Les deux formules n'en étaient pas neutralisées de la même façon, ce qui
     * rendait la panne discrète : « assiette < seuil » se vérifiait TOUJOURS (zéro est
     * inférieur à tout seuil positif) et « assiette >= seuil » JAMAIS.
     */
    public function conditionFranchitSonSeuil(ConditionPartage $condition, RevenuPourCourtier $revenu, $addressedTo = -1): bool
    {
        $formule = $condition->getFormule();
        if ($formule === ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL) {
            return true;
        }

        $seuil = (float) $condition->getSeuil();
        $uniteMesure = $this->sommeCommissionPureDeLUnite($condition, $revenu->getCotation(), $addressedTo, true);

        return match ($formule) {
            ConditionPartage::FORMULE_ASSIETTE_INFERIEURE_AU_SEUIL => $uniteMesure < $seuil,
            ConditionPartage::FORMULE_ASSIETTE_AU_MOINS_EGALE_AU_SEUIL => $uniteMesure >= $seuil,
            default => false,
        };
    }

    /**
     * Le montant « pur » d'un revenu : son HT moins la taxe due par le courtier.
     *
     * LES DEUX FILTRES ÉTAIENT PERDUS EN SILENCE. La méthode ne déclarait qu'un
     * paramètre, mais trois lui étaient passés depuis
     * getRevenuMontantRetrocommissionsPayableParCourtier, applyRevenuConditionsSpeciales
     * et ConditionPartageIndicatorStrategy. PHP tolère les arguments surnuméraires : le
     * redevable visé et la restriction aux revenus PARTAGEABLES étaient donc écrits dans
     * les appels, et jamais appliqués. Conséquence concrète : un revenu NON partageable
     * gonflait l'assiette et la rétrocommission d'une condition de partage — alors que
     * « non partageable » veut précisément dire qu'il n'est pas partagé avec le partenaire.
     *
     * Les deux filtres suivent exactement les règles de leurs équivalents à l'échelle de
     * la cotation (computeCommissionHt / getRevenuMontantHtAddressedTo), et leurs valeurs
     * par défaut ne filtrent rien : les appelants qui ne passent qu'un revenu obtiennent
     * le même résultat qu'avant.
     *
     * @param int|string $addressedTo redevable visé, -1 pour ne pas filtrer
     * @param bool $onlySharable true = seuls les revenus partageables comptent
     */
    public function getRevenuMontantPure(RevenuPourCourtier $revenu, $addressedTo = -1, bool $onlySharable = false): float
    {
        $typeRevenu = $revenu->getTypeRevenu();
        if ($onlySharable && (!$typeRevenu || !$typeRevenu->isShared())) {
            return 0.0;
        }

        $montantHT = $this->getRevenuMontantHtAddressedTo($addressedTo, $revenu);
        $taxeCourtier = $this->serviceTaxes->getMontantTaxe($montantHT, $this->isIARD($revenu->getCotation()), false);
        return $montantHT - $taxeCourtier;
    }

    /**
     * NOUVEAU : Calcule le montant de la taxe courtier pour un revenu spécifique.
     *
     * @param RevenuPourCourtier $revenu
     * @return float
     */
    public function getRevenuMontantTaxeCourtier(RevenuPourCourtier $revenu): float
    {
        $montantHT = $this->getRevenuMontantHt($revenu);
        return $this->serviceTaxes->getMontantTaxe($montantHT, $this->isIARD($revenu->getCotation()), false); // false = Taxe Courtier
    }

    /**
     * NOUVEAU : Calcule le montant de la taxe assureur pour un revenu spécifique.
     */
    public function getRevenuMontantTaxeAssureur(RevenuPourCourtier $revenu): float
    {
        $montantHT = $this->getRevenuMontantHt($revenu);
        return $this->serviceTaxes->getMontantTaxe($montantHT, $this->isIARD($revenu->getCotation()), true); // true = Taxe Assureur
    }

    public function calculerRetroCommission(?Risque $risque, ?ConditionPartage $conditionPartage, $assiette): float
    {
        if (!$conditionPartage || !$risque) return 0.0;
        $taux = $conditionPartage->getFraction();
        $produitsCible = $conditionPartage->getProduits();

        switch ($conditionPartage->getCritereRisque()) {
            case ConditionPartage::CRITERE_EXCLURE_TOUS_CES_RISQUES:
                if (!$produitsCible->contains($risque)) return $assiette * $taux;
                return 0.0;
            case ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES:
                if ($produitsCible->contains($risque)) return $assiette * $taux;
                return 0.0;
            case ConditionPartage::CRITERE_PAS_RISQUES_CIBLES:
                return $assiette * $taux;
        }
        return 0.0;
    }

    public function getCotationMontantRetrocommissionsPayableParCourtierPayee(?Cotation $cotation, ?Partenaire $partenaireCible): float
    {
        $montant = 0;
        if ($cotation != null) {
            $partenaire = $this->getCotationPartenaire($cotation);
            if ($partenaire) {
                if ($this->isSamePartenaire($partenaire, $partenaireCible)) {
                    foreach ($cotation->getTranches() as $tranche) {
                        $montant += $this->getTrancheMontantRetrocommissionsPayableParCourtierPayee($tranche, $partenaireCible);
                    }
                }
            }
        }
        return $montant;
    }

    public function getTrancheMontantRetrocommissionsPayableParCourtierPayee(?Tranche $tranche, ?Partenaire $partenaireCible = null): float
    {
        $montant = 0;
        if (!$tranche || $tranche->getArticles()->isEmpty()) return 0.0;

        if ($this->isSamePartenaire($this->getTranchePartenaire($tranche), $partenaireCible)) {
            foreach ($tranche->getArticles() as $article) {
                $note = $article->getNote();
                if (!$note) continue;

                $montantPayableNote = $this->getNoteMontantPayable($note);
                $proportionPaiement = 0;
                if ($montantPayableNote > 0) {
                    $proportionPaiement = $this->getNoteMontantPaye($note) / $montantPayableNote;
                }

                if ($note->getAddressedTo() == Note::TO_PARTENAIRE) {
                    $montant += $proportionPaiement * $this->getArticleMontant($article);
                }
            }
        }
        return $montant;
    }

    public function getTranchePartenaire(?Tranche $tranche)
    {
        if ($tranche != null) {
            if ($tranche->getCotation() != null) {
                return $this->getCotationPartenaire($tranche->getCotation());
            }
        }
        return null;
    }

    public function getNotificationSinistreCompensation(NotificationSinistre $sinistre): float
    {
        return array_reduce($sinistre->getOffreIndemnisationSinistres()->toArray(), function ($carry, OffreIndemnisationSinistre $offre) {
            return $carry + ($offre->getMontantPayable() ?? 0);
        }, 0.0);
    }

    public function getNotificationSinistreCompensationVersee(NotificationSinistre $sinistre): float
    {
        return array_reduce($sinistre->getOffreIndemnisationSinistres()->toArray(), function ($carry, OffreIndemnisationSinistre $offre) {
            return $carry + $this->getOffreIndemnisationCompensationVersee($offre);
        }, 0.0);
    }

    public function getOffreIndemnisationCompensationVersee(OffreIndemnisationSinistre $offre_indemnisation): float
    {
        return array_reduce($offre_indemnisation->getPaiements()->toArray(), function ($carry, Paiement $paiement) {
            return $carry + ($paiement->getMontant() ?? 0);
        }, 0.0);
    }

    public function getNotificationSinistreDateDernierReglement(NotificationSinistre $sinistre): ?\DateTimeInterface
    {
        $dateDernierReglement = null;
        foreach ($sinistre->getOffreIndemnisationSinistres() as $offre) {
            foreach ($offre->getPaiements() as $paiement) {
                if ($paiement->getPaidAt() && (!$dateDernierReglement || $paiement->getPaidAt() > $dateDernierReglement)) {
                    $dateDernierReglement = $paiement->getPaidAt();
                }
            }
        }
        return $dateDernierReglement;
    }

    public function getCotationMontantPrimePayableParClientPayee(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation) {
            foreach ($cotation->getTranches() as $tranche) {
                $montant += $this->getTranchePrimePayee($tranche);
            }
        }
        return $montant;
    }

    public function getTranchePrimePayee(Tranche $tranche): float
    {
        $montant = 0.0;
        foreach ($tranche->getArticles() as $article) {
            $note = $article->getNote();
            if ($note && $note->getAddressedTo() === Note::TO_CLIENT) {
                $montantPayableNote = $this->getNoteMontantPayable($note);
                if ($montantPayableNote > 0) {
                    $proportionPaiement = $this->getNoteMontantPaye($note) / $montantPayableNote;
                    $montant += $proportionPaiement * $this->getArticleMontant($article);
                }
            }
        }

        // Marché par défaut (l'ASSUREUR facture et encaisse la prime) : les paiements
        // SIGNALÉS par le courtier (PaiementPrime, purement déclaratifs — jamais sa
        // trésorerie) comptent comme prime payée : ils déclenchent l'exigibilité de
        // la commission de courtage.
        $montant += $this->getTranchePrimeDeclareePayee($tranche);

        // Inférences bordereau — faits dérivés (aucun PaiementPrime créé, se rétractent
        // seuls si l'encaissement source est annulé), plafonnés par max() contre tout
        // double comptage avec les paiements signalés :
        // 1. notes ASSUREUR de la tranche soldées → commission reversée → l'assureur
        //    détenait la prime ;
        // 2. tranche couverte par une ligne RÉCONCILIÉE d'un bordereau de production
        //    (analysisResults, type « match ») : le bordereau atteste que l'assureur a
        //    encaissé la prime de l'avenant — sans articles, le bordereau porte déjà
        //    l'information.
        if ($this->isTrancheCommissionAssureurSoldee($tranche) || $this->isTrancheCouverteParBordereau($tranche)) {
            $prime = $this->getCotationMontantPrimePayableParClient($tranche->getCotation())
                * $this->getTrancheTauxFactor($tranche);
            $montant = max($montant, $prime);
        }

        return $montant;
    }

    /**
     * Vrai si la tranche est facturée à l'ASSUREUR pour au moins une commission
     * (typiquement la note liée à un bordereau de production) et que ces notes sont
     * intégralement encaissées, au prorata des articles de la tranche. Même filtre
     * d'articles que getTrancheMontantCommissionEncaissee, restreint au flux assureur.
     */
    public function isTrancheCommissionAssureurSoldee(Tranche $tranche): bool
    {
        $facture = 0.0;
        $encaisse = 0.0;
        foreach ($tranche->getArticles() as $article) {
            $note = $article->getNote();
            if (!$note || !$article->getRevenuFacture() || $note->getAddressedTo() !== Note::TO_ASSUREUR) {
                continue;
            }
            $montantArticle = $this->getArticleMontant($article);
            $facture += $montantArticle;
            $montantPayableNote = $this->getNoteMontantPayable($note);
            if ($montantPayableNote > 0) {
                $encaisse += ($this->getNoteMontantPaye($note) / $montantPayableNote) * $montantArticle;
            }
        }

        return round($facture, 2) > 0 && round($encaisse, 2) >= round($facture, 2);
    }

    /**
     * Vrai si un avenant de la cotation de la tranche est attesté par une ligne
     * RÉCONCILIÉE (type « match ») d'un bordereau de production : l'assureur y déclare
     * avoir encaissé la prime de l'avenant. Avec $exigerSolde, exige en plus que le
     * bordereau soit intégralement encaissé (commission effectivement reversée) —
     * c'est le miroir « sans articles » de l'encaissement par notes.
     */
    public function isTrancheCouverteParBordereau(Tranche $tranche, bool $exigerSolde = false): bool
    {
        $cotation = $tranche->getCotation();
        $entreprise = $tranche->getEntreprise();
        if (!$cotation || !$entreprise) {
            return false;
        }

        $couverture = $this->getCouvertureBordereaux($entreprise);
        $cible = $exigerSolde ? $couverture['couvertsSoldes'] : $couverture['couverts'];
        if ($cible === []) {
            return false;
        }

        foreach ($cotation->getAvenants() as $avenant) {
            if (isset($cible[(int) $avenant->getId()])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Montant de commission qu'un bordereau de production a réellement fait rentrer SUR
     * CETTE TRANCHE, par imputation sur les plus anciennes (cf. getCouvertureBordereaux).
     * Zéro tant que le règlement n'est pas descendu jusqu'à elle.
     */
    public function getTrancheCommissionAllouee(Tranche $tranche): float
    {
        $entreprise = $tranche->getEntreprise();
        if (!$entreprise || $tranche->getId() === null) {
            return 0.0;
        }

        return $this->getCouvertureBordereaux($entreprise)['allocation'][(int) $tranche->getId()] ?? 0.0;
    }

    /**
     * Construit (et met en cache par entreprise) la couverture des avenants par les
     * bordereaux de production : seules comptent les lignes d'analysisResults de type
     * « match » (correspondance parfaite ou réconciliée après création/mise à jour) —
     * une ligne « discrepancy » reste en litige et n'atteste rien. « Soldé » = somme
     * des paiements des notes du bordereau ≥ commission TTC RÉELLE agrégée de TOUS les
     * avenants réconciliés (même calcul que BordereauIndicatorStrategy::getMontantsFromAvenants,
     * PAS Bordereau.montantComHtPayableNow/montantTaxePayableNow — un champ auto-déclaré,
     * pensé pour l'affichage d'un solde bordereau, qui peut sous-évaluer le vrai total dû
     * si le bordereau a accumulé des réconciliations sans être remis à jour. Comparer
     * l'encaissé à ce champ ferait passer « soldé » un bordereau dont seule une fraction
     * du dû réel a été perçue, et gonflerait la « commission encaissée » de CHAQUE avenant
     * matché à son montant plein — un seul paiement partiel se retrouvant ainsi compté en
     * entier sur chacun d'eux (constaté : 166 463 $ de commission encaissée inférée pour
     * un unique paiement réel de 75 908 $ sur le bordereau).
     *
     * `allocation` répartit ce qui a été RÉELLEMENT encaissé entre les tranches couvertes.
     * La part de chaque POLICE est EXACTE : les lignes d'analyse persistent ce que l'assureur
     * déclare régler sur elle (commission_ht_payable_now + taxe_commission_payable_now), et
     * il suffit d'y appliquer le taux de règlement de la note. Seule la ventilation ENTRE LES
     * TRANCHES d'une même police reste une règle — imputation sur les plus anciennes —, parce
     * que le bordereau raisonne par police et ne dit rien de ses échéances internes.
     *
     * Trois répartitions ont été essayées avant celle-ci, toutes fausses faute d'utiliser la
     * déclaration par police, qui n'était pas conservée :
     *  - créditer chaque avenant de son montant PLEIN dès que le bordereau est soldé :
     *    un paiement unique de 75 908 $ produisait 166 463 $ de commission encaissée ;
     *  - n'admettre que le solde INTÉGRAL : un bordereau réglé en partie ne créditait plus
     *    rien, et l'argent réellement rentré disparaissait de tous les indicateurs ;
     *  - répartir au PRORATA : le total redevenait juste, mais aucune tranche n'était JAMAIS
     *    soldée tant que le bordereau ne l'était pas — le filtre « commission payée » ne
     *    pouvait alors structurellement rien renvoyer.
     * Un bordereau analysé AVANT la persistance des montants garde la troisième variante
     * corrigée (imputation globale sur les plus anciennes), en repli.
     *
     * @return array{couverts: array<int, true>, couvertsSoldes: array<int, true>, allocation: array<int, float>, parAvenant: array<int, array{reclame: float, encaisse: float}>}
     */
    private function getCouvertureBordereaux(Entreprise $entreprise): array
    {
        $entrepriseId = (int) $entreprise->getId();
        if (isset($this->couvertureBordereauxCache[$entrepriseId])) {
            return $this->couvertureBordereauxCache[$entrepriseId];
        }

        $couverts = [];
        $couvertsSoldes = [];
        $allocation = [];
        $parAvenant = [];
        $bordereaux = $this->em->getRepository(Bordereau::class)->findBy([
            'entreprise' => $entreprise,
            'type' => Bordereau::TYPE_BOREDERAU_PRODUCTION,
        ]);
        foreach ($bordereaux as $bordereau) {
            // Ce que l'assureur déclare régler POUR CHAQUE POLICE, si l'analyse l'a
            // persisté (cf. BordereauController::ligneAnalyseAPersister). Les bordereaux
            // analysés avant cette version n'ont que les clés de repérage : ils gardent
            // l'ancien régime, décidé ici POUR TOUT LE BORDEREAU — jamais ligne à ligne,
            // sous peine de mélanger deux modes de répartition sur un même règlement.
            $avenantIds = [];
            $reclameParAvenant = [];
            $porteLesMontants = false;
            foreach ($bordereau->getAnalysisResults() ?? [] as $ligne) {
                if (array_key_exists('commission_ht_payable_now', $ligne)) {
                    $porteLesMontants = true;
                }
                if (($ligne['type'] ?? null) !== 'match' || empty($ligne['avenant_id'])) {
                    continue; // « new » : pas d'avenant ; « discrepancy » : en litige, n'atteste rien.
                }
                $avenantId = (int) $ligne['avenant_id'];
                $avenantIds[] = $avenantId;
                $reclameParAvenant[$avenantId] = ($reclameParAvenant[$avenantId] ?? 0.0)
                    + (float) ($ligne['commission_ht_payable_now'] ?? 0)
                    + (float) ($ligne['taxe_commission_payable_now'] ?? 0);
            }
            if ($avenantIds === []) {
                continue;
            }

            // Cotations couvertes, DÉDUPLIQUÉES : la commission est portée par la cotation,
            // pas par l'avenant — deux avenants d'une même cotation (l'initial et une
            // modification) réconciliés par le même bordereau ne doublent pas son dû.
            // Sans cette déduplication, une cotation de 76 656 $ était comptée deux fois.
            $cotationParAvenant = [];
            foreach ($this->em->getRepository(Avenant::class)->findBy(['id' => $avenantIds]) as $avenant) {
                if ($cotation = $avenant->getCotation()) {
                    $cotationParAvenant[(int) $avenant->getId()] = (int) $cotation->getId();
                }
            }
            $cotationIds = array_values(array_unique($cotationParAvenant));
            if ($cotationIds === []) {
                continue;
            }

            // Les tranches de ces cotations, groupées par cotation et triées de la PLUS
            // ANCIENNE échéance à la plus récente : l'ordre d'imputation. `payableAt` sert
            // de repli quand l'échéance manque, l'identifiant départage à date égale
            // (déterminisme). Le tout à plat sert au régime de repli.
            $parCotation = [];
            $aImputer = [];
            foreach ($this->em->getRepository(Tranche::class)->findBy(['cotation' => $cotationIds]) as $tranche) {
                $entree = [
                    'id' => (int) $tranche->getId(),
                    'du' => round(
                        $this->getCotationMontantCommissionTtc($tranche->getCotation(), -1, false)
                        * $this->getTrancheTauxFactor($tranche),
                        2
                    ),
                    'quand' => ($tranche->getEcheanceAt() ?? $tranche->getPayableAt())?->getTimestamp() ?? PHP_INT_MAX,
                ];
                $parCotation[(int) $tranche->getCotation()->getId()][] = $entree;
                $aImputer[] = $entree;
            }
            $trierParAnciennete = static function (array &$lignes): void {
                usort($lignes, static fn (array $a, array $b) => $a['quand'] <=> $b['quand'] ?: $a['id'] <=> $b['id']);
            };
            $trierParAnciennete($aImputer);
            array_walk($parCotation, $trierParAnciennete);

            // « Soldé » se mesure contre le dû RÉEL des tranches couvertes, jamais contre
            // Bordereau.montantComHtPayableNow — un champ auto-déclaré qui peut sous-évaluer
            // le total si le bordereau a accumulé des réconciliations sans être remis à jour.
            $payable = round(array_sum(array_column($aImputer, 'du')), 2);
            $encaisse = round($this->getBordereauMontantEncaisse($bordereau), 2);
            $estSolde = $payable > 0 && $encaisse >= $payable;

            foreach ($avenantIds as $avenantId) {
                $couverts[$avenantId] = true;
                if ($estSolde) {
                    $couvertsSoldes[$avenantId] = true;
                }
            }

            if (!$porteLesMontants) {
                // REPLI (bordereau analysé avant la persistance des montants) : le règlement
                // est imputé sur les plus anciennes tranches couvertes, toutes polices
                // confondues. Approximation assumée, faute de la déclaration par police.
                $this->imputerSurLesPlusAnciennes($allocation, $encaisse, $aImputer);
                continue;
            }

            // RÉGIME EXACT. Le dénominateur est ce que la NOTE réclame — la même base que
            // le paiement —, pas le dû théorique des polices : un bordereau ne facture
            // souvent qu'une partie de la commission des affaires qu'il réconcilie.
            $reclameTotal = round((float) ($bordereau->getMontantPayableNow() ?? 0), 2);
            $taux = $reclameTotal > 0 ? min(1.0, $encaisse / $reclameTotal) : 0.0;
            if ($taux <= 0) {
                continue;
            }

            // La part de chaque POLICE est exacte (sa déclaration × le taux de règlement).
            // Seule sa ventilation ENTRE LES TRANCHES de cette police reste une règle : le
            // bordereau raisonne par police et ne dit rien des échéances internes.
            $reclameParCotation = [];
            foreach ($reclameParAvenant as $avenantId => $reclame) {
                $cotationId = $cotationParAvenant[$avenantId] ?? null;
                if ($cotationId !== null) {
                    $reclameParCotation[$cotationId] = ($reclameParCotation[$cotationId] ?? 0.0) + $reclame;
                }
            }
            // Ce que CE bordereau doit placer au total : jamais plus qu'il n'a encaissé.
            $aPlacer = min($encaisse, round(array_sum($reclameParCotation) * $taux, 2));
            $avant = array_sum($allocation);

            foreach ($reclameParCotation as $cotationId => $reclame) {
                $this->imputerSurLesPlusAnciennes(
                    $allocation,
                    round($reclame * $taux, 2),
                    $parCotation[$cotationId] ?? [],
                );
            }

            // RELIQUAT NON ATTRIBUABLE À SA POLICE. L'assureur déclare parfois régler, sur
            // une police, plus que la commission que nous lui calculons — ici 42 polices sur
            // 51, dans un rapport de 1,16 : leur configuration de revenus ne porte pas la TVA
            // que l'assureur, lui, facture. La part excédentaire ne rentre alors dans aucune
            // tranche de cette police. Cet argent est pourtant RÉELLEMENT rentré : le
            // plafonner sans le réaffecter le ferait disparaître du chiffre d'affaires. On le
            // déverse sur les tranches encore dues du bordereau, les plus anciennes d'abord.
            //
            // Le reliquat est MESURÉ sur l'allocation (ce qui restait à placer moins ce qui
            // l'a été), jamais accumulé depuis les appels : un accumulateur surestimait le
            // reste dès qu'une tranche était partagée par deux polices, et faisait déborder
            // le total au-dessus de l'encaissement. Mesuré, le dépassement est impossible.
            $reliquat = round($aPlacer - (array_sum($allocation) - $avant), 2);
            if ($reliquat > 0.005) {
                $this->imputerSurLesPlusAnciennes($allocation, $reliquat, $aImputer);
            }

            // Vue PAR POLICE, pour la fiche de l'avenant : ce que les bordereaux lui ont
            // réclamé, et ce qui est effectivement rentré dessus. Un avenant peut figurer
            // dans plusieurs bordereaux successifs — d'où le cumul.
            foreach ($reclameParAvenant as $avenantId => $reclame) {
                $parAvenant[$avenantId]['reclame'] = ($parAvenant[$avenantId]['reclame'] ?? 0.0) + $reclame;
                $parAvenant[$avenantId]['encaisse'] = ($parAvenant[$avenantId]['encaisse'] ?? 0.0) + $reclame * $taux;
            }
        }

        return $this->couvertureBordereauxCache[$entrepriseId] = [
            'couverts' => $couverts,
            'couvertsSoldes' => $couvertsSoldes,
            'allocation' => $allocation,
            'parAvenant' => $parAvenant,
        ];
    }

    /**
     * Ce que les bordereaux de production ont RÉCLAMÉ à l'assureur sur cette police, et ce
     * qui est effectivement rentré dessus. Lecture directe de la déclaration par police —
     * aucune règle d'imputation n'intervient à ce niveau.
     *
     * @return array{reclame: float, encaisse: float}
     */
    public function getAvenantMontantsBordereau(Avenant $avenant): array
    {
        $entreprise = $avenant->getEntreprise();
        if (!$entreprise || $avenant->getId() === null) {
            return ['reclame' => 0.0, 'encaisse' => 0.0];
        }

        $montants = $this->getCouvertureBordereaux($entreprise)['parAvenant'][(int) $avenant->getId()] ?? [];

        return [
            'reclame' => round((float) ($montants['reclame'] ?? 0), 2),
            'encaisse' => round((float) ($montants['encaisse'] ?? 0), 2),
        ];
    }

    /**
     * Impute un montant sur des tranches déjà triées de la plus ancienne à la plus récente :
     * chacune est soldée à son tour, la dernière servie ne recevant que le reliquat. Les
     * imputations s'ajoutent à celles déjà faites (une tranche peut être couverte par
     * plusieurs bordereaux) sans jamais dépasser son dû.
     *
     * @param array<int, float> $allocation modifié en place (id de tranche => montant)
     * @param array<int, array{id: int, du: float, quand: int}> $lignesTriees
     * @return float Reliquat non imputable (les tranches visées étaient déjà soldées).
     */
    private function imputerSurLesPlusAnciennes(array &$allocation, float $montant, array $lignesTriees): float
    {
        $reste = $montant;
        foreach ($lignesTriees as $ligne) {
            if ($reste <= 0) {
                return 0.0;
            }
            $deja = $allocation[$ligne['id']] ?? 0.0;
            $part = min($reste, max(0.0, $ligne['du'] - $deja));
            if ($part > 0) {
                $allocation[$ligne['id']] = $deja + $part;
                $reste -= $part;
            }
        }

        return max(0.0, $reste);
    }

    /** Somme des paiements de prime SIGNALÉS sur la tranche (trace déclarative). */
    public function getTranchePrimeDeclareePayee(Tranche $tranche): float
    {
        $montant = 0.0;
        foreach ($tranche->getPaiementsPrime() as $paiementPrime) {
            $montant += (float) ($paiementPrime->getMontant() ?? 0.0);
        }

        return $montant;
    }

    public function getCotationMontantCommissionPure(?Cotation $cotation, $addressedTo, bool $onlySharable): float
    {
        $comHT = $this->getCotationMontantCommissionHt($cotation, $addressedTo, $onlySharable);
        $taxeCourtier = $this->getCotationMontantTaxePayableParCourtier($cotation, $onlySharable);
        return $comHT - $taxeCourtier;
    }

    public function getCotationMontantTaxePayableParCourtier(?Cotation $cotation, bool $onlySharable): float
    {
        return $this->getTotalNet($cotation, $onlySharable, false);
    }

    // VOICI LA FONCTION MANQUANTE PRÉCÉDEMMENT
    public function getTotalNet(?Cotation $cotation, bool $onlySharable, bool $isTaxAssureur): float
    {
        if (!$cotation) return 0.0;
        $isIARD = $this->isIARD($cotation);
        $net_payable_par_assureur = $this->getCotationMontantCommissionHt($cotation, TypeRevenu::REDEVABLE_ASSUREUR, $onlySharable);
        $net_payable_par_client = $this->getCotationMontantCommissionHt($cotation, TypeRevenu::REDEVABLE_CLIENT, $onlySharable);
        $net_total = $net_payable_par_assureur + $net_payable_par_client;
        return $this->serviceTaxes->getMontantTaxe($net_total, $isIARD, $isTaxAssureur);
    }

    // --- NOUVELLES MÉTHODES UTILITAIRES POUR LES STRATÉGIES ---

    public function getArticleMontant(Article $article): float
    {
        $revenu = $article->getRevenuFacture();
        $tranche = $article->getTranche();
        $note = $article->getNote();
 
        if (!$note) return 0.0;
 
        // CAS 1 : Note de crédit pour une autorité fiscale (taxe).
        // Le montant doit être le montant de la taxe, en négatif.
        if ($note->getType() === Note::TYPE_NOTE_DE_CREDIT && $note->getAddressedTo() === Note::TO_AUTORITE_FISCALE) {
            $autoriteFiscale = $note->getAutoritefiscale();
            if ($autoriteFiscale && $autoriteFiscale->getTaxe()) {
                $taxe = $autoriteFiscale->getTaxe();
                $montantHTRevenu = $this->getRevenuMontantHt($revenu);
                $isIARD = $this->isIARD($revenu->getCotation());
                $tauxTaxe = $isIARD ? $taxe->getTauxIARD() : $taxe->getTauxVIE();

                $montantTaxe = $montantHTRevenu * (($tauxTaxe ?? 0.0) / 100);
 
                // Le facteur de tranche n'est pertinent que si une tranche est liée.
                $facteurTranche = $tranche ? $this->getTrancheTauxFactor($tranche) : 1.0;
                $quantite = $article->getQuantite() ?? 1.0;
 
                return abs($montantTaxe * $quantite * $facteurTranche);
            }
        }
 
        // CAS 2 : Note de crédit pour un partenaire (rétrocommission).
        if ($note->getType() === Note::TYPE_NOTE_DE_CREDIT && $note->getAddressedTo() === Note::TO_PARTENAIRE && $revenu) {
            // On calcule le montant total de la rétrocommission pour le revenu.
            $montantRetroBase = $this->getRevenuMontantRetrocommissionsPayableParCourtier($revenu, null, -1);
            // On applique le prorata de la tranche et la quantité.
            $facteurTranche = $tranche ? $this->getTrancheTauxFactor($tranche) : 1.0;
            $quantite = $article->getQuantite() ?? 1.0;
            return abs($montantRetroBase * $facteurTranche * $quantite);
        }
 
        // CAS 3 : Comportement par défaut pour les autres notes (débit, crédit client/assureur...).
        // Le montant est basé sur le montant TTC du revenu, proportionnellement à la tranche.
        if ($revenu && $tranche) {
            $quantite = $article->getQuantite() ?? 1.0;
            $facteurTranche = $this->getTrancheTauxFactor($tranche);
            $montant = $this->getRevenuMontantTTC($revenu) * $quantite * $facteurTranche;
            return abs($montant);
        }
 
        // Si l'article n'est pas (encore) complètement lié (ex: en cours de création),
        // ou si c'est un article libre sans revenu/tranche, son montant est 0.
        return 0.0;
    }

    /**
     * NOUVEAU : Calcule le montant HT d'un article.
     * Cette méthode est une copie de getArticleMontant, mais utilise getRevenuMontantHt au lieu de getRevenuMontantTTC.
     */
    public function getArticleMontantHT(Article $article): float
    {
        $revenu = $article->getRevenuFacture();
        $tranche = $article->getTranche();
        $note = $article->getNote();

        if (!$note) return 0.0;

        // Pour les notes de crédit (taxe, rétro-commission), le montant est déjà HT.
        if ($note->getType() === Note::TYPE_NOTE_DE_CREDIT) {
            // On réutilise la logique existante qui est correcte pour les crédits.
            return $this->getArticleMontant($article);
        }

        // CAS PAR DÉFAUT : Note de débit (commission, etc.)
        // Le montant est basé sur le montant HT du revenu, proportionnellement à la tranche.
        if ($revenu && $tranche) {
            $quantite = $article->getQuantite() ?? 1.0;
            $facteurTranche = $this->getTrancheTauxFactor($tranche);
            // La seule différence est ici : on appelle getRevenuMontantHt
            $montant = $this->getRevenuMontantHt($revenu) * $quantite * $facteurTranche;
            return abs($montant);
        }

        // Si l'article n'est pas (encore) complètement lié (ex: en cours de création),
        // ou si c'est un article libre sans revenu/tranche, son montant est 0.
        return 0.0;
    }

    /**
     * NOUVEAU : Méthode publique pour accéder à la logique de ServiceTaxes.
     *
     * @param boolean $isIARD
     * @param boolean $isTaxeAssureur
     * @return Taxe|null
     */
    public function getTaxeApplicable(bool $isIARD, bool $isTaxeAssureur): ?\App\Entity\Taxe
    {
        return $this->serviceTaxes->getTaxeApplicable($isIARD, $isTaxeAssureur);
    }

    public function getTrancheTauxFactor(Tranche $tranche): float
    {
        if ($tranche->getPourcentage() !== null && $tranche->getPourcentage() > 0) {
            // Le pourcentage est stocké en POINTS (100 = 100 %) : la part est
            // toujours la fraction dérivée. Source unique = Tranche::getFraction().
            return $tranche->getFraction();
        }
        if ($tranche->getMontantFlat() !== null && $tranche->getMontantFlat() > 0) {
            $cotation = $tranche->getCotation();
            if ($cotation) {
                $primeTotale = $this->getCotationMontantPrimePayableParClient($cotation);
                if ($primeTotale > 0) return $tranche->getMontantFlat() / $primeTotale;
            }
        }
        return 0.0;
    }

    public function getRevenuMontantTTC(RevenuPourCourtier $revenu): float
    {
        $ht = $this->getRevenuMontantHt($revenu);
        $isIARD = $this->isIARD($revenu->getCotation());
        $taxe = $this->serviceTaxes->getMontantTaxe($ht, $isIARD, true); // Taxe Assureur sur TTC
        return $ht + $taxe;
    }

    // --- NOUVELLES MÉTHODES POUR PAIEMENT ---

    public function getPaiementTypePaiement(Paiement $paiement): string
    {
        if ($paiement->getNote() !== null) {
            return 'Prime';
        }
        if ($paiement->getOffreIndemnisationSinistre() !== null) {
            return 'Sinistre';
        }
        return 'N/A';
    }

    public function getPaiementContexte(Paiement $paiement): string
    {
        if ($note = $paiement->getNote()) {
            return $note->getReference() ?? 'N/A';
        }
        if ($offre = $paiement->getOffreIndemnisationSinistre()) {
            return $offre->getNotificationSinistre()?->getReferenceSinistre() ?? 'N/A';
        }
        return 'N/A';
    }

    public function getPaiementMontantPaiement(Paiement $paiement): ?float
    {
        return $paiement->getMontant();
    }

    private function getPaiementCotation(Paiement $paiement): ?Cotation
    {
        if ($note = $paiement->getNote()) {
            if ($note->getArticles()->isEmpty()) {
                return null;
            }
            return $note->getArticles()->first()?->getTranche()?->getCotation();
        }
        if ($offre = $paiement->getOffreIndemnisationSinistre()) {
            $sinistre = $offre->getNotificationSinistre();
            if ($sinistre && $sinistre->getReferencePolice()) {
                // On utilise la méthode existante pour trouver la cotation via la référence de police.
                return $this->cotationRepository->findOneByReferencePolice($sinistre->getReferencePolice());
            }
        }
        return null;
    }

    public function getPaiementReferencePolice(Paiement $paiement): string
    {
        $cotation = $this->getPaiementCotation($paiement);
        return $cotation ? $this->getCotationReferencePolice($cotation) : 'N/A';
    }

    public function getPaiementClientNom(Paiement $paiement): string
    {
        $cotation = $this->getPaiementCotation($paiement);
        return $cotation ? $this->getClientDescriptionFromCotation($cotation) : 'N/A';
    }

    /**
     * NOUVEAU : Méthode déplacée depuis NoteIndicatorStrategy pour être réutilisable.
     * Retourne le nom du destinataire d'une note sous forme de chaîne.
     */
    public function getNoteAddressedToString(?Note $note): ?string
    {
        if ($note === null) return null;

        switch ($note->getAddressedTo()) {
            case Note::TO_CLIENT:
                return $note->getClient()?->getNom() ?? 'Client';

            case Note::TO_ASSUREUR:
                return $note->getAssureur()?->getNom() ?? 'Assureur';

            case Note::TO_PARTENAIRE:
                return $note->getPartenaire()?->getNom() ?? 'Intermédiaire';

            case Note::TO_AUTORITE_FISCALE:
                if ($autorite = $note->getAutoritefiscale()) {
                    $nom = $autorite->getNom();
                    $abbreviation = $autorite->getAbreviation();
                    if ($abbreviation && trim($abbreviation) !== '') {
                        return trim($abbreviation) . ' - ' . $nom;
                    }
                    return $nom;
                }
                return 'Autorité Fiscale';

            default:
                return 'Inconnu';
        }
    }

    /**
     * Calcule le montant total encaissé pour un bordereau, en sommant les paiements des notes associées.
     *
     * @param Bordereau $bordereau
     * @return float
     */
    public function getBordereauMontantEncaisse(Bordereau $bordereau): float
    {
        $totalEncaisse = 0.0;
        foreach ($bordereau->getNotes() as $note) {
            $totalEncaisse += $this->getNoteMontantPaye($note);
        }
        return $totalEncaisse;
    }

    public function preloadCotationRelations(array $cotations): void
    {
        $ids = array_values(array_filter(array_map(fn($c) => $c->getId(), $cotations)));
        if (empty($ids)) return;
        $this->em->createQuery(
            'SELECT c, rev, ch, tr, p, cl, r
             FROM App\Entity\Cotation c
             LEFT JOIN c.revenus rev
             LEFT JOIN c.chargements ch
             LEFT JOIN c.tranches tr
             LEFT JOIN c.piste p
             LEFT JOIN p.client cl
             LEFT JOIN p.risque r
             WHERE c.id IN (:ids)'
        )->setParameter('ids', $ids)->getResult();
    }

    /**
     * Précharge en un nombre FIXE de requêtes tout le graphe que les indicateurs de la
     * liste Avenant parcourent ensuite. Sans cela, chaque ligne déclenchait ses propres
     * chargements paresseux : mesuré à 289 requêtes pour 20 avenants (revenus ×53,
     * chargements ×53, puis 20 chacun pour tranches, articles et paiements de prime).
     *
     * RÈGLE À NE PAS ENFREINDRE : une seule collection to-many par requête. Joindre
     * deux to-many dans la même requête (p. ex. revenus ET chargements) produit un
     * produit cartésien dont le coût croît en O(n×m) et annule le gain.
     *
     * Reste volontairement hors périmètre : notification_sinistre, chargé par
     * `reference_police` (recherche par valeur, pas une association) et donc non
     * groupable ici.
     */
    public function preloadAvenantRelations(array $avenants): void
    {
        $this->preloadDepuisCotationIds(array_map(fn($a) => $a->getCotation()?->getId(), $avenants));
    }

    /**
     * LE GRAPHE D'UNE PAGE ENTIÈRE, LU EN UNE PASSE.
     *
     * Sept rubriques — Partenaire, Client, Assureur, Risque, Groupe, Portefeuille et
     * Contact — affichent des colonnes qui viennent toutes de getIndicateursGlobaux(),
     * appelé UNE FOIS PAR LIGNE avec une cible différente. Chaque appel relisait donc
     * son propre sous-graphe, alors que les vingt lignes d'une page partagent
     * l'essentiel du portefeuille.
     *
     * On lit ici, en une seule requête, l'union des cotations concernées par TOUTES les
     * cibles de la page, puis on hydrate leur graphe une fois pour toutes. Les appels
     * par ligne qui suivent retrouvent tout en mémoire : le mémo de
     * preloadDepuisCotationIds() leur évite de recharger, et le cache des sinistres leur
     * évite de relire l'entreprise.
     *
     * CE QUI N'EST PAS FAIT ICI, ET POURQUOI. On ne pré-calcule PAS les agrégats par
     * cible. Les montants ne sont pas des sommes SQL : ils dérivent d'un prorata de
     * notes, d'un max() entre articles et couverture bordereau, et d'une imputation FIFO
     * sur les tranches les plus anciennes. Les recomposer en dehors de la boucle
     * d'origine, ce serait ouvrir une seconde source de vérité financière. Ce qui est
     * groupé, c'est la LECTURE — l'arithmétique reste exactement où elle était.
     *
     * @param object[] $cibles entités d'une même rubrique (partenaires, clients…)
     */
    public function preloadIndicateursGlobauxParCible(Entreprise $entreprise, string $cleOption, array $cibles): void
    {
        $cibles = array_values(array_filter($cibles, static fn (object $c) => method_exists($c, 'getId') && $c->getId() !== null));
        if ($cibles === []) {
            return;
        }

        $qb = $this->cotationRepository->createQueryBuilder('c')
            ->select('c')
            ->join('c.piste', 'p')
            ->join('p.invite', 'i')
            ->where('i.entreprise = :entreprise')
            ->andWhere('SIZE(c.avenants) > 0')
            ->setParameter('entreprise', $entreprise)
            ->setParameter('cibles', $cibles)
            ->distinct();

        // Les mêmes prédicats que les filtres unitaires, au pluriel. Une imprécision
        // serait ici sans conséquence sur les chiffres — un préchargement trop large ne
        // fait que charger un peu trop — mais les garder alignés évite qu'une page ne
        // précharge à côté de ce que les lignes vont réellement lire.
        switch ($cleOption) {
            case 'partenaireCible':
                $qb->leftJoin('p.client', 'cl')
                    ->leftJoin('p.partenaires', 'pa')
                    ->leftJoin('cl.partenaires', 'clpa')
                    ->andWhere('pa IN (:cibles) OR clpa IN (:cibles)');
                break;
            case 'clientCible':
                $qb->andWhere('p.client IN (:cibles)');
                break;
            case 'assureurCible':
                $qb->andWhere('c.assureur IN (:cibles)');
                break;
            case 'risqueCible':
                $qb->andWhere('p.risque IN (:cibles)');
                break;
            case 'groupeCible':
                $qb->join('p.client', 'cl_g')->andWhere('cl_g.groupe IN (:cibles)');
                break;
            case 'portefeuilleCible':
                $qb->join('p.client', 'cl_pf')->andWhere('cl_pf.portefeuille IN (:cibles)');
                break;
            default:
                return; // Rubrique non agrégatrice : rien à précharger de ce côté.
        }

        $cotations = $qb->getQuery()->getResult();
        $this->preloadDepuisCotationIds(array_map(static fn (Cotation $c) => $c->getId(), $cotations));

        // Les sinistres se lisent par référence de police, jamais par association : une
        // lecture par entreprise, puis un filtre en mémoire pour chaque ligne.
        $this->sinistresDeLEntreprise($entreprise);

        $this->preloadParcoursDeLaRubrique($cleOption, $cibles);
    }

    /**
     * Les collections que chaque rubrique agrégatrice parcourt POUR SON PROPRE COMPTE,
     * en plus de l'agrégat : compter les polices d'un client, résumer les conditions de
     * partage d'un partenaire, attribuer une commission de bordereau…
     *
     * Ces parcours descendent tous vers « pistes → cotations → avenants », et ils
     * atteignent des cotations que l'agrégat ignore volontairement (les propositions non
     * souscrites). Sans ce préchargement, ils rallumaient un chargement paresseux par
     * ligne et par niveau : mesuré à 59 lectures de `cotation` pour dix clients.
     */
    private const COLLECTIONS_DE_RUBRIQUE = [
        'partenaireCible'   => [Partenaire::class, ['pistes', 'clients', 'conditionPartages']],
        // Les pistes des clients sont chargées plus bas, pour TOUS les chemins qui
        // mènent à un client : les redemander ici ferait deux fois la même requête.
        'clientCible'       => [Client::class, []],
        'assureurCible'     => [Assureur::class, ['cotations']],
        'risqueCible'       => [Risque::class, ['pistes']],
        'groupeCible'       => [Groupe::class, ['clients']],
        'portefeuilleCible' => [Portefeuille::class, ['clients']],
    ];

    /**
     * @param object[] $cibles
     */
    private function preloadParcoursDeLaRubrique(string $cleOption, array $cibles): void
    {
        if (!isset(self::COLLECTIONS_DE_RUBRIQUE[$cleOption])) {
            return;
        }
        [$classe, $collections] = self::COLLECTIONS_DE_RUBRIQUE[$cleOption];

        $ids = array_map(static fn (object $c) => $c->getId(), $cibles);
        $clients = [];
        $pistes = [];
        $cotations = [];
        $conditions = [];

        // UNE SEULE COLLECTION TO-MANY PAR REQUÊTE : joindre `pistes` et `clients`
        // ensemble produirait le produit cartésien que tout ce chantier combat.
        foreach ($collections as $collection) {
            $lus = $this->em->createQuery(
                sprintf('SELECT e, x FROM %s e LEFT JOIN e.%s x WHERE e.id IN (:ids)', $classe, $collection)
            )->setParameter('ids', $ids)->getResult();

            foreach ($lus as $entite) {
                foreach ($entite->{'get' . ucfirst($collection)}() as $lie) {
                    match ($collection) {
                        'pistes'            => $pistes[] = $lie,
                        'clients'           => $clients[] = $lie,
                        'cotations'         => $cotations[] = $lie,
                        'conditionPartages' => $conditions[] = $lie,
                        default             => null,
                    };
                }
            }
        }

        if ($cleOption === 'clientCible') {
            $clients = $cibles;
        }

        // Les conditions de partage portent leurs risques ciblés : le résumé affiché en
        // liste les énumère, donc les charge.
        if ($conditions !== []) {
            $this->em->createQuery(
                'SELECT cp, pr FROM App\Entity\ConditionPartage cp LEFT JOIN cp.produits pr WHERE cp.id IN (:ids)'
            )->setParameter('ids', $this->identifiants($conditions))->getResult();
        }

        if ($clients !== []) {
            foreach ($this->em->createQuery(
                'SELECT cl, p FROM App\Entity\Client cl LEFT JOIN cl.pistes p WHERE cl.id IN (:ids)'
            )->setParameter('ids', $this->identifiants($clients))->getResult() as $client) {
                foreach ($client->getPistes() as $piste) {
                    $pistes[] = $piste;
                }
            }
        }

        if ($pistes !== []) {
            foreach ($this->em->createQuery(
                'SELECT p, c FROM App\Entity\Piste p LEFT JOIN p.cotations c WHERE p.id IN (:ids)'
            )->setParameter('ids', $this->identifiants($pistes))->getResult() as $piste) {
                foreach ($piste->getCotations() as $cotation) {
                    $cotations[] = $cotation;
                }
            }
        }

        if ($cotations !== []) {
            $ids = $this->identifiants($cotations);

            $this->em->createQuery(
                'SELECT c, a FROM App\Entity\Cotation c LEFT JOIN c.avenants a WHERE c.id IN (:ids)'
            )->setParameter('ids', $ids)->getResult();

            // ET LEUR GRAPHE FINANCIER. L'agrégat ne connaît que les cotations
            // SOUSCRITES ; les parcours de la rubrique, eux, descendent aussi dans les
            // propositions en cours — dont les revenus et les chargements se
            // rechargeaient alors une par une. Le mémo garantit que les cotations déjà
            // vues par l'agrégat ne sont pas relues ici.
            $this->preloadDepuisCotationIds($ids);
        }
    }

    /**
     * @param object[] $entites
     *
     * @return int[]
     */
    private function identifiants(array $entites): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (object $e) => $e->getId(), $entites))));
    }

    /**
     * Même préchargement, mais amorcé depuis des TRANCHES au lieu d'avenants : les
     * indicateurs d'une tranche (prime, commission HT/TTC, taxes sur la commission,
     * rétrocommission) parcourent exactement le même graphe, puisqu'ils se lisent tous
     * sur la cotation portante. Sert aux outils de l'assistant qui hydratent un LOT de
     * tranches désignées par autre chose qu'elles-mêmes — un signalement de paiement de
     * prime, par exemple.
     *
     * @param Tranche[] $tranches
     */
    public function preloadTrancheRelations(array $tranches): void
    {
        $this->preloadDepuisCotationIds(array_map(fn($t) => $t->getCotation()?->getId(), $tranches));
    }

    /**
     * Le préchargement lui-même, partagé par les deux entrées ci-dessus : la COTATION est
     * la racine réelle du graphe des indicateurs, l'entité par laquelle on y arrive n'est
     * qu'un chemin d'accès.
     *
     * @param array<int, int|null> $cotationIds identifiants bruts, doublons et nuls admis
     */
    private function preloadDepuisCotationIds(array $cotationIds): void
    {
        $cotationIds = array_values(array_unique(array_filter($cotationIds)));

        // CE QUI EST DÉJÀ CHAUD NE SE RECHARGE PAS. Vingt lignes d'une rubrique partagent
        // très largement le même sous-graphe : sans ce filtre, chacune rejouait les six
        // requêtes ci-dessous pour des cotations déjà hydratées.
        // La revalidation contre l'identity map n'est pas une précaution de style : un
        // em->clear() détache tout, et un mémo non revalidé ferait retomber la page dans
        // le chargement paresseux ligne à ligne.
        $uow = $this->em->getUnitOfWork();
        $cotationIds = array_values(array_filter(
            $cotationIds,
            function (int $id) use ($uow): bool {
                if (!isset($this->cotationsPrechargees[$id])) {
                    return true;
                }
                if ($uow->tryGetById($id, Cotation::class) !== false) {
                    return false;
                }
                unset($this->cotationsPrechargees[$id]);

                return true;
            },
        ));

        if (empty($cotationIds)) return;

        foreach ($cotationIds as $id) {
            $this->cotationsPrechargees[$id] = true;
        }

        // 1. Graphe to-one + partenaires (une seule collection : partenaires).
        $this->em->createQuery(
            'SELECT c, p, cl, r, par
             FROM App\Entity\Cotation c
             LEFT JOIN c.piste p
             LEFT JOIN p.client cl
             LEFT JOIN p.risque r
             LEFT JOIN p.partenaires par
             WHERE c.id IN (:ids)'
        )->setParameter('ids', $cotationIds)->getResult();

        // 2. Revenus (+ leur type, to-one : sert au calcul des rétrocommissions).
        $this->em->createQuery(
            'SELECT c, rev, trev
             FROM App\Entity\Cotation c
             LEFT JOIN c.revenus rev
             LEFT JOIN rev.typeRevenu trev
             WHERE c.id IN (:ids)'
        )->setParameter('ids', $cotationIds)->getResult();

        // 3. Chargements de prime (+ leur type, to-one).
        $this->em->createQuery(
            'SELECT c, ch, cht
             FROM App\Entity\Cotation c
             LEFT JOIN c.chargements ch
             LEFT JOIN ch.type cht
             WHERE c.id IN (:ids)'
        )->setParameter('ids', $cotationIds)->getResult();

        // 4. Tranches.
        $cotations = $this->em->createQuery(
            'SELECT c, tr
             FROM App\Entity\Cotation c
             LEFT JOIN c.tranches tr
             WHERE c.id IN (:ids)'
        )->setParameter('ids', $cotationIds)->getResult();

        // 5-6. Collections portées par la Tranche. Les identifiants sont relus depuis les
        // tranches déjà hydratées en 4 : aucune requête supplémentaire pour les obtenir.
        $trancheIds = [];
        foreach ($cotations as $cotation) {
            foreach ($cotation->getTranches() as $tranche) {
                if ($tranche->getId() !== null) {
                    $trancheIds[] = $tranche->getId();
                }
            }
        }
        $trancheIds = array_values(array_unique($trancheIds));
        if (empty($trancheIds)) return;

        $this->em->createQuery(
            'SELECT t, a FROM App\Entity\Tranche t
             LEFT JOIN t.articles a
             WHERE t.id IN (:ids)'
        )->setParameter('ids', $trancheIds)->getResult();

        $this->em->createQuery(
            'SELECT t, pp FROM App\Entity\Tranche t
             LEFT JOIN t.paiementsPrime pp
             WHERE t.id IN (:ids)'
        )->setParameter('ids', $trancheIds)->getResult();
    }
}