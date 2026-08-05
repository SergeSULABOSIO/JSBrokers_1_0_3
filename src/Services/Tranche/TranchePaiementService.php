<?php

namespace App\Services\Tranche;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Tranche;
use App\Services\CanvasBuilder;
use App\Services\Search\PortefeuilleScope;
use App\Services\Search\TranchePaiementScope;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Source de vérité du suivi des paiements par tranche (liste workspace ET assistant IA).
 *
 * Les soldes (prime client, commission, rétrocommission) étant dérivés à la volée par
 * TrancheIndicatorStrategy — jamais stockés —, le filtrage par AXES et le tri par urgence
 * se font en mémoire : préchargement batch, calcul des indicateurs, classification, tri,
 * puis pagination par découpage. Le volume de tranches d'une entreprise reste modeste ;
 * au-delà de MAX_TRANCHES_EN_MEMOIRE un avertissement est journalisé.
 *
 * Le filtre n'est plus un statut unique mais une COMBINAISON d'axes cumulés en ET
 * (cf. TranchePaiementScope) : une dette par débiteur, plus l'échéance. Un tableau d'axes
 * vide = toutes les tranches suivables.
 */
class TranchePaiementService
{
    /** Garde-fou du chemin in-memory : seuil de journalisation, le traitement continue. */
    private const MAX_TRANCHES_EN_MEMOIRE = 5000;

    private LoggerInterface $logger;

    public function __construct(
        private readonly CanvasBuilder $canvasBuilder,
        private readonly EntityManagerInterface $em,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Filtre, trie par urgence et pagine une collection de tranches déjà chargées.
     * Retour de même forme que JSBDynamicSearchService::search() pour s'insérer sans
     * friction dans le pipeline des listes (trait + templates).
     *
     * @param Tranche[] $tranches
     * @param array<string, string> $axes Combinaison d'axes (cf. TranchePaiementScope), cumulés en ET.
     */
    public function filtrerTrierPaginer(array $tranches, array $axes, int $page = 1, int $limit = 20): array
    {
        $filtrees = $this->preparerFiltrerTrier($tranches, $axes);
        $total = count($filtrees);
        $pageItems = array_slice($filtrees, ($page - 1) * $limit, $limit);

        return [
            'status' => [
                'error' => null,
                'code' => 200,
                'message' => 'Requête de filtre exécutée avec succès.',
            ],
            'data' => $pageItems,
            'totalItems' => $total,
            'currentPage' => $page,
            'totalPages' => max(1, (int) ceil($total / $limit)),
            'itemsPerPage' => $limit,
        ];
    }

    /**
     * Chemin assistant IA : charge les tranches de l'entreprise (option : rattachées à un
     * client ou une cotation), filtre par AXES, trie par urgence, pagine, et ajoute les
     * totaux calculés sur l'ENSEMBLE filtré (pas seulement la page).
     *
     * $invitePortefeuille restreint au PÉRIMÈTRE PORTEFEUILLE de cet invité, comme le badge
     * « Mon portefeuille » posé par défaut sur la rubrique Tranches : le chemin de relations
     * vient de PortefeuilleScope (source unique), pour que le suivi des impayés annoncé par
     * l'assistant coïncide avec la liste affichée. `null` = toute l'entreprise (chemin
     * historique, conservé pour la vigie des échéances qui raisonne au niveau du cabinet).
     *
     * @param array<string, string> $axes Combinaison d'axes (cf. TranchePaiementScope), cumulés en ET.
     * @return array{items: Tranche[], totaux: array, totalItems: int, currentPage: int, totalPages: int}
     */
    public function lister(Entreprise $entreprise, array $axes, ?string $lieAEntite = null, ?int $lieAId = null, int $page = 1, int $limit = 10, ?Invite $invitePortefeuille = null): array
    {
        $qb = $this->em->getRepository(Tranche::class)->createQueryBuilder('t')
            ->andWhere('t.entreprise = :entreprise')
            ->setParameter('entreprise', $entreprise);

        // Jointures mutualisées : le rattachement (lieA=Client) et le périmètre portefeuille
        // empruntent le même début de chemin (cotation → piste), à ne joindre qu'une fois.
        // L'alias dérive du chemin parcouru, donc deux chemins qui se recouvrent partagent
        // naturellement leurs jointures.
        $joints = [];
        $joindreChemin = static function (array $segments) use ($qb, &$joints): string {
            $alias = 't';
            $parcouru = [];
            foreach ($segments as $segment) {
                $parcouru[] = $segment;
                $suivant = 'j_' . implode('_', $parcouru);
                if (!isset($joints[$suivant])) {
                    $qb->join("{$alias}.{$segment}", $suivant);
                    $joints[$suivant] = true;
                }
                $alias = $suivant;
            }

            return $alias;
        };

        if ($lieAId !== null && $lieAEntite === 'Cotation') {
            $qb->andWhere('t.cotation = :lieA')->setParameter('lieA', $lieAId);
        } elseif ($lieAId !== null && $lieAEntite === 'Client') {
            $alias = $joindreChemin(['cotation', 'piste']);
            $qb->andWhere("{$alias}.client = :lieA")->setParameter('lieA', $lieAId);
        }

        // Périmètre portefeuille : chemin de relations emprunté à PortefeuilleScope (source
        // unique partagée avec le moteur de recherche des listes) — le dernier segment est le
        // gestionnaire, comparé à l'invité.
        if ($invitePortefeuille !== null) {
            $segments = explode('.', PortefeuilleScope::pathsFor('Tranche')[0] ?? '');
            $gestionnaire = array_pop($segments);
            if ($gestionnaire !== null && $segments !== []) {
                $alias = $joindreChemin($segments);
                $qb->andWhere("{$alias}.{$gestionnaire} = :invitePortefeuille")
                    ->setParameter('invitePortefeuille', $invitePortefeuille);
            }
        }

        $filtrees = $this->preparerFiltrerTrier($qb->getQuery()->getResult(), $axes);
        $total = count($filtrees);

        // RÉPARTITION PAR DETTE. Comptée ICI, sur l'ensemble filtré : c'est le seul endroit
        // qui le voit en entier, donc le seul où elle reste juste quand la page est tronquée.
        // Sans elle, l'assistant lit « 5 lignes » puis « 4 soldes de prime à 0 » et croit se
        // contredire, alors que ce sont deux dettes distinctes (incident du 2026-08-05).
        $totaux = [
            'nb' => $total,
            'totalPrime' => 0.0,
            'totalSoldePrime' => 0.0,
            'totalSoldeCommission' => 0.0,
            'totalRetroExigible' => 0.0,
            'nbPrimeImpayee' => 0,
            'nbCommissionImpayee' => 0,
        ];
        foreach ($filtrees as $tranche) {
            $soldePrime = (float) ($tranche->primeSoldeDue ?? 0);
            $soldeCommission = (float) ($tranche->solde_restant_du ?? 0);
            $totaux['totalPrime'] += (float) ($tranche->primeTranche ?? 0);
            $totaux['totalSoldePrime'] += max(0.0, $soldePrime);
            $totaux['totalSoldeCommission'] += max(0.0, $soldeCommission);
            $totaux['totalRetroExigible'] += max(0.0, (float) ($tranche->retroCommissionExigible ?? 0));
            $totaux['nbPrimeImpayee'] += $soldePrime > 0 ? 1 : 0;
            $totaux['nbCommissionImpayee'] += $soldeCommission > 0 ? 1 : 0;
        }
        $totaux = array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $totaux);

        return [
            'items' => array_slice($filtrees, ($page - 1) * $limit, $limit),
            'totaux' => $totaux,
            'totalItems' => $total,
            'currentPage' => $page,
            'totalPages' => max(1, (int) ceil($total / $limit)),
        ];
    }

    /**
     * Indique si une tranche (indicateurs déjà calculés) satisfait TOUS les axes demandés.
     * Les axes se cumulent en ET ; un tableau vide laisse tout passer.
     *
     * Chaque axe porte sur UNE dette et une seule — elles ne se compensent jamais, leurs
     * débiteurs diffèrent (assuré / assureur / partenaire). Les combinaisons remplacent les
     * anciens statuts composites : « commission exigible » = prime PAYÉE + commission
     * IMPAYÉE, « rétro à payer maintenant » = rétro IMPAYÉE + commission PAYÉE.
     *
     * @param array<string, string> $axes clé de critère (ou nom court) => valeur
     */
    public function correspondAuFiltre(Tranche $tranche, array $axes): bool
    {
        $statutPaiement = (string) ($tranche->statutPaiement ?? 'N/A');
        if ($statutPaiement === 'N/A') {
            return false; // projet (cotation non validée) ou ni prime ni commission
        }

        foreach (TranchePaiementScope::normaliserAxes($axes) as $cle => $valeur) {
            if (!$this->respecteAxe($tranche, $cle, $valeur)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Prédicat d'un axe unique. Une dette INEXISTANTE (aucune rétro à verser) ou une
     * information ABSENTE (tranche sans date d'échéance) sort des DEUX valeurs de son axe :
     * la tranche n'est ni « payée » ni « impayée » de ce point de vue, exactement comme
     * « N/A » la sort du suivi. C'est ce qui rend chaque axe réellement complémentaire.
     */
    private function respecteAxe(Tranche $tranche, string $cle, string $valeur): bool
    {
        $impayee = $valeur === TranchePaiementScope::IMPAYEE;

        return match ($cle) {
            // Dette de l'ASSURÉ envers l'assureur.
            TranchePaiementScope::AXE_PRIME => $impayee
                ? (float) ($tranche->primeSoldeDue ?? 0) > 0
                : (float) ($tranche->primeSoldeDue ?? 0) <= 0,

            // Dette de l'ASSUREUR envers le courtier.
            TranchePaiementScope::AXE_COMMISSION => $impayee
                ? (float) ($tranche->solde_restant_du ?? 0) > 0
                : (float) ($tranche->solde_restant_du ?? 0) <= 0,

            // Dette du COURTIER envers le partenaire (flux inverse). On filtre sur le SOLDE
            // (la dette existe-t-elle ?), pas sur l'exigibilité (est-elle collectable ?) :
            // « à payer maintenant » s'obtient en ajoutant l'axe « commission payée ».
            TranchePaiementScope::AXE_RETRO => (float) ($tranche->retroCommission ?? 0) > 0
                && ($impayee
                    ? (float) ($tranche->retroCommissionSolde ?? 0) > 0
                    : (float) ($tranche->retroCommissionSolde ?? 0) <= 0),

            // Axe orthogonal aux trois dettes : n'importe laquelle peut être en retard.
            TranchePaiementScope::AXE_ECHEANCE => $tranche->getEcheanceAt() instanceof \DateTimeInterface
                && ($valeur === TranchePaiementScope::ECHUE
                    ? $this->estEchue($tranche)
                    : !$this->estEchue($tranche)),

            default => true,
        };
    }

    /**
     * Tri par urgence : (1) échues impayées, retard décroissant ; (2) à échoir impayées,
     * échéance la plus proche d'abord ; (3) impayées sans échéance, payableAt croissant ;
     * (4) payées / N-A, dernier encaissement le plus récent d'abord.
     *
     * @param Tranche[] $tranches
     * @return Tranche[]
     */
    public function trierParUrgence(array $tranches): array
    {
        usort($tranches, function (Tranche $a, Tranche $b): int {
            [$rangA, $cleA] = $this->cleUrgence($a);
            [$rangB, $cleB] = $this->cleUrgence($b);

            return $rangA <=> $rangB ?: $cleA <=> $cleB ?: $a->getId() <=> $b->getId();
        });

        return $tranches;
    }

    /**
     * Hydrate les valeurs calculées d'un lot de tranches (primeTranche, primePayee,
     * primeDeclareePayee, primeSoldeDue, statutPaiement, urgenceRecouvrement,
     * commissionExigible…). SOURCE UNIQUE de ce calcul pour la rubrique Tranches, les
     * chips de statut et les outils de l'assistant IA : personne ne recalcule à côté.
     *
     * @param Tranche[] $tranches
     */
    public function chargerIndicateurs(array $tranches): void
    {
        if (count($tranches) > self::MAX_TRANCHES_EN_MEMOIRE) {
            $this->logger->warning('[TranchePaiement] Volume de tranches inhabituel pour le filtrage en mémoire.', [
                'nb' => count($tranches),
                'seuil' => self::MAX_TRANCHES_EN_MEMOIRE,
            ]);
        }

        $this->canvasBuilder->batchPreloadForCollection($tranches);
        foreach ($tranches as $tranche) {
            $this->canvasBuilder->loadAllCalculatedValues($tranche);
        }
    }

    /**
     * @param Tranche[] $tranches
     * @param array<string, string> $axes
     * @return Tranche[] Tranches filtrées et triées (ensemble complet, non paginé).
     */
    private function preparerFiltrerTrier(array $tranches, array $axes): array
    {
        // Hydrater AVANT de filtrer, jamais l'inverse : les axes portent sur des indicateurs
        // calculés, qui valent null tant que le canvas n'est pas passé.
        $this->chargerIndicateurs($tranches);

        if (TranchePaiementScope::normaliserAxes($axes) !== []) {
            $tranches = array_values(array_filter(
                $tranches,
                fn (Tranche $t): bool => $this->correspondAuFiltre($t, $axes)
            ));
        }

        return $this->trierParUrgence($tranches);
    }

    private function estEchue(Tranche $tranche): bool
    {
        $echeance = $tranche->getEcheanceAt();

        return $echeance instanceof \DateTimeInterface && $echeance < new \DateTimeImmutable('now');
    }

    /**
     * @return array{0: int, 1: int} [rang de groupe, clé de tri croissante dans le groupe]
     */
    private function cleUrgence(Tranche $tranche): array
    {
        // « Reste quelque chose à recouvrer » se lit sur les SOLDES, pas sur le libellé
        // composite : le tri survit ainsi à toute évolution des libellés de statut.
        $statutPaiement = (string) ($tranche->statutPaiement ?? 'N/A');
        $impayee = $statutPaiement !== 'N/A'
            && ((float) ($tranche->primeSoldeDue ?? 0) > 0 || (float) ($tranche->solde_restant_du ?? 0) > 0);
        $echeance = $tranche->getEcheanceAt();

        if ($impayee && $echeance instanceof \DateTimeInterface) {
            // Échéance la plus ancienne = retard le plus grand → en tête des échues ;
            // pour les à-échoir, la plus proche d'abord. Un seul ordre croissant suffit.
            $rang = $echeance < new \DateTimeImmutable('now') ? 0 : 1;

            return [$rang, (int) $echeance->getTimestamp()];
        }

        if ($impayee) {
            return [2, (int) ($tranche->getPayableAt()?->getTimestamp() ?? PHP_INT_MAX)];
        }

        $dernier = $tranche->dateDernierEncaissement ?? null;
        $cle = $dernier instanceof \DateTimeInterface ? -$dernier->getTimestamp() : PHP_INT_MAX;

        return [3, (int) $cle];
    }
}
