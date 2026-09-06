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
 * ⚠ IL NE PROJETTE MÊME PAS : c'est `EconomieTranche` qui nomme les indicateurs, et ce
 * service la consomme. Deux lectures des mêmes chiffres, c'étaient deux vérités en sursis
 * — le fichier et le chat auraient fini par se contredire. Ce qui reste ici est ce qui
 * exige des SERVICES : les pièces de règlement, et les noms.
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

    /** Colonne d'identité de la ligne : jamais retirable. */
    public const COLONNE_IDENTITE = 'id';

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
     * @param string[] $retenues codes des colonnes demandées ; vide = toutes
     *
     * @return array<string, ColonneEtat>
     */
    public function colonnes(Entreprise $entreprise, array $retenues = []): array
    {
        $courtier = $this->nomDeLaTaxe($entreprise, Taxe::REDEVABLE_COURTIER);
        $assureur = $this->nomDeLaTaxe($entreprise, Taxe::REDEVABLE_ASSUREUR);
        $catalogue = CatalogueDesColonnes::pour($courtier, $assureur);

        if ($retenues === []) {
            return $catalogue;
        }

        // ⚠ `id` EST TOUJOURS LÀ, demandée ou non : c'est elle qui identifie la ligne, et
        // un état dont on ne peut rattacher aucune ligne à sa tranche n'est plus un état.
        // La garantie est ici et pas seulement à l'écran : une case désactivée n'engage
        // que le navigateur, jamais l'adresse qu'on tape à la main.
        $retenues[] = self::COLONNE_IDENTITE;

        // L'ordre reste celui du CATALOGUE, jamais celui de la demande : deux exports du
        // même périmètre doivent donner deux fichiers superposables.
        return array_filter(
            $catalogue,
            static fn (string $code) => \in_array($code, $retenues, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Les exercices présents chez ce cabinet — dérivés des données, jamais énumérés.
     *
     * @return int[]
     */
    public function exercices(Entreprise $entreprise): array
    {
        return ExerciceDesTranches::annees($this->em, $entreprise);
    }

    /**
     * Nombre de lignes à produire — pour que la barre de progression annonce un reste
     * crédible plutôt qu'une attente indéfinie.
     */
    public function compterLignes(Entreprise $entreprise, ?string $validite = null, string $exercice = ExerciceDesTranches::TOUS): int
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(Tranche::class, 't')
            ->andWhere('t.entreprise = :entreprise')
            ->setParameter('entreprise', $entreprise);

        // ⚠ LE MÊME FILTRE QUE LA LECTURE, sans quoi la barre de progression annoncerait
        // un reste qui n'arrivera jamais — ou atteindrait 100 % avant la fin.
        ValiditeDesTranches::appliquer($qb, 't', $validite);
        ExerciceDesTranches::appliquer($qb, 't', $exercice);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Les lignes de l'état, par lots hydratés.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function lignes(Entreprise $entreprise, ?Progression $progression = null, ?string $validite = null, string $exercice = ExerciceDesTranches::TOUS): \Generator
    {
        $offset = 0;

        while (true) {
            $qb = $this->em->createQueryBuilder()
                ->select('t')
                ->from(Tranche::class, 't')
                ->andWhere('t.entreprise = :entreprise')
                ->setParameter('entreprise', $entreprise)
                ->orderBy('t.id', 'ASC')
                ->setFirstResult($offset)
                ->setMaxResults(self::LOT);

            ValiditeDesTranches::appliquer($qb, 't', $validite);
            ExerciceDesTranches::appliquer($qb, 't', $exercice);

            $lot = $qb->getQuery()->getResult();

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
        $avenant = self::policeDe($cotation);

        $primes = $this->pieces->prime($tranche);
        $commissions = $this->pieces->commission($tranche);
        $taxeCourtier = $this->pieces->taxe($tranche, Taxe::REDEVABLE_COURTIER);
        $taxeAssureur = $this->pieces->taxe($tranche, Taxe::REDEVABLE_ASSUREUR);
        $retroPartenaire = $this->pieces->retro($tranche, false);
        $retroAgent = $this->pieces->retro($tranche, true);

        // ⚠ UNE SEULE PROJECTION DES INDICATEURS, PARTAGÉE AVEC L'ASSISTANT.
        //
        // Relire ici les propriétés de la tranche, comme le faisait ce service, c'était
        // tenir DEUX lectures des mêmes chiffres : elles coïncidaient parce qu'elles
        // avaient été écrites le même jour, et la première correction portée d'un seul
        // côté les aurait séparées — le fichier disant un montant, le chat un autre.
        //
        // EconomieTranche est cette projection, dans le vocabulaire de Ket. Une clé
        // ABSENTE y signifie une absence (tranche non hydratée, taxe non paramétrée,
        // affaire sans partenaire) : on la laisse vide plutôt que d'écrire un zéro, qui
        // se présenterait comme une valeur.
        $eco = EconomieTranche::depuis($tranche);

        return [
            'id' => $tranche->getId(),

            'policeDateEffet' => $avenant?->getStartingAt(),
            'policeEcheance' => $avenant?->getEndingAt(),
            'policeReference' => $avenant?->getReferencePolice(),
            'policeNumeroAvenant' => $avenant?->getNumero(),
            'policeMoisEffet' => self::moisDe($avenant?->getStartingAt()),

            'trancheNom' => $tranche->getNom(),
            'tranchePayableAt' => $tranche->getPayableAt(),
            'trancheEcheanceAt' => $tranche->getEcheanceAt(),

            'assure' => $piste?->getClient()?->getNom(),
            'risque' => $piste?->getRisque()?->getNomComplet() ?: $piste?->getRisque()?->getCode(),
            'assureur' => $cotation?->getAssureur()?->getNom(),

            'primeTotale' => $eco['primeTranche'] ?? null,
            'primePayee' => $eco['primeSignalee'] ?? null,
            'primeSolde' => $eco['primeSolde'] ?? null,
            'primeDerniereLe' => $primes['date'],
            'primeReferences' => $primes['references'],

            'commissionTtc' => $eco['commissionTtc'] ?? null,
            'commissionHt' => $eco['commissionHt'] ?? null,
            'commissionEncaissee' => $eco['commissionEncaissee'] ?? null,
            'commissionSolde' => $eco['commissionSolde'] ?? null,
            'commissionExigible' => $eco['commissionExigible'] ?? null,
            'commissionDerniereLe' => $commissions['date'],
            'commissionReferences' => $commissions['references'],
            'commissionComptes' => $commissions['comptes'],
            'commissionBordereaux' => $commissions['bordereaux'],

            'taxeCourtierTaux' => $eco['tauxTaxeCourtier'] ?? null,
            'taxeCourtierMontant' => $eco['taxeCourtier'] ?? null,
            'taxeCourtierPayee' => $eco['taxeCourtierPayee'] ?? null,
            'taxeCourtierSolde' => $eco['taxeCourtierSolde'] ?? null,
            'taxeCourtierPayeeLe' => $taxeCourtier['date'],
            'taxeCourtierReferences' => $taxeCourtier['references'],
            'taxeCourtierExigible' => $eco['taxeCourtierExigible'] ?? null,

            'taxeAssureurTaux' => $eco['tauxTaxeAssureur'] ?? null,
            'taxeAssureurMontant' => $eco['taxeAssureur'] ?? null,
            'taxeAssureurPayee' => $eco['taxeAssureurPayee'] ?? null,
            'taxeAssureurSolde' => $eco['taxeAssureurSolde'] ?? null,
            'taxeAssureurPayeeLe' => $taxeAssureur['date'],
            'taxeAssureurReferences' => $taxeAssureur['references'],
            'taxeAssureurExigible' => $eco['taxeAssureurExigible'] ?? null,

            'commissionPure' => $eco['commissionPure'] ?? null,
            'reserve' => $eco['reserve'] ?? null,

            'intermediaire' => $this->nomDeLIntermediaire($tranche),
            'intermediairePart' => $this->partDeLIntermediaire($tranche),

            'retroPartenaireDue' => $eco['retroCommission'] ?? null,
            'retroPartenairePayee' => $eco['retroReversee'] ?? null,
            'retroPartenaireSolde' => $eco['retroSolde'] ?? null,
            'retroPartenaireExigible' => $eco['retroAPayer'] ?? null,
            'retroPartenairePayeeLe' => $retroPartenaire['date'],
            'retroPartenaireReferences' => $retroPartenaire['references'],
            'retroPartenaireLots' => $retroPartenaire['lots'],
            'retroPartenaireComptes' => $retroPartenaire['comptes'],

            'retroAgentBeneficiaire' => $this->nomDesAgents($tranche),
            'retroAgentDue' => $eco['retroAgentDue'] ?? null,
            'retroAgentPayee' => $eco['retroAgentReversee'] ?? null,
            'retroAgentSolde' => $eco['retroAgentSolde'] ?? null,
            'retroAgentExigible' => $eco['retroAgentExigible'] ?? null,
            'retroAgentPayeeLe' => $retroAgent['date'],
            'retroAgentReferences' => $retroAgent['references'],
            'retroAgentLots' => $retroAgent['lots'],
            'retroAgentComptes' => $retroAgent['comptes'],
        ];
    }

    /**
     * LES MOIS, DANS L'ORDRE DU CALENDRIER.
     *
     * Publique parce que la synthèse en a besoin pour TRIER ses lignes : le libellé ne
     * porte que le nom du mois, si bien que l'ordre ne se déduit plus du texte. Le
     * calendrier vit ici, une seule fois, et non recopié dans l'écrivain.
     */
    public const MOIS = [
        'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
        'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre',
    ];

    /**
     * LE MOIS DES TRANCHES SANS DATE D'EFFET.
     *
     * ⚠ CE LIBELLÉ EST ÉCRIT DANS LES DONNÉES, PAS SEULEMENT DANS LA SYNTHÈSE — et c'est
     * tout l'intérêt. Laisser la cellule vide et nommer le groupe dans la seule feuille de
     * synthèse donnait une somme conditionnelle sans correspondance : le groupe affichait
     * 0,00 quand ces tranches pesaient 4 952,50, et les sous-lignes ne totalisaient plus le
     * total général. Un critère ne peut chercher que ce que les données portent.
     */
    public const SANS_MOIS = "(sans date d'effet)";

    /**
     * LE MOIS D'UNE DATE : « Janvier », « Février »…
     *
     * ⚠ NE JAMAIS Y REMETTRE UN RANG NI UN JOUR — c'est un correctif, pas une préférence.
     * Écrit « 01 janv », le libellé était RECONNU COMME UNE DATE par `strtotime` (« jan »),
     * tout comme « 03 mars » (« mar »). Le moteur de formules convertissait alors le critère
     * en date, ne trouvait plus aucune correspondance, et ces deux mois-là — et eux seuls —
     * ressortaient à zéro dans la synthèse : janvier annonçait 0,00 quand la somme brute
     * valait 605 715,10.
     *
     * Les douze noms seuls sont inanalysables comme dates ; `MoisDEffetTest` le vérifie.
     * L'ordre du calendrier est porté par le TRI (cf. `EcrivainEtat::grouper()`), pas par
     * le texte.
     */
    private static function moisDe(?\DateTimeInterface $date): string
    {
        if ($date === null) {
            return self::SANS_MOIS;
        }

        return self::MOIS[(int) $date->format('n') - 1];
    }

    /**
     * LA POLICE D'UNE TRANCHE : l'avenant à la date d'effet la PLUS ANCIENNE.
     *
     * ⚠ `getAvenants()->first()` n'a AUCUN ordre garanti — la collection ne porte pas
     * d'`OrderBy`. Sur une cotation à plusieurs avenants, la date d'effet affichée
     * pouvait donc changer d'une exécution à l'autre, sans que rien ne le signale.
     *
     * C'est aussi la définition qu'emploie le filtre d'exercice : filtrer sur une date
     * que la colonne n'affiche pas serait un piège.
     */
    private static function policeDe(?\App\Entity\Cotation $cotation): ?\App\Entity\Avenant
    {
        $retenu = null;
        foreach ($cotation?->getAvenants() ?? [] as $avenant) {
            $date = $avenant->getStartingAt();
            if ($retenu === null) {
                $retenu = $avenant;
                continue;
            }
            $dateRetenue = $retenu->getStartingAt();
            // Une date absente ne peut pas gagner : elle ne date rien.
            if ($date !== null && ($dateRetenue === null || $date < $dateRetenue)) {
                $retenu = $avenant;
            }
        }

        return $retenu;
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
     * LES AGENTS INTERNES À QUI LA TRANCHE DOIT UNE RÉTROCOMMISSION.
     *
     * ⚠ Lus sur les CONDITIONS DE PARTAGE, jamais sur les versements. C'est la source qui
     * décide déjà qui a droit à quoi, et un agent a droit dès la souscription : le déduire
     * des reversements aurait laissé la colonne vide sur toute affaire pas encore
     * reversée — c'est-à-dire précisément celles qu'on ouvre ce fichier pour retrouver.
     */
    private function nomDesAgents(Tranche $tranche): string
    {
        $noms = [];
        foreach ($this->helper->getCotationConditionsAgent($tranche->getCotation()) as $condition) {
            $nom = $condition->getAgent()?->getNom();
            if ($nom !== null && $nom !== '') {
                $noms[$nom] = true;
            }
        }

        return implode(PiecesDeReglement::SEPARATEUR, array_keys($noms));
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

}
