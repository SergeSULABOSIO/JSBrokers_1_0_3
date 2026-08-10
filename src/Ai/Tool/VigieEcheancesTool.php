<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Services\DashboardDataProvider;
use App\Services\Search\PortefeuilleCritereFactory;
use App\Services\Search\TranchePaiementScope;
use App\Services\Tranche\TranchePaiementService;
use App\Service\Workspace\WorkspaceAccessResolver;

/**
 * Outil de données : le BRIEF des échéances du courtier — polices à renouveler
 * sous N jours, tâches non closes (dont celles en retard), pistes encore sans
 * police, derniers sinistres notifiés, tranches échues impayées (primes et
 * commissions à relancer). Répond à « que dois-je surveiller ? ».
 *
 * Gating PAR VOLET (fail-closed) : chaque volet est adossé à une entité du
 * périmètre ; un volet hors périmètre est OMIS avec mention (l'assistant reste
 * utile sur le reste), le refus global n'arrive que si tout est hors périmètre.
 *
 * LE VOLET RENOUVELLEMENTS EST PARTITIONNÉ (echues / aVenir), et cette forme est
 * un GARDE-FOU, pas une commodité d'affichage : avec un unique « total », un
 * résultat de 2 lignes toutes à venir se lisait « il n'y a aucune police échue »,
 * et l'assistant l'a affirmé alors que la rubrique en affichait cinq. Deux
 * compteurs nommés rendent ce contresens impossible. Les bornes viennent de
 * AvenantEcheanceScope (via DashboardDataProvider) : ce volet RÉCONCILIE avec les
 * chips « Échus » + « Sous N jours » de la rubrique.
 *
 * LE VOLET IMPAYÉS EST PARTITIONNÉ DE MÊME (primes / commissions), pour la même
 * raison et à la suite du même genre d'incident : un total unique mélangeait la
 * dette de l'ASSURÉ (prime) et celle de l'ASSUREUR (commission), si bien que « 5
 * impayés » et « une seule prime réellement due » décrivaient le même jeu de lignes.
 * Les deux ensembles sont disjoints par construction (le second exige prime PAYÉE).
 *
 * COÛT : getAllRenouvellements() hydrate les valeurs calculées des avenants —
 * l'horizon est borné dur (HORIZON_MAX) pour contenir ce coût. La projection de la
 * vigie n'en a pas besoin (getters simples), mais le tableau de bord partage la
 * méthode : ne pas retirer l'hydratation sans dissocier les deux appelants.
 */
final class VigieEcheancesTool implements AiToolInterface
{
    /** volet => entité dont le droit de lecture conditionne le volet. */
    private const VOLETS = [
        'renouvellements' => 'Avenant',
        'taches'          => 'Tache',
        'pistes'          => 'Piste',
        'sinistres'       => 'NotificationSinistre',
        'impayes'         => 'Tranche',
    ];

    /** Lignes restituées par volet (sortie compacte, économie de tokens). */
    private const MAX_LIGNES_PAR_VOLET = 8;

    private const HORIZON_DEFAUT = 30;
    private const HORIZON_MAX = 180;

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly DashboardDataProvider $dashboard,
        private readonly TranchePaiementService $tranchePaiement,
        private readonly PortefeuilleCritereFactory $portefeuilleCritere,
    ) {
    }

    public function name(): string
    {
        return 'vigie_echeances';
    }

    public function description(): string
    {
        return 'Brief des échéances et points de vigilance du cabinet : polices déjà ÉCHUES et '
            . 'polices à renouveler sous N jours (défaut 30), tâches non closes (dont en '
            . 'retard), pistes en cours sans police, derniers sinistres notifiés, tranches '
            . 'échues impayées (primes et commissions à relancer). À appeler quand '
            . 'l\'utilisateur demande ce qu\'il doit surveiller/faire, ses renouvellements ou '
            . 'un brief du jour. Le volet renouvellements est PARTITIONNÉ en « echues » et '
            . '« aVenir », chacun avec son propre total : il reproduit EXACTEMENT les chips '
            . '« Échus » + « Sous N jours » de la rubrique Avenants (mêmes bornes, même '
            . 'exclusion des polices dont le sort est scellé), donc ses chiffres doivent '
            . 'coïncider avec ceux de compter_entites (paramètre echeance) et avec la boussole. '
            . 'Un « aVenir.total » non nul avec « echues.total » à zéro signifie qu\'il n\'y a '
            . 'réellement aucune police échue — mais ne JAMAIS déduire l\'absence d\'échues '
            . 'd\'un total global. Le volet impayes est lui aussi PARTITIONNÉ, par DETTE : '
            . '« primes » (dues par l\'assuré) et « commissions » (dues par l\'assureur, prime '
            . 'déjà soldée), ensembles disjoints — lis primes.total avant de parler de primes '
            . 'impayées. Pour le détail complet des impayés, préférer suivi_impayes.';
    }

    public function aiguillage(): string
    {
        return '« renouvellements à venir / polices qui expirent / polices ÉCHUES / échéances à ne pas rater » : '
            . 'REPÉRER. Ma sortie est PARTITIONNÉE en « echues » et « aVenir », chacune avec son total : lis '
            . 'TOUJOURS echues.total avant de parler des polices échues, et ne déduis JAMAIS leur absence d\'un '
            . 'total global ni du fait que les lignes affichées portent des dates futures.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'volet' => [
                    'type' => 'string',
                    'enum' => array_merge(array_keys(self::VOLETS), ['tout']),
                    'description' => 'Volet du brief : renouvellements de polices, tâches non closes, '
                        . 'pistes en cours, derniers sinistres — ou tout (brief complet).',
                ],
                'horizonJours' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::HORIZON_MAX,
                    'description' => 'Horizon des renouvellements en jours (défaut ' . self::HORIZON_DEFAUT . ').',
                ],
            ],
            'required' => ['volet'],
        ];
    }

    /**
     * Chemin simulé : « mes échéances », « brief du jour », « renouvellements
     * (sous N jours) », « tâches en retard/non closes ». « Liste les tâches »
     * reste le domaine de rechercher_entites.
     */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        if (preg_match('/\b(vigie|echeances?|brief|a surveiller|points? de vigilance)\b/', $normalized)) {
            $args = ['volet' => 'tout'];
            if (preg_match('/\b(\d{1,3})\s*jours?\b/', $normalized, $m)) {
                $args['horizonJours'] = (int) $m[1];
            }

            return $args;
        }

        // « échues / expirées / périmées » relèvent aussi de ce volet depuis qu'il les
        // couvre : sans cela, « il reste combien de polices échues ? » ne trouvait aucun
        // outil et le modèle répondait de mémoire.
        if (preg_match('/\brenouvellements?\b|\brenouveler\b|\b(?:echues?|echus|expirees?|expires?|perimees?|perimes?)\b/', $normalized)
            && !preg_match('/\bcombien\b/', $normalized)) {
            $args = ['volet' => 'renouvellements'];
            if (preg_match('/\b(\d{1,3})\s*jours?\b/', $normalized, $m)) {
                $args['horizonJours'] = (int) $m[1];
            }

            return $args;
        }

        if (preg_match('/\btaches?\s+(en retard|non closes?|ouvertes?|en cours)\b/', $normalized)) {
            return ['volet' => 'taches'];
        }

        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $volet = (string) ($args['volet'] ?? 'tout');
        if ($volet !== 'tout' && !isset(self::VOLETS[$volet])) {
            return AiToolResult::introuvable($volet);
        }
        $horizon = max(1, min(self::HORIZON_MAX, (int) ($args['horizonJours'] ?? self::HORIZON_DEFAUT)));

        $demandes = $volet === 'tout' ? array_keys(self::VOLETS) : [$volet];
        $labels = $this->accessResolver->libellesEntites();

        // FAIL-CLOSED par volet : hors périmètre => volet omis avec mention.
        $horsPerimetre = [];
        $volets = [];
        foreach ($demandes as $v) {
            $entite = self::VOLETS[$v];
            if (!$this->accessResolver->canRead($scope->invite, $entite)) {
                $horsPerimetre[] = $labels[$entite] ?? $entite;
                continue;
            }
            $volets[$v] = $this->collecter($v, $scope, $horizon);
        }

        if ($volets === []) {
            return AiToolResult::horsPerimetre(
                'Échéances (' . implode(', ', $horsPerimetre) . ')'
            );
        }

        return AiToolResult::ok(array_filter([
            'date'          => (new \DateTimeImmutable('now'))->format('Y-m-d'),
            'horizonJours'  => $horizon,
            'volets'        => $volets,
            'horsPerimetre' => $horsPerimetre ?: null,
        ], static fn ($v) => $v !== null));
    }

    private function collecter(string $volet, AiScope $scope, int $horizon): array
    {
        $entreprise = $scope->entreprise;
        $max = self::MAX_LIGNES_PAR_VOLET;

        // PÉRIMÈTRE : la vigie répond DANS le portefeuille de l'invité, comme la
        // boussole et comme les rubriques affichées. Sans cela, un gestionnaire
        // s'entendait annoncer les échéances de tout le cabinet — deux nombres
        // pour la même question selon qu'il la posait à Ket ou qu'il regardait
        // sa liste. `null` quand l'invité ne gère aucun portefeuille : le filtre
        // est alors sans objet (il n'y a rien à restreindre).
        $portefeuilleDe = $this->portefeuilleCritere->pour('Tranche', $scope->invite) !== []
            ? $scope->invite
            : null;

        // Volet impayés : tranches ÉCHUES, PARTITIONNÉES par dette — même remède que le
        // volet renouvellements ci-dessous. Un total unique mélangeant deux débiteurs
        // (l'assuré pour la prime, l'assureur pour la commission) laissait lire « 5
        // impayés » puis « une seule prime réellement due » sur le même jeu de lignes.
        // Source unique TranchePaiementService (mêmes axes que les chips de la liste).
        if ($volet === 'impayes') {
            $primes = $this->tranchePaiement->lister(
                $entreprise,
                [
                    TranchePaiementScope::AXE_ECHEANCE => TranchePaiementScope::ECHUE,
                    TranchePaiementScope::AXE_PRIME => TranchePaiementScope::IMPAYEE,
                ],
                null,
                null,
                1,
                $max,
                $portefeuilleDe
            );

            // Prime PAYÉE + commission impayée = commission exigible, à collecter
            // auprès de l'assureur. Disjoint du volet précédent par construction.
            $commissions = $this->tranchePaiement->lister(
                $entreprise,
                [
                    TranchePaiementScope::AXE_ECHEANCE => TranchePaiementScope::ECHUE,
                    TranchePaiementScope::AXE_PRIME => TranchePaiementScope::PAYEE,
                    TranchePaiementScope::AXE_COMMISSION => TranchePaiementScope::IMPAYEE,
                ],
                null,
                null,
                1,
                $max,
                $portefeuilleDe
            );

            return [
                'primes' => [
                    'lignes' => array_map(fn (object $e) => $this->projeter($volet, $e), $primes['items']),
                    'totaux' => $primes['totaux'],
                    'total' => $primes['totalItems'],
                    'tronque' => $primes['totalItems'] > count($primes['items']),
                    'debiteur' => 'l\'assuré',
                ],
                'commissions' => [
                    'lignes' => array_map(fn (object $e) => $this->projeter($volet, $e), $commissions['items']),
                    'totaux' => $commissions['totaux'],
                    'total' => $commissions['totalItems'],
                    'tronque' => $commissions['totalItems'] > count($commissions['items']),
                    'debiteur' => 'l\'assureur',
                ],
                'total' => $primes['totalItems'] + $commissions['totalItems'],
                'rappel' => 'Sortie PARTITIONNÉE par dette : « primes » = dues par l\'assuré, '
                    . '« commissions » = dues par l\'assureur (prime déjà soldée). Les deux ensembles '
                    . 'sont DISJOINTS. Lis TOUJOURS primes.total avant de parler de primes impayées, '
                    . 'et ne déduis JAMAIS leur absence du total global.',
            ];
        }

        // Volet renouvellements : PARTITIONNÉ en échues / à venir. Un seul « total »
        // laissait lire « 2 lignes à venir » comme « aucune police échue ».
        if ($volet === 'renouvellements') {
            return $this->partitionnerRenouvellements(
                $this->dashboard->getAllRenouvellements($entreprise, $horizon, $portefeuilleDe),
                $max,
                $this->dashboard->getAvenantsNonRenouvelables($entreprise, $portefeuilleDe),
            );
        }

        $items = match ($volet) {
            'taches'    => $this->dashboard->getTachesNonCloses($entreprise, $max + 1, $portefeuilleDe),
            'pistes'    => $this->dashboard->getPistesEnCours($entreprise, $max + 1, $portefeuilleDe),
            'sinistres' => $this->dashboard->getDerniersSinistres($entreprise, $max + 1, $portefeuilleDe),
        };

        $total = count($items);
        $lignes = array_map(
            fn (object $e) => $this->projeter($volet, $e),
            array_slice($items, 0, $max),
        );

        return ['lignes' => $lignes, 'total' => $total, 'tronque' => $total > $max];
    }

    /**
     * Sépare les polices ÉCHUES des polices à venir, chacune avec son propre compteur
     * et son propre plafond de lignes — les échues, les plus urgentes, ne doivent
     * jamais être évincées de l'échantillon par des échéances plus lointaines.
     *
     * `total` reste le compte RÉEL de chaque population, indépendamment du plafond :
     * c'est ce nombre que l'assistant doit citer, et il doit coïncider avec le chip
     * correspondant de la rubrique.
     *
     * TROISIÈME POPULATION, informative : les polices SIGNALÉES non renouvelables. Elles ne
     * réclament aucune action — c'est pourquoi le pipeline les écarte, et pourquoi elles ne
     * sont PAS comptées dans `total`. Mais les taire ferait dire à l'assistante « il ne reste
     * plus rien » là où le courtier voit un onglet et un chip pleins. On les annonce donc,
     * avec leur motif, et en disant explicitement qu'elles sont hors du travail à faire.
     *
     * @param array<int, object> $items            avenants triés par endingAt croissant
     * @param array<int, object> $nonRenouvelables polices écartées par décision
     */
    private function partitionnerRenouvellements(array $items, int $max, array $nonRenouvelables = []): array
    {
        $aujourdhui = new \DateTimeImmutable('today');
        $echues = [];
        $aVenir = [];

        foreach ($items as $avenant) {
            $fin = $avenant->getEndingAt();
            $estEchue = $fin !== null
                && \DateTimeImmutable::createFromInterface($fin)->setTime(0, 0) < $aujourdhui;
            if ($estEchue) {
                $echues[] = $avenant;
            } else {
                $aVenir[] = $avenant;
            }
        }

        $population = fn (array $liste): array => [
            'lignes' => array_map(
                fn (object $e) => $this->projeter('renouvellements', $e),
                array_slice($liste, 0, $max),
            ),
            'total' => count($liste),
        ];

        $payload = [
            'echues'  => $population($echues),
            'aVenir'  => $population($aVenir),
            'total'   => count($items),
            'tronque' => count($echues) > $max || count($aVenir) > $max,
            'rappel'  => 'Les polices ÉCHUES sont incluses (echues.total). Un renouvellement '
                . 'AMORCÉ — piste dérivée sans avenant successeur — laisse la police ÉCHUE : '
                . 'ne conclus jamais qu\'elle est renouvelée.',
        ];

        // Hors du travail à faire, donc hors de `total` — mais dites, pour ne pas contredire
        // l'écran, qui leur consacre un chip et un onglet.
        if ($nonRenouvelables !== []) {
            $payload['nonRenouvelables'] = [
                'lignes' => array_map(
                    fn (object $e) => $this->projeter('renouvellements', $e)
                        + ['motif' => $e->nonRenouvelableDetail ?? null],
                    array_slice($nonRenouvelables, 0, $max),
                ),
                'total'  => count($nonRenouvelables),
                'rappel' => 'Polices SIGNALÉES non renouvelables : une décision a été prise, elles ne '
                    . 'sont donc PAS comptées dans « total » et ne réclament aucune action de '
                    . 'renouvellement. Ne les présente pas comme du retard. Elles restent visibles '
                    . 'dans le chip « Non renouvelables » de la rubrique et l\'onglet du même nom au '
                    . 'tableau de bord ; ce qui reste dû dessus reste à recouvrer, et la décision peut '
                    . 'être levée (preparer_marquage_non_renouvelable, mode="lever").',
            ];
        }

        return $payload;
    }

    /** Projection compacte d'une ligne (scalaires utiles uniquement, dates Y-m-d). */
    private function projeter(string $volet, object $e): array
    {
        $aujourdhui = new \DateTimeImmutable('today');

        return match ($volet) {
            'renouvellements' => array_filter([
                'id'            => $e->getId(),
                'police'        => $e->getReferencePolice(),
                'client'        => $e->getCotation()?->getPiste()?->getClient()?->getNom(),
                'assureur'      => $e->getCotation()?->getAssureur()?->getNom(),
                'risque'        => $e->getCotation()?->getPiste()?->getRisque()?->getNomComplet(),
                'echeance'      => $e->getEndingAt()?->format('Y-m-d'),
                // Jour à jour (échéance ramenée à minuit) : négatif = police ÉCHUE. Le
                // signe seul est trop discret pour être lu, d'où joursRetard explicite.
                'joursRestants' => $e->getEndingAt() !== null
                    ? (int) $aujourdhui->diff(\DateTimeImmutable::createFromInterface($e->getEndingAt())->setTime(0, 0))->format('%r%a')
                    : null,
                'joursRetard'   => $e->getEndingAt() !== null
                    && ($retard = -((int) $aujourdhui->diff(\DateTimeImmutable::createFromInterface($e->getEndingAt())->setTime(0, 0))->format('%r%a'))) > 0
                        ? $retard
                        : null,
            ], static fn ($v) => $v !== null && $v !== ''),
            'taches' => array_filter([
                'id'          => $e->getId(),
                'description' => mb_substr((string) $e->getDescription(), 0, 80),
                'echeance'    => $e->getToBeEndedAt()?->format('Y-m-d'),
                'enRetard'    => $e->getToBeEndedAt() !== null
                    && \DateTimeImmutable::createFromInterface($e->getToBeEndedAt()) < $aujourdhui,
            ], static fn ($v) => $v !== null && $v !== ''),
            'pistes' => array_filter([
                'id'     => $e->getId(),
                'nom'    => $e->getNom(),
                'client' => $e->getClient()?->getNom(),
                'risque' => $e->getRisque()?->getNomComplet(),
                'creeLe' => $e->getCreatedAt()?->format('Y-m-d'),
            ], static fn ($v) => $v !== null && $v !== ''),
            'sinistres' => array_filter([
                'id'        => $e->getId(),
                'reference' => $e->getReferenceSinistre(),
                'assure'    => $e->getAssure()?->getNom(),
                'assureur'  => $e->getAssureur()?->getNom(),
                'risque'    => $e->getRisque()?->getNomComplet(),
                'notifieLe' => $e->getNotifiedAt()?->format('Y-m-d'),
            ], static fn ($v) => $v !== null && $v !== ''),
            // Tranche échue impayée (indicateurs déjà calculés par TranchePaiementService).
            'impayes' => array_filter([
                'id'              => $e->getId(),
                'tranche'         => $e->getNom(),
                'client'          => $e->clientNom ?? null,
                'police'          => $e->referencePolice ?? null,
                'statut'          => $e->statutPaiement ?? null,
                'urgence'         => $e->urgenceRecouvrement ?? null,
                'echeance'        => $e->getEcheanceAt()?->format('Y-m-d'),
                'joursRetard'     => $e->getEcheanceAt() !== null
                    // Jour à jour : échéance ramenée à minuit (sinon l'heure tronque un jour).
                    ? max(0, -((int) $aujourdhui->diff(\DateTimeImmutable::createFromInterface($e->getEcheanceAt())->setTime(0, 0))->format('%r%a')))
                    : null,
                'soldePrime'      => max(0.0, (float) ($e->primeSoldeDue ?? 0)),
                'soldeCommission' => max(0.0, (float) ($e->solde_restant_du ?? 0)),
                'retroAPayer'     => ($e->retroCommissionExigible ?? 0) > 0 ? (float) $e->retroCommissionExigible : null,
            ], static fn ($v) => $v !== null && $v !== '' && $v !== 'N/A'),
        };
    }
}
