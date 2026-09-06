<?php

namespace App\Echange\Etat;

use App\Ai\Finance\EconomieTranche;
use App\Echange\Service\Progression;
use App\Entity\Entreprise;
use App\Entity\Taxe;
use App\Entity\Tranche;
use App\Repository\TaxeRepository;
use App\Service\Retro\BeneficiaireRetroFactory;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Service\Partage\Exigibilite;
use App\Service\Partage\Reserve;
use App\Services\Tranche\TranchePaiementService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'ÉTAT DU PORTEFEUILLE : une ligne par tranche, et sur cette ligne tout ce qui décide
 * d'une affaire.
 *
 * ── CE SERVICE NE CALCULE (PRESQUE) RIEN ────────────────────────────────────────────
 * ⚠ C'est sa règle fondatrice. Chaque montant est LU sur les indicateurs déjà posés par
 * TrancheIndicatorStrategy — la source unique qui alimente la rubrique Tranches, ses
 * chips de statut et le suivi des impayés. Un chiffre de ce fichier est donc, au centime
 * près, celui de l'écran. Recalculer ici, fût-ce une soustraction, aurait créé une
 * seconde vérité : le jour où les deux divergent, personne ne sait laquelle croire.
 *
 * Les deux seules opérations faites ici passent par des formules PARTAGÉES et éprouvées :
 * `Reserve::calculer()` pour la réserve du cabinet, `Exigibilite::exigible()` pour la part
 * réclamable d'une taxe.
 *
 * ── CE QU'IL N'EST PAS ──────────────────────────────────────────────────────────────
 * ⚠ CE FICHIER NE SE RÉIMPORTE PAS, et ne le pourra jamais. « Prime payée », « commission
 * encaissée », « réserve », « rétro exigible » ne sont pas des champs mais des RÉSULTATS
 * — la somme de règlements, le produit d'un taux, un solde. Les réécrire n'a pas de sens.
 * Le format d'échange, lui, reste produit par ExportateurJsbx et sert le gabarit.
 *
 * ── LES SINISTRES N'Y SONT PAS, ET C'EST VOULU ──────────────────────────────────────
 * Décision du 06/09/2026. Un sinistre vit à la maille POLICE : porté par une ligne de
 * tranche, le dommage d'une police à quatre tranches serait compté quatre fois, et toute
 * somme de la colonne serait fausse. Ils appelleront une feuille à eux, jamais celle-ci.
 */
final class EtatDuPortefeuille
{
    /** Nom de l'unique feuille de données. */
    public const FEUILLE = 'DONNEES';

    /**
     * Taille des lots d'hydratation.
     *
     * ⚠ `chargerIndicateurs()` porte son propre seuil et journalise un avertissement
     * au-delà : hydrater un portefeuille entier d'un bloc reviendrait à le déclencher à
     * chaque export. On avance donc par paquets, comme l'export d'échange le fait déjà.
     */
    private const LOT = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranchePaiementService $tranchePaiement,
        private readonly PiecesDeReglement $pieces,
        private readonly TaxeRepository $taxes,
        // Le partenaire d'une affaire ne se lit pas sur ses versements — il existe avant
        // le premier virement. On passe donc par le MÊME chemin que les indicateurs.
        private readonly IndicatorCalculationHelper $helper,
        // La condition retenue pour un bénéficiaire : source unique du taux affiché.
        private readonly BeneficiaireRetroFactory $beneficiaires,
    ) {
    }

    /**
     * Les colonnes du fichier, dans l'ordre, indexées par code.
     *
     * Les libellés des taxes portent le NOM de l'autorité fiscale du cabinet : un montant
     * de taxe sans le nom de la taxe ne se rattache à rien.
     *
     * @return array<string, ColonneEtat>
     */
    public function colonnes(Entreprise $entreprise): array
    {
        $courtier = $this->nomDeLaTaxe($entreprise, Taxe::REDEVABLE_COURTIER);
        $assureur = $this->nomDeLaTaxe($entreprise, Taxe::REDEVABLE_ASSUREUR);

        return CatalogueDesColonnes::pour($courtier, $assureur);
    }

    /**
     * Nombre de lignes à produire — pour que la barre de progression annonce un reste
     * crédible plutôt qu'une attente indéfinie.
     */
    public function compterLignes(Entreprise $entreprise): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(Tranche::class, 't')
            ->andWhere('t.entreprise = :entreprise')
            ->setParameter('entreprise', $entreprise)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Les lignes de l'état, par lots hydratés.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function lignes(Entreprise $entreprise, ?Progression $progression = null): \Generator
    {
        $offset = 0;

        while (true) {
            $lot = $this->em->createQueryBuilder()
                ->select('t')
                ->from(Tranche::class, 't')
                ->andWhere('t.entreprise = :entreprise')
                ->setParameter('entreprise', $entreprise)
                ->orderBy('t.id', 'ASC')
                ->setFirstResult($offset)
                ->setMaxResults(self::LOT)
                ->getQuery()
                ->getResult();

            if ($lot === []) {
                break;
            }

            // ⚠ HYDRATER D'ABORD, PROJETER ENSUITE. Sur une tranche non hydratée,
            // EconomieTranche rend un tableau VIDE — et c'est voulu : un zéro se présente,
            // une absence non. Une ligne de zéros serait un mensonge tranquille.
            $this->tranchePaiement->chargerIndicateurs($lot);

            foreach ($lot as $tranche) {
                yield $this->ligne($tranche);
                $progression?->avancer();
            }

            $offset += self::LOT;
            if (\count($lot) < self::LOT) {
                break;
            }
        }
    }

    /**
     * UNE LIGNE. Tout y est lu ; rien n'y est déduit.
     *
     * @return array<string, mixed>
     */
    private function ligne(Tranche $tranche): array
    {
        $cotation = $tranche->getCotation();
        $piste = $cotation?->getPiste();
        $avenant = $cotation?->getAvenants()->first() ?: null;

        $primes = $this->pieces->prime($tranche);
        $commissions = $this->pieces->commission($tranche);
        $taxeCourtier = $this->pieces->taxe($tranche, Taxe::REDEVABLE_COURTIER);
        $taxeAssureur = $this->pieces->taxe($tranche, Taxe::REDEVABLE_ASSUREUR);
        $retroPartenaire = $this->pieces->retro($tranche, false);
        $retroAgent = $this->pieces->retro($tranche, true);

        $commissionTtc = $this->nombre($tranche->montantCalculeTTC);
        $commissionEncaissee = $this->nombre($tranche->montant_paye);
        $pure = $this->commissionPure($tranche);

        return [
            'id' => $tranche->getId(),

            'policeDateEffet' => $avenant?->getStartingAt(),
            'policeEcheance' => $avenant?->getEndingAt(),
            'policeReference' => $avenant?->getReferencePolice(),
            'policeNumeroAvenant' => $avenant?->getNumero(),

            'trancheNom' => $tranche->getNom(),
            'tranchePayableAt' => $tranche->getPayableAt(),
            'trancheEcheanceAt' => $tranche->getEcheanceAt(),

            'assure' => $piste?->getClient()?->getNom(),
            'risque' => $piste?->getRisque()?->getNomComplet() ?: $piste?->getRisque()?->getCode(),
            'assureur' => $cotation?->getAssureur()?->getNom(),

            'primeTotale' => $tranche->primeTranche,
            'primePayee' => $tranche->primeDeclareePayee,
            'primeSolde' => $tranche->primeSoldeDue,
            'primeDerniereLe' => $primes['date'],
            'primeReferences' => $primes['references'],

            'commissionTtc' => $tranche->montantCalculeTTC,
            'commissionHt' => $tranche->montantCalculeHT,
            'commissionEncaissee' => $tranche->montant_paye,
            'commissionSolde' => $tranche->solde_restant_du,
            'commissionExigible' => $tranche->commissionExigible,
            'commissionDerniereLe' => $commissions['date'],
            'commissionReferences' => $commissions['references'],
            'commissionComptes' => $commissions['comptes'],

            'taxeCourtierTaux' => $tranche->taxeCourtierTaux,
            'taxeCourtierMontant' => $tranche->taxeCourtierMontant,
            'taxeCourtierPayee' => $tranche->taxeCourtierPayee,
            'taxeCourtierSolde' => $tranche->taxeCourtierSolde,
            'taxeCourtierPayeeLe' => $taxeCourtier['date'],
            'taxeCourtierReferences' => $taxeCourtier['references'],
            'taxeCourtierExigible' => $this->exigibiliteDeLaTaxe(
                $this->nombre($tranche->taxeCourtierMontant),
                $commissionTtc,
                $commissionEncaissee,
                $this->nombre($tranche->taxeCourtierPayee),
            ),

            'taxeAssureurTaux' => $tranche->taxeAssureurTaux,
            'taxeAssureurMontant' => $tranche->taxeAssureurMontant,
            'taxeAssureurPayee' => $tranche->taxeAssureurPayee,
            'taxeAssureurSolde' => $tranche->taxeAssureurSolde,
            'taxeAssureurPayeeLe' => $taxeAssureur['date'],
            'taxeAssureurReferences' => $taxeAssureur['references'],
            'taxeAssureurExigible' => $this->exigibiliteDeLaTaxe(
                $this->nombre($tranche->taxeAssureurMontant),
                $commissionTtc,
                $commissionEncaissee,
                $this->nombre($tranche->taxeAssureurPayee),
            ),

            'commissionPure' => $pure,
            'reserve' => $pure === null ? null : Reserve::calculer(
                $pure,
                $this->nombre($tranche->retroCommission),
                $this->nombre($tranche->retroAgentDue),
            ),

            'intermediaire' => $this->nomDeLIntermediaire($tranche),
            'intermediairePart' => $this->partDeLIntermediaire($tranche),

            'retroPartenaireDue' => $tranche->retroCommission,
            'retroPartenairePayee' => $tranche->retroCommissionReversee,
            'retroPartenaireSolde' => $tranche->retroCommissionSolde,
            'retroPartenaireExigible' => $tranche->retroCommissionExigible,
            'retroPartenairePayeeLe' => $retroPartenaire['date'],

            'retroAgentDue' => $tranche->retroAgentDue,
            'retroAgentPayee' => $tranche->retroAgentReversee,
            'retroAgentSolde' => $tranche->retroAgentSolde,
            'retroAgentExigible' => $tranche->retroAgentExigible,
            'retroAgentPayeeLe' => $retroAgent['date'],
        ];
    }

    /**
     * COMMISSION PURE : ce qui reste au cabinet avant tout partage — commission HT moins
     * la taxe dont il est lui-même redevable. La définition est celle de `Reserve`, dont
     * l'en-tête fait foi ; on ne la réinvente pas ici, on l'applique.
     *
     * Rend null sur une tranche non hydratée, pour ne pas afficher un zéro qui passerait
     * pour une commission nulle.
     */
    private function commissionPure(Tranche $tranche): ?float
    {
        if ($tranche->montantCalculeHT === null) {
            return null;
        }

        return round($tranche->montantCalculeHT - $this->nombre($tranche->taxeCourtierMontant), 2);
    }

    /**
     * LA PART DE TAXE DEVENUE RÉCLAMABLE, au rythme de la commission encaissée.
     *
     * ⚠ POURQUOI CE PRORATA EST LÉGITIME, LÀ OÙ CELUI DE LA COMMISSION EST INTERDIT.
     * `EconomieTranche::NOTE` interdit de proratiser la COMMISSION sur un règlement
     * partiel de PRIME : la commission n'est réclamable à l'assureur qu'une fois la prime
     * intégralement payée — c'est une condition contractuelle, pas une proportion. La
     * taxe, elle, est DUE SUR UN REVENU PERÇU : elle naît avec l'encaissement et croît
     * avec lui. La proportionnalité n'y est pas un raccourci, elle est la règle.
     *
     * La formule n'est pas écrite ici : `Exigibilite::exigible()` la porte déjà pour les
     * rétrocommissions, avec ses trois garde-fous éprouvés — ratio plafonné à 1, jamais
     * de négatif, et ratio de 1 quand aucune commission n'est attendue (sans quoi la taxe
     * d'une affaire à honoraires purs resterait éternellement inexigible).
     */
    private function exigibiliteDeLaTaxe(float $montant, float $commissionTtc, float $encaissee, float $payee): ?float
    {
        if ($montant <= 0.0) {
            return null;
        }

        return Exigibilite::exigible($montant, $commissionTtc, $encaissee, $payee);
    }

    /**
     * L'INTERMÉDIAIRE DE L'AFFAIRE, nommé.
     *
     * ⚠ On ne le déduit pas des versements : un partenaire existe dès la souscription,
     * bien avant le premier virement. Le lire sur les reversements aurait laissé vide la
     * colonne de toutes les affaires non encore reversées — c'est-à-dire précisément
     * celles qu'on ouvre ce fichier pour retrouver.
     */
    private function nomDeLIntermediaire(Tranche $tranche): ?string
    {
        return $this->helper->getTranchePartenaire($tranche)?->getNom();
    }

    /**
     * Le taux appliqué à l'intermédiaire, lu sur la CONDITION RETENUE.
     *
     * Le taux ne se déduit jamais d'une division entre une rétro et une assiette : le
     * projet s'interdit cette déduction, et le résultat serait faux dès qu'un seuil ou
     * un plafond entre en jeu. On lit la condition que le moteur a effectivement retenue.
     */
    private function partDeLIntermediaire(Tranche $tranche): ?float
    {
        $partenaire = $this->helper->getTranchePartenaire($tranche);
        if ($partenaire === null) {
            return null;
        }

        $condition = $this->beneficiaires->pour($partenaire)->conditionRetenue($tranche->getCotation());

        return $condition?->getTaux();
    }

    /**
     * Le nom de la taxe du cabinet pour ce redevable — abréviation de l'autorité fiscale
     * de préférence, code de la taxe à défaut. Lu UNE fois par fichier, pas par tranche.
     */
    private function nomDeLaTaxe(Entreprise $entreprise, int $redevable): string
    {
        $taxe = $this->taxes->findOneBy(['redevable' => $redevable, 'entreprise' => $entreprise]);
        if ($taxe === null) {
            return $redevable === Taxe::REDEVABLE_COURTIER ? 'Taxe courtier' : 'Taxe assureur';
        }

        $autorite = $taxe->getAutoriteFiscales()->first() ?: null;
        $nom = $autorite ? ($autorite->getAbreviation() ?: $autorite->getNom()) : null;

        return (string) ($nom ?: $taxe->getCode() ?: 'Taxe');
    }

    private function nombre(?float $valeur): float
    {
        return $valeur ?? 0.0;
    }
}
