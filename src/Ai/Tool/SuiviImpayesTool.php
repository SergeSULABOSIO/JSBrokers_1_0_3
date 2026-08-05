<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Entity\Tranche;
use App\Services\Search\PortefeuilleCritereFactory;
use App\Services\Search\PortefeuilleScope;
use App\Services\Search\TranchePaiementScope;
use App\Services\Tranche\TranchePaiementService;
use App\Service\Workspace\WorkspaceAccessResolver;

/**
 * Outil de données : suivi des paiements par tranche — primes clients et commissions
 * exigibles, soldes, retards par rapport à l'échéance, triés par urgence. Alimente les
 * relances structurées de l'assistant (« quelles primes sont en retard ? », « relances
 * à faire pour le client X »).
 *
 * FAIL-CLOSED : sans droit de lecture sur Tranche, les données n'existent pas.
 * Source unique : TranchePaiementService (mêmes AXES que les chips de la liste Tranches).
 *
 * TROIS DETTES, JAMAIS UNE SEULE. Le filtre est une combinaison d'axes cumulés en ET, un
 * par débiteur (assuré / assureur / partenaire) plus l'échéance. Chaque ligne porte
 * `dette`, qui NOMME la dette restante, et la sortie porte `repartition` quand aucun axe
 * de dette n'est posé : sans ces deux garde-fous, le modèle lit « 5 lignes » puis « 4
 * soldes de prime à 0 » et croit se contredire (incident du 2026-08-05).
 */
final class SuiviImpayesTool implements AiToolInterface
{
    /** Lignes restituées par page (sortie compacte, économie de tokens). */
    private const MAX_LIGNES = 10;

    private const LIE_A_AUTORISES = ['Client', 'Cotation'];

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly TranchePaiementService $tranchePaiement,
        private readonly PortefeuilleCritereFactory $portefeuilleCritere,
    ) {
    }

    public function name(): string
    {
        return 'suivi_impayes';
    }

    public function description(): string
    {
        return 'Suivi des paiements par tranche : soldes restants de PRIME (due par l\'assuré), '
            . 'de COMMISSION (due par l\'assureur) et de RÉTROCOMMISSION (due par le courtier au '
            . 'partenaire), retards par rapport à la date d\'échéance, triés par urgence (les plus '
            . 'en retard d\'abord). Le filtre `axes` cible UNE dette à la fois ou les croise : '
            . '{prime: impayee} = primes encore dues par les clients ; {prime: payee, commission: '
            . 'impayee} = commissions à collecter MAINTENANT auprès de l\'assureur ; {retro: '
            . 'impayee, commission: payee} = rétros à verser maintenant. À appeler pour : impayés, '
            . 'arriérés, relances à faire, primes ou commissions en retard/dues/exigibles, qui doit '
            . 'payer, soldes dus, rétros à verser aux partenaires. Porte par défaut sur le '
            . 'PORTEFEUILLE de l\'utilisateur, comme la rubrique Tranches affichée (paramètre '
            . 'perimetre). Restreignable à un client ou une cotation via lieA.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'axes' => TranchePaiementScope::proprieteSchema(),
                'lieA' => [
                    'type' => 'object',
                    'description' => 'Restreint aux tranches rattachées à cette fiche (client ou cotation).',
                    'properties' => [
                        'entite' => ['type' => 'string', 'enum' => self::LIE_A_AUTORISES],
                        'id' => ['type' => 'integer', 'minimum' => 1],
                    ],
                    'required' => ['entite', 'id'],
                ],
                'page' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Page de résultats (défaut 1, ' . self::MAX_LIGNES . ' lignes par page).',
                ],
                'perimetre' => PortefeuilleScope::proprieteSchema(),
            ],
            'required' => [],
        ];
    }

    /**
     * Chemin simulé : « impayés », « arriérés », « primes en retard », « relances »…
     * « Liste les tranches » reste le domaine de rechercher_entites.
     */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        // « sont dues », « restent dues », « encore dues » : la formulation naturelle
        // interpose un verbe entre la dette et son qualificatif. Sans ce petit segment
        // optionnel, « quelles primes sont dues ? » — la question même de l'incident —
        // ne déclenchait pas cet outil.
        $verbeIntercale = '(sont |restent |demeurent |encore )?';
        $declencheurEncaissement = (bool) preg_match(
            '/\b(impayes?|arrieres?|relances?|retards? de paiement'
            . '|primes? ' . $verbeIntercale . '(dues?|impayees?|en retard|a collecter)'
            . '|commissions? ' . $verbeIntercale . '(dues?|impayees?|en retard|a collecter)'
            . '|soldes? dus?|qui doit payer)\b/',
            $normalized
        );
        // Flux inverse : rétro évoquée AVEC une notion de paiement/dette (« liste les
        // rétrocommissions » reste le domaine de rechercher_entites).
        $declencheurRetro = preg_match('/\bretro(commission)?s?\b/', $normalized)
            && preg_match('/\b(payer|verser|reverser|dues?|dois|exigibles?|soldes?)\b/', $normalized);
        // Exigibilité : commissions devenues collectables (prime encaissée par l'assureur).
        $declencheurExigible = preg_match('/\bcommissions?\b/', $normalized)
            && preg_match('/\bexigibles?\b|\bcollecter\b|\bencaisser\b/', $normalized);

        if (!$declencheurEncaissement && !$declencheurRetro && !$declencheurExigible) {
            return null;
        }

        // La détection rend une COMBINAISON d'axes, source unique partagée avec les chips :
        // « commission exigible » y devient {prime: payee, commission: impayee}, sa
        // définition exacte, au lieu d'un statut composite opaque.
        $axes = TranchePaiementScope::detecterAxesDepuisTexte($normalized);
        if ($declencheurRetro || preg_match('/\bpartenaires?\b/', $normalized)) {
            // Ce que le courtier doit VERSER aux partenaires, une fois la dette née.
            $axes = [
                TranchePaiementScope::AXE_RETRO => TranchePaiementScope::IMPAYEE,
                TranchePaiementScope::AXE_COMMISSION => TranchePaiementScope::PAYEE,
            ];
        }

        $args = [];
        if (($courts = TranchePaiementScope::versNomsCourts($axes)) !== []) {
            $args['axes'] = $courts;
        }

        // Le périmètre par défaut est celui de l'écran (portefeuille de l'invité) : seule une
        // demande explicite d'élargissement est détectée ici.
        if (($p = PortefeuilleScope::detecterPerimetreDepuisTexte($normalized)) !== null) {
            $args['perimetre'] = $p;
        }

        return $args;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $labels = $this->accessResolver->libellesEntites();

        // FAIL-CLOSED : sans droit de lecture explicite, les données n'existent pas.
        if (!$this->accessResolver->canRead($scope->invite, 'Tranche')) {
            return AiToolResult::horsPerimetre($labels['Tranche'] ?? 'Suivi des paiements (Tranches)');
        }

        // Aucun axe fourni = aucun filtre de dette : la sortie porte alors `repartition`,
        // pour que la réponse puisse nommer chaque dette au lieu de les confondre.
        $axes = TranchePaiementScope::normaliserAxes(is_array($args['axes'] ?? null) ? $args['axes'] : []);

        $lieAEntite = null;
        $lieAId = null;
        if (isset($args['lieA']) && is_array($args['lieA'])) {
            $lieAEntite = (string) ($args['lieA']['entite'] ?? '');
            $lieAId = (int) ($args['lieA']['id'] ?? 0);
            if (!in_array($lieAEntite, self::LIE_A_AUTORISES, true) || $lieAId < 1) {
                return AiToolResult::introuvable($lieAEntite . '#' . $lieAId);
            }
        }

        // PÉRIMÈTRE : par défaut le portefeuille de l'invité, comme le badge « Mon
        // portefeuille » posé par défaut sur la rubrique Tranches. Le libellé vient de la
        // fabrique partagée avec le contrôleur de liste (source unique).
        $perimetreEntreprise = PortefeuilleScope::estEntreprise($args['perimetre'] ?? null);
        $criterePortefeuille = $perimetreEntreprise
            ? []
            : $this->portefeuilleCritere->pour('Tranche', $scope->invite);

        $page = max(1, (int) ($args['page'] ?? 1));
        $resultat = $this->tranchePaiement->lister(
            $scope->entreprise,
            $axes,
            $lieAEntite,
            $lieAId,
            $page,
            self::MAX_LIGNES,
            $criterePortefeuille === [] ? null : $scope->invite
        );

        return AiToolResult::ok(array_filter([
            'date' => (new \DateTimeImmutable('now'))->format('Y-m-d'),
            'filtre' => $axes === [] ? 'Toutes les tranches suivies' : TranchePaiementScope::libelleCombinaison($axes),
            'perimetre' => PortefeuilleScope::libellePerimetre($perimetreEntreprise, $criterePortefeuille),
            'lignes' => array_map(fn (Tranche $t) => $this->projeter($t), $resultat['items']),
            'totaux' => $resultat['totaux'],
            'repartition' => $this->repartition($axes, $resultat['totaux']),
            'total' => $resultat['totalItems'],
            'page' => $resultat['currentPage'],
            'tronque' => $resultat['totalItems'] > count($resultat['items']) ? true : null,
            // NOTE DE CONDUITE (corollaire de conception : tout retour d'outil porte la
            // sienne). C'est une LECTURE : elle donne toute la matière d'un plan
            // d'écriture — id de tranche, échéance, solde — sans en être un. Sans cette
            // note, le modèle a présenté en prose un plan recopié du tour précédent
            // (incident du 2026-08-05, série de « le suivant ») : aucun bouton ne pouvait
            // s'afficher. On nomme donc ici l'écriture qui prolonge la lecture.
            // Le second paragraphe traite l'autre moitié du même incident : nommer la
            // dette au lieu de lire un solde de prime nul comme « rien à faire ».
            'note' => 'LECTURE SEULE : ces lignes ne constituent PAS un plan d\'écriture et ne font '
                . 'apparaître aucun bouton de validation. Pour ENREGISTRER le règlement d\'une prime par '
                . 'l\'assuré sur l\'une de ces tranches, appelle signaler_paiement_prime (trancheId pris '
                . 'dans « id » ci-dessus) — un appel PAR tranche, y compris quand l\'utilisateur enchaîne '
                . '« le suivant » : ne recopie jamais le tableau d\'un plan précédent. '
                . 'NOMME LA DETTE : chaque ligne porte « dette » (prime / commission / prime+commission / '
                . 'retro). Un soldePrime à 0 signifie PRIME SOLDÉE, jamais « rien à faire » — la dette '
                . 'restante est alors celle de l\'assureur. Ne présente comme « primes dues » que des '
                . 'lignes obtenues avec axes.prime = impayee ; sinon rappelle l\'outil avec cet axe.',
        ], static fn ($v) => $v !== null));
    }

    /**
     * Répartition des deux dettes principales sur l'ENSEMBLE filtré (pas la page), émise
     * seulement quand aucun axe de dette n'a été posé — c'est-à-dire quand le total seul
     * serait ambigu. Même remède que la partition echues/aVenir de vigie_echeances : un
     * total global a suffi, une fois, à faire dire à l'assistant l'inverse de la ligne
     * qu'il venait de lire.
     *
     * @param array<string, string> $axes
     * @param array<string, float|int> $totaux
     */
    private function repartition(array $axes, array $totaux): ?array
    {
        if (isset($axes[TranchePaiementScope::AXE_PRIME]) || isset($axes[TranchePaiementScope::AXE_COMMISSION])) {
            return null;
        }

        return [
            'primeImpayee' => [
                'nb' => (int) ($totaux['nbPrimeImpayee'] ?? 0),
                'montant' => (float) ($totaux['totalSoldePrime'] ?? 0),
                'debiteur' => 'l\'assuré',
            ],
            'commissionImpayee' => [
                'nb' => (int) ($totaux['nbCommissionImpayee'] ?? 0),
                'montant' => (float) ($totaux['totalSoldeCommission'] ?? 0),
                'debiteur' => 'l\'assureur',
            ],
            'rappel' => 'Ces deux comptes se CHEVAUCHENT (une tranche peut devoir les deux) et ne '
                . 's\'additionnent pas. Pour une liste de primes dues uniquement, rappelle l\'outil '
                . 'avec axes.prime = impayee.',
        ];
    }

    /**
     * Projection compacte d'une tranche (indicateurs déjà calculés par le service).
     * Les soldes négatifs (trop-perçus) sont restitués à 0 : rien à relancer.
     */
    private function projeter(Tranche $tranche): array
    {
        $echeance = $tranche->getEcheanceAt();
        $joursRetard = null;
        if ($echeance !== null) {
            // Comparaison JOUR à JOUR : l'échéance est ramenée à minuit, sinon l'heure
            // portée par la date tronque un jour de retard sur le diff avec « today ».
            $aujourdhui = new \DateTimeImmutable('today');
            $echeanceJour = \DateTimeImmutable::createFromInterface($echeance)->setTime(0, 0);
            $ecart = (int) $aujourdhui->diff($echeanceJour)->format('%r%a');
            $joursRetard = $ecart < 0 ? -$ecart : 0;
        }

        $soldePrime = max(0.0, (float) ($tranche->primeSoldeDue ?? 0));
        $soldeCommission = max(0.0, (float) ($tranche->solde_restant_du ?? 0));

        return array_filter([
            'id' => $tranche->getId(),
            'tranche' => $tranche->getNom(),
            'client' => $tranche->clientNom ?? null,
            'police' => $tranche->referencePolice ?? null,
            'cotation' => $tranche->cotationNom ?? null,
            'statut' => $tranche->statutPaiement ?? null,
            'urgence' => $tranche->urgenceRecouvrement ?? null,
            'echeance' => $echeance?->format('Y-m-d'),
            'joursRetard' => $joursRetard,
            'prime' => $tranche->primeTranche ?? null,
            'soldePrime' => $soldePrime,
            'soldeCommission' => $soldeCommission,
            // NOMME la dette restante. Sans ce champ, un soldePrime à 0 se lit « rien à
            // faire » alors que l'assureur doit encore sa commission — les deux dettes ont
            // des débiteurs différents et ne se compensent jamais.
            'dette' => $this->nommerDette($soldePrime, $soldeCommission, $tranche),
            // Signaux d'exigibilité (absents quand rien n'est exigible, pour rester compact) :
            // commission à collecter auprès de l'assureur (prime payée/signalée) et rétro
            // partenaire à verser (commission partageable encaissée).
            'commissionExigible' => ($tranche->commissionExigible ?? 0) > 0 ? (float) $tranche->commissionExigible : null,
            'retroAPayer' => ($tranche->retroCommissionExigible ?? 0) > 0 ? (float) $tranche->retroCommissionExigible : null,
        ], static fn ($v) => $v !== null && $v !== '' && $v !== 'N/A');
    }

    /**
     * Nomme la ou les dettes restantes d'une tranche, du point de vue du DÉBITEUR.
     * « soldee » n'est pas « rien à faire » : la rétro peut rester à verser, et c'est le
     * seul flux où le courtier est lui-même débiteur.
     */
    private function nommerDette(float $soldePrime, float $soldeCommission, Tranche $tranche): string
    {
        $dettes = [];
        if ($soldePrime > 0) {
            $dettes[] = 'prime';
        }
        if ($soldeCommission > 0) {
            $dettes[] = 'commission';
        }
        if ($dettes === [] && (float) ($tranche->retroCommissionSolde ?? 0) > 0) {
            return 'retro';
        }

        return $dettes === [] ? 'soldee' : implode('+', $dettes);
    }
}
