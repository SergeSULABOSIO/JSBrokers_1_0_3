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
 * Le statut de règlement (prime client ET commission) étant dérivé à la volée par
 * TrancheIndicatorStrategy — jamais stocké —, le filtrage par statut et le tri par urgence
 * se font en mémoire : préchargement batch, calcul des indicateurs, classification, tri,
 * puis pagination par découpage. Le volume de tranches d'une entreprise reste modeste ;
 * au-delà de MAX_TRANCHES_EN_MEMOIRE un avertissement est journalisé.
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
     */
    public function filtrerTrierPaginer(array $tranches, string $statut, int $page = 1, int $limit = 20): array
    {
        $filtrees = $this->preparerFiltrerTrier($tranches, $statut);
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
     * client ou une cotation), filtre par statut, trie par urgence, pagine, et ajoute les
     * totaux calculés sur l'ENSEMBLE filtré (pas seulement la page).
     *
     * $invitePortefeuille restreint au PÉRIMÈTRE PORTEFEUILLE de cet invité, comme le badge
     * « Mon portefeuille » posé par défaut sur la rubrique Tranches : le chemin de relations
     * vient de PortefeuilleScope (source unique), pour que le suivi des impayés annoncé par
     * l'assistant coïncide avec la liste affichée. `null` = toute l'entreprise (chemin
     * historique, conservé pour la vigie des échéances qui raisonne au niveau du cabinet).
     *
     * @return array{items: Tranche[], totaux: array, totalItems: int, currentPage: int, totalPages: int}
     */
    public function lister(Entreprise $entreprise, string $statut, ?string $lieAEntite = null, ?int $lieAId = null, int $page = 1, int $limit = 10, ?Invite $invitePortefeuille = null): array
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

        $filtrees = $this->preparerFiltrerTrier($qb->getQuery()->getResult(), $statut);
        $total = count($filtrees);

        $totaux = ['nb' => $total, 'totalPrime' => 0.0, 'totalSoldePrime' => 0.0, 'totalSoldeCommission' => 0.0, 'totalRetroExigible' => 0.0];
        foreach ($filtrees as $tranche) {
            $totaux['totalPrime'] += (float) ($tranche->primeTranche ?? 0);
            $totaux['totalSoldePrime'] += max(0.0, (float) ($tranche->primeSoldeDue ?? 0));
            $totaux['totalSoldeCommission'] += max(0.0, (float) ($tranche->solde_restant_du ?? 0));
            $totaux['totalRetroExigible'] += max(0.0, (float) ($tranche->retroCommissionExigible ?? 0));
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
     * Indique si une tranche (indicateurs déjà calculés) relève du statut demandé.
     * Sémantique : « impayees » englobe tout solde exigible (prime et/ou commission),
     * « echues »/« a_echoir » découpent les impayées selon l'échéance, « partiellement »
     * cible les règlements entamés mais incomplets, « payees » = prime ET commission soldées.
     */
    public function correspondAuFiltre(Tranche $tranche, string $statut): bool
    {
        $statutPaiement = (string) ($tranche->statutPaiement ?? 'N/A');
        if ($statutPaiement === 'N/A') {
            return false; // ni prime ni commission : visible uniquement sous « Toutes »
        }

        $impayee = $statutPaiement !== 'Payée';

        return match ($statut) {
            TranchePaiementScope::STATUT_IMPAYEES => $impayee,
            TranchePaiementScope::STATUT_ECHUES => $impayee && $this->estEchue($tranche),
            TranchePaiementScope::STATUT_A_ECHOIR => $impayee && !$this->estEchue($tranche),
            TranchePaiementScope::STATUT_PARTIELLEMENT => in_array($statutPaiement, ['Partiellement payée', 'Prime payée, commission due'], true),
            TranchePaiementScope::STATUT_PAYEES => !$impayee,
            // Flux inverse (décaissement) : rétro partenaire exigible, quel que soit
            // le statut d'encaissement (une tranche soldée peut devoir sa rétro).
            TranchePaiementScope::STATUT_RETRO_A_PAYER => (float) ($tranche->retroCommissionExigible ?? 0) > 0,
            // Commission à collecter MAINTENANT auprès de l'assureur : la prime est
            // payée (facturée ou signalée), la commission ne l'est pas encore.
            TranchePaiementScope::STATUT_COMMISSION_EXIGIBLE => (float) ($tranche->commissionExigible ?? 0) > 0,
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
     * @return Tranche[] Tranches filtrées et triées (ensemble complet, non paginé).
     */
    private function preparerFiltrerTrier(array $tranches, string $statut): array
    {
        $this->chargerIndicateurs($tranches);

        if (TranchePaiementScope::estValide($statut)) {
            $tranches = array_values(array_filter(
                $tranches,
                fn (Tranche $t): bool => $this->correspondAuFiltre($t, $statut)
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
        $statutPaiement = (string) ($tranche->statutPaiement ?? 'N/A');
        $impayee = $statutPaiement !== 'N/A' && $statutPaiement !== 'Payée';
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
