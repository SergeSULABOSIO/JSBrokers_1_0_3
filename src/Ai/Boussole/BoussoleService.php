<?php

namespace App\Ai\Boussole;

use App\Comptabilite\CourtierSuiviFiscalService;
use App\Entity\Avenant;
use App\Entity\Entreprise;
use App\Entity\Feedback;
use App\Entity\Invite;
use App\Entity\Tache;
use App\Service\Saturation\SaturationService;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use App\Services\Note\NoteRecouvrementService;
use App\Services\Search\AvenantEcheanceScope;
use App\Services\Search\ChargeInviteCritereFactory;
use App\Services\Search\PortefeuilleCritereFactory;
use App\Services\Search\TranchePaiementScope;
use App\Services\Tranche\TranchePaiementService;

/**
 * LA BOUSSOLE du courtier, consolidée pour l'assistant IA.
 *
 * Produit un instantané COMPACT de la chaîne de valeur, dans le périmètre
 * (portefeuille) de l'invité, injecté dans le contexte système de Ket à CHAQUE
 * message : saturation/cross-selling, renouvellements à anticiper, primes
 * exigibles impayées, commissions à recouvrer auprès des assureurs, rétros à
 * reverser, obligation fiscale, bordereaux de fin de mois, tâches à clôturer.
 * Ket s'en sert pour rappeler et guider vers l'étape la plus prioritaire.
 *
 * FAIL-CLOSED : un axe n'apparaît que si l'invité a le droit de lire l'entité
 * sous-jacente. FAIL-SAFE : ce service tourne sur le chemin chaud du chat ; toute
 * exception d'un axe est avalée (l'axe disparaît, la conversation ne casse jamais).
 * Il ne restitue que des COMPTES — le DÉTAIL chiffré relève des outils dédiés.
 */
final class BoussoleService
{
    /**
     * Barème d'urgence des axes (plus haut = plus prioritaire pour le rappel).
     * PUBLIC parce que PlanDuJourService s'en sert pour ordonner le programme du
     * jour : un seul barème, donc jamais de contradiction entre la todo list
     * affichée à l'ouverture et le rappel de fin de réponse.
     */
    public const URGENCE = [
        'fiscal'             => 90,
        'retros'             => 85,
        'renouvellements'    => 80, // échus ; 60 si seulement imminents (cf. axeRenouvellements)
        'feedbacks'          => 75, // prochaine action datée : l'engagement pris envers un client
        'recouvrement_notes' => 72, // commission FACTURÉE non encaissée
        'commissions'        => 70, // commission exigible, pas encore facturée
        'primes_impayees'    => 65,
        'bordereaux'         => 55,
        'saturation'         => 50,
        'taches'             => 40,
    ];

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly SaturationService $saturation,
        private readonly TranchePaiementService $tranchePaiement,
        private readonly CourtierSuiviFiscalService $suiviFiscal,
        private readonly JSBDynamicSearchService $searchService,
        private readonly PortefeuilleCritereFactory $portefeuilleCritere,
        private readonly ChargeInviteCritereFactory $chargeCritere,
        private readonly NoteRecouvrementService $noteRecouvrement,
    ) {
    }

    /**
     * Instantané de la boussole pour l'invité. `prioritaire` = l'axe actionnable
     * le plus urgent (ou null si tout est au vert), sur lequel Ket appuie son rappel.
     *
     * @return array{items: array<int, array>, prioritaire: ?array}
     */
    public function etat(Entreprise $entreprise, Invite $invite): array
    {
        $items = array_values(array_filter([
            $this->axe($invite, 'Client', fn (): array => $this->axeSaturation($entreprise, $invite)),
            $this->axe($invite, 'Avenant', fn (): array => $this->axeRenouvellements($entreprise, $invite)),
            $this->axe($invite, 'Tranche', fn (): array => $this->axePrimesImpayees($entreprise, $invite)),
            $this->axe($invite, 'Tranche', fn (): array => $this->axeCommissions($entreprise, $invite)),
            $this->axe($invite, 'Tranche', fn (): array => $this->axeRetros($entreprise, $invite)),
            $this->axe($invite, 'Note', fn (): array => $this->axeRecouvrementNotes($entreprise)),
            $this->axe($invite, 'Bordereau', fn (): array => $this->axeBordereaux()),
            $this->axe($invite, 'DocumentComptable', fn (): array => $this->axeFiscal($entreprise)),
            $this->axe($invite, 'Tache', fn (): array => $this->axeTaches($entreprise, $invite)),
            $this->axe($invite, 'Feedback', fn (): array => $this->axeFeedbacks($entreprise, $invite)),
        ]));

        $prioritaire = null;
        foreach ($items as $item) {
            if (!empty($item['actionnable']) && ($prioritaire === null || $item['urgence'] > $prioritaire['urgence'])) {
                $prioritaire = $item;
            }
        }

        return ['items' => $items, 'prioritaire' => $prioritaire];
    }

    /**
     * Enveloppe fail-closed (canRead) + fail-safe (exceptions avalées) d'un axe :
     * le chat ne doit JAMAIS tomber à cause de la boussole.
     */
    private function axe(Invite $invite, string $entite, callable $calcul): ?array
    {
        if (!$this->accessResolver->canRead($invite, $entite)) {
            return null;
        }
        try {
            return $calcul();
        } catch (\Throwable) {
            return null;
        }
    }

    private function axeSaturation(Entreprise $entreprise, Invite $invite): array
    {
        $agg         = $this->saturation->couverturePortefeuille($entreprise, $invite, false);
        $sous        = $agg['nbClientsSousSatures'];
        $actionnable = $sous > 0 && $agg['nbCatalogue'] > 0;

        return [
            'axe'         => 'saturation',
            'libelle'     => $actionnable
                ? sprintf('%d client(s) sous 100 %% de couverture (taux moyen %s %%)', $sous, $agg['tauxMoyen'])
                : 'Portefeuille pleinement saturé',
            'compte'      => $sous,
            'urgence'     => $actionnable ? self::URGENCE['saturation'] : 0,
            'actionnable' => $actionnable,
            'opportunite' => $agg['topOpportunites'][0]['risque'] ?? null,
        ];
    }

    private function axeRenouvellements(Entreprise $entreprise, Invite $invite): array
    {
        $echus     = $this->compterAvenants($entreprise, $invite, AvenantEcheanceScope::STATUT_ECHUS);
        $imminents = $this->compterAvenants($entreprise, $invite, AvenantEcheanceScope::STATUT_30J);
        $total     = $echus + $imminents;

        return [
            'axe'         => 'renouvellements',
            'libelle'     => $total > 0
                ? sprintf('%d renouvellement(s) à anticiper (%d échu(s), %d sous 30 j)', $total, $echus, $imminents)
                : 'Aucun renouvellement imminent',
            'compte'      => $total,
            'urgence'     => $echus > 0 ? self::URGENCE['renouvellements'] : ($imminents > 0 ? 60 : 0),
            'actionnable' => $total > 0,
        ];
    }

    private function axePrimesImpayees(Entreprise $entreprise, Invite $invite): array
    {
        // Axe PRIME SEULE : le compte et le montant portent alors sur la MÊME dette, celle
        // de l'assuré. L'ancien filtre « impayées » comptait aussi les dettes de commission
        // tout en affichant le seul solde de prime — un axe annonçait 5 lignes pour 1 seule
        // prime réellement due (incident du 2026-08-05). Les commissions ont leur propre axe.
        $r  = $this->tranchePaiement->lister(
            $entreprise,
            [TranchePaiementScope::AXE_PRIME => TranchePaiementScope::IMPAYEE],
            null,
            null,
            1,
            1,
            $invite,
        );
        $nb = (int) ($r['totalItems'] ?? 0);

        return [
            'axe'         => 'primes_impayees',
            'libelle'     => $nb > 0 ? sprintf('%d prime(s) encore due(s) par les clients', $nb) : 'Aucune prime impayée',
            'compte'      => $nb,
            'montant'     => (float) ($r['totaux']['totalSoldePrime'] ?? 0),
            'urgence'     => $nb > 0 ? self::URGENCE['primes_impayees'] : 0,
            'actionnable' => $nb > 0,
        ];
    }

    private function axeCommissions(Entreprise $entreprise, Invite $invite): array
    {
        // « Commission exigible » = sa définition même : prime PAYÉE par l'assuré (l'assureur
        // détient donc les fonds) + commission encore due au courtier.
        $r  = $this->tranchePaiement->lister(
            $entreprise,
            [
                TranchePaiementScope::AXE_PRIME => TranchePaiementScope::PAYEE,
                TranchePaiementScope::AXE_COMMISSION => TranchePaiementScope::IMPAYEE,
            ],
            null,
            null,
            1,
            1,
            $invite,
        );
        $nb = (int) ($r['totalItems'] ?? 0);

        return [
            'axe'         => 'commissions',
            'libelle'     => $nb > 0
                ? sprintf('%d commission(s) exigible(s) à recouvrer auprès des assureurs', $nb)
                : 'Aucune commission à recouvrer',
            'compte'      => $nb,
            'montant'     => (float) ($r['totaux']['totalSoldeCommission'] ?? 0),
            'urgence'     => $nb > 0 ? self::URGENCE['commissions'] : 0,
            'actionnable' => $nb > 0,
        ];
    }

    private function axeRetros(Entreprise $entreprise, Invite $invite): array
    {
        // « Rétro à verser MAINTENANT » : la dette rétro existe (solde > 0) ET la commission
        // partageable a été encaissée — sans quoi la dette envers le partenaire n'est pas
        // encore née. C'est le seul flux où le courtier est lui-même débiteur.
        $r  = $this->tranchePaiement->lister(
            $entreprise,
            [
                TranchePaiementScope::AXE_RETRO => TranchePaiementScope::IMPAYEE,
                TranchePaiementScope::AXE_COMMISSION => TranchePaiementScope::PAYEE,
            ],
            null,
            null,
            1,
            1,
            $invite,
        );
        $nb = (int) ($r['totalItems'] ?? 0);

        return [
            'axe'         => 'retros',
            'libelle'     => $nb > 0
                ? sprintf('%d rétrocommission(s) à reverser aux partenaires', $nb)
                : 'Aucune rétrocommission à reverser',
            'compte'      => $nb,
            'montant'     => (float) ($r['totaux']['totalRetroExigible'] ?? 0),
            'urgence'     => $nb > 0 ? self::URGENCE['retros'] : 0,
            'actionnable' => $nb > 0,
        ];
    }

    private function axeBordereaux(): array
    {
        // Rappel TEMPOREL : les bordereaux de production sont fournis par les assureurs
        // à la clôture mensuelle — c'est en fin / début de mois qu'il faut les demander,
        // les analyser et facturer les commissions en lot (note de débit).
        $jour       = (int) (new \DateTimeImmutable('now'))->format('j');
        $finDeMois  = $jour >= 25 || $jour <= 5;

        return [
            'axe'         => 'bordereaux',
            'libelle'     => $finDeMois
                ? 'Fin de mois : demander les bordereaux de production aux assureurs et facturer les commissions en lot'
                : 'Bordereaux : rien d’urgent hors clôture mensuelle',
            'compte'      => 0,
            'urgence'     => $finDeMois ? self::URGENCE['bordereaux'] : 0,
            'actionnable' => $finDeMois,
        ];
    }

    private function axeFiscal(Entreprise $entreprise): array
    {
        // Obligation FISCALE DU CABINET (non scopée au portefeuille) : réservée aux
        // invités habilités « Documents comptables » (gating canRead ci-dessus).
        //
        // CourtierSuiviFiscalService, et surtout PAS SuiviFiscalService : ce dernier
        // calcule la TVA de la PLATEFORME JS Brokers (ventes de tokens, dépenses de
        // l'éditeur) et ne prend aucune entreprise — il annoncerait à chaque courtier
        // un solde qui ne le concerne pas, sur l'axe le plus urgent du barème.
        //
        // DEUX DETTES DISTINCTES, jamais additionnées à l'aveugle dans le libellé
        // (cf. la règle « deux mondes » des taxes) : la taxe COLLECTÉE sur les primes
        // pour le compte de l'assureur, et la taxe due par le COURTIER sur ses propres
        // commissions. Le montant agrégé sert au tri, le libellé nomme la plus lourde.
        $exercice = (int) date('Y');
        $suivi    = $this->suiviFiscal->suivi($entreprise, $exercice);

        $soldeAssureur = (float) ($suivi['assureur']['totaux']['solde'] ?? 0);
        $soldeCourtier = (float) ($suivi['courtier']['totaux']['solde'] ?? 0);
        $solde         = max(0.0, $soldeAssureur) + max(0.0, $soldeCourtier);
        $du            = $solde > 0.005;

        $dettes = [];
        if ($soldeAssureur > 0.005) {
            $dettes[] = 'taxe sur primes collectée';
        }
        if ($soldeCourtier > 0.005) {
            $dettes[] = 'taxe sur commissions du courtier';
        }

        return [
            'axe'         => 'fiscal',
            'libelle'     => $du
                ? sprintf('Taxes à reverser (%s) — exercice %d', implode(' et ', $dettes), $exercice)
                : 'Taxes à jour',
            'compte'      => $du ? count($dettes) : 0,
            'montant'     => round($solde, 2),
            'urgence'     => $du ? self::URGENCE['fiscal'] : 0,
            'actionnable' => $du,
        ];
    }

    /**
     * Commissions FACTURÉES non encaissées. Stade suivant de l'axe « commissions »
     * (exigible = pas encore facturée) : une note de débit a été émise à l'assureur
     * et attend son règlement. Périmètre CABINET — une note agrège les commissions
     * de plusieurs clients et n'est rattachable à aucun portefeuille.
     */
    private function axeRecouvrementNotes(Entreprise $entreprise): array
    {
        $r  = $this->noteRecouvrement->lister($entreprise, 1, 1);
        $nb = (int) ($r['totalItems'] ?? 0);

        return [
            'axe'         => 'recouvrement_notes',
            'libelle'     => $nb > 0
                ? sprintf('%d note(s) de débit émise(s) non encaissée(s) à relancer (cabinet)', $nb)
                : 'Aucune note de débit en attente d’encaissement',
            'compte'      => $nb,
            'montant'     => (float) ($r['totaux']['totalSolde'] ?? 0),
            'urgence'     => $nb > 0 ? self::URGENCE['recouvrement_notes'] : 0,
            'actionnable' => $nb > 0,
        ];
    }

    /**
     * Tâches ouvertes : celles qui M'INCOMBENT (assignées à moi ou à personne) et
     * celles de MON PORTEFEUILLE, comptées comme un seul volume de travail. Le
     * libellé isole le retard, seule information qui appelle une action immédiate.
     * Règle de périmètre partagée avec PlanDuJourService (ChargeInviteCritereFactory).
     */
    private function axeTaches(Entreprise $entreprise, Invite $invite): array
    {
        $jour  = new \DateTimeImmutable('today');
        $bases = [$this->chargeCritere->tachesAssignees($invite)];
        if ($this->chargeCritere->aUnPortefeuille('Tache', $invite)) {
            $bases[] = $this->chargeCritere->tachesPortefeuille($invite);
        }

        // Les deux jeux se recoupant, la somme majorerait le compte : on retient le
        // plus large plutôt que d'annoncer un volume que la rubrique ne montrera pas.
        $nb       = 0;
        $enRetard = 0;
        foreach ($bases as $criteres) {
            $nb = max($nb, $this->compter(Tache::class, $criteres, $entreprise));
            $enRetard = max($enRetard, $this->compter(
                Tache::class,
                ['toBeEndedAt' => ['to' => $jour->modify('-1 day')->format('Y-m-d')]] + $criteres,
                $entreprise,
            ));
        }

        return [
            'axe'         => 'taches',
            'libelle'     => match (true) {
                $nb === 0     => 'Aucune tâche ouverte',
                $enRetard > 0 => sprintf('%d tâche(s) ouverte(s), dont %d en retard : suivre les feedbacks puis clôturer', $nb, $enRetard),
                default       => sprintf('%d tâche(s) ouverte(s) : suivre les feedbacks puis clôturer', $nb),
            },
            'compte'      => $nb,
            'urgence'     => $nb > 0 ? self::URGENCE['taches'] : 0,
            'actionnable' => $nb > 0,
        ];
    }

    /**
     * Prochaines actions de feedback dues ou en retard — l'engagement daté pris
     * envers un client au fil d'une tâche. Seule donnée « prochain pas » du
     * workspace, longtemps restée invisible faute d'être requêtée.
     */
    private function axeFeedbacks(Entreprise $entreprise, Invite $invite): array
    {
        $jour  = new \DateTimeImmutable('today');
        $bases = [$this->chargeCritere->feedbacksAuteur($invite, $jour)];
        if ($this->chargeCritere->aUnPortefeuille('Feedback', $invite)) {
            $bases[] = $this->chargeCritere->feedbacksPortefeuille($invite, $jour);
        }

        $nb = 0;
        foreach ($bases as $criteres) {
            $nb = max($nb, $this->compter(Feedback::class, $criteres, $entreprise));
        }

        return [
            'axe'         => 'feedbacks',
            'libelle'     => $nb > 0
                ? sprintf('%d action(s) promise(s) arrivée(s) à échéance (feedbacks)', $nb)
                : 'Aucune action de feedback en attente',
            'compte'      => $nb,
            'urgence'     => $nb > 0 ? self::URGENCE['feedbacks'] : 0,
            'actionnable' => $nb > 0,
        ];
    }

    /**
     * Comptage via le moteur de recherche (même SQL que la rubrique affichée).
     *
     * @param array<string, mixed> $criteres
     */
    private function compter(string $entityClass, array $criteres, Entreprise $entreprise): int
    {
        $result = $this->searchService->search($entityClass, $criteres, $entreprise, null, 1, 1);

        return ($result['status']['code'] ?? 500) === 200 ? (int) ($result['totalItems'] ?? 0) : 0;
    }

    /** Compte des avenants d'une fenêtre d'échéance dans le périmètre de l'invité. */
    private function compterAvenants(Entreprise $entreprise, Invite $invite, string $statutEcheance): int
    {
        $criteria = AvenantEcheanceScope::critereRecherche('Avenant', $statutEcheance)
            + $this->portefeuilleCritere->pour('Avenant', $invite);
        $result = $this->searchService->search(Avenant::class, $criteria, $entreprise, null, 1, 1);

        return ($result['status']['code'] ?? 500) === 200 ? (int) ($result['totalItems'] ?? 0) : 0;
    }
}
