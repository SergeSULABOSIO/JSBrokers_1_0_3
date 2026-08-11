<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Finance\EconomieTranche;
use App\Ai\Presentation\Colonnes;
use App\Ai\Scope\AiScope;
use App\Entity\PaiementPrime;
use App\Entity\Tranche;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\PortefeuilleCritereFactory;
use App\Services\Search\PortefeuilleScope;
use App\Services\Tranche\TranchePaiementService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Outil de données : les SIGNALEMENTS de paiement de prime (entité PaiementPrime) —
 * qui a réglé quoi, quand, sous quelle référence, avec quelles preuves.
 *
 * Marché par défaut : l'ASSUREUR facture et encaisse la prime ; le courtier ne fait que
 * TRACER l'information pour savoir quand sa commission devient exigible. Un signalement
 * n'impacte donc JAMAIS la trésorerie du cabinet — à ne pas confondre avec l'entité
 * Paiement (rubrique Paiements), qui est, elle, un encaissement du courtier.
 *
 * Deux modes :
 *  - CIBLÉ (trancheId) : les signalements d'UNE tranche, replacés dans son contexte de
 *    règlement (prime totale, part signalée, solde, exigibilité de la commission) ;
 *  - TRANSVERSAL (sans trancheId) : la liste des signalements de l'entreprise, filtrable
 *    par rattachement (client/cotation) et par période de paiement.
 *
 * FAIL-CLOSED : PaiementPrime est une SOUS-ENTITÉ STRUCTURELLE gouvernée par sa Tranche
 * (même règle que TrancheController::getPaiementPrimeContext côté écriture) — sans droit
 * de LECTURE sur Tranche, ces données n'existent pas pour l'assistant. Scoping entreprise
 * doublement assuré par JSBDynamicSearchService (la tranche ET les signalements portent
 * un lien `entreprise`).
 *
 * PÉRIMÈTRE : le mode ciblé désigne un enregistrement par son id — pas de filtre
 * portefeuille, comme lire_fiche et signaler_paiement_prime. Le mode transversal est une
 * LISTE : le portefeuille de l'invité s'y applique par défaut, comme à l'écran.
 *
 * L'ÉCONOMIE DE LA TRANCHE VOYAGE AVEC LE SIGNALEMENT (incident du 2026-08-11). Un
 * signalement ne dit, à lui seul, qu'une date et un montant ; mais il désigne sa TRANCHE,
 * et une tranche sait tout le reste — prime attendue, commission HT, taxes sur la
 * commission, commission exigible, rétrocommission. On hydrate donc les tranches de la
 * page (TranchePaiementService, la source unique) et on joint cette économie à chaque
 * ligne via EconomieTranche. Sans cela, l'assistant, à qui l'on demandait « ajoute une
 * colonne pour les commissions exigibles », répondait qu'elles « ne figurent pas dans les
 * résultats » : c'était vrai, et parfaitement évitable — la clé était dans la ligne.
 */
final class PaiementsPrimeTool implements AiToolInterface, AiToolConditionnel
{
    /** Lignes restituées par page (sortie compacte, économie de tokens). */
    private const MAX_LIGNES = 20;

    /**
     * Rattachements admis en mode transversal : nom court => chemin de relations depuis
     * PaiementPrime (le moteur de recherche joint chaque segment et filtre par identité).
     */
    private const LIE_A_CHEMINS = [
        'Cotation' => 'tranche.cotation',
        'Client'   => 'tranche.cotation.piste.client',
    ];

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly JSBDynamicSearchService $searchService,
        private readonly TranchePaiementService $tranchePaiement,
        private readonly PortefeuilleCritereFactory $portefeuilleCritere,
        // Sert UNIQUEMENT au préchargement du contexte des lignes (client, assureur,
        // police) : sans lui, chaque ligne déclencherait ses propres chargements
        // paresseux le long de tranche → cotation → piste → client.
        private readonly EntityManagerInterface $em,
        // Préchargement du graphe que parcourent ensuite les INDICATEURS des tranches
        // (revenus, chargements, articles…) : un nombre fixe de requêtes au lieu d'une
        // rafale par tranche. Même méthode que la liste des avenants, mêmes règles.
        private readonly IndicatorCalculationHelper $calculationHelper,
    ) {
    }

    public function name(): string
    {
        return 'paiements_prime';
    }

    public function description(): string
    {
        return 'Consulte les SIGNALEMENTS de paiement de prime : date de règlement, montant, '
            . 'référence, description et nombre de pièces justificatives. CHAQUE LIGNE PORTE '
            . 'AUSSI TOUTE L\'ÉCONOMIE DE SA TRANCHE, sans second appel : client, assureur, '
            . 'police, statut, prime attendue / signalée / solde, commission HT, taxe assureur '
            . '(TVA) et taxe courtier (ARCA) avec leurs taux, commission TTC, commission '
            . 'encaissée, solde et COMMISSION EXIGIBLE, rétrocommission à verser au partenaire. '
            . 'Avec trancheId, restitue les signalements d\'UNE tranche et son contexte complet '
            . 'de règlement ; sans trancheId, liste les signalements de l\'entreprise, '
            . 'filtrables par client ou cotation (lieA) et par période de paiement (du/au). '
            . 'À appeler dès que la question porte sur le paiement de la PRIME par l\'assuré ou '
            . 'sur ce qu\'il rapporte au cabinet : « la prime de cette tranche a-t-elle été '
            . 'payée ? », « quels paiements de prime ont été signalés ? », « quelles commissions '
            . 'ces règlements rendent-ils exigibles ? », « quelles taxes sur ces commissions ? ». '
            . 'ATTENTION : un signalement est DÉCLARATIF — l\'assureur encaisse la prime, jamais '
            . 'la trésorerie du cabinet ; ne jamais confondre avec l\'entité Paiement (rubrique '
            . 'Paiements = encaissements du courtier). Pour EN CRÉER un, utiliser '
            . 'signaler_paiement_prime.';
    }

    public function aiguillage(): string
    {
        return 'Le paiement de la PRIME par l\'assuré : « la prime a-t-elle été payée ? », « quels paiements de '
            . 'prime signalés, quand, pour quel montant ? » (trancheId pour une tranche précise). Chaque ligne '
            . 'rend aussi la commission, ses taxes et son exigibilité : une relance du type « ajoute les '
            . 'commissions exigibles / les taxes de ces tranches » se satisfait des lignes DÉJÀ obtenues, sans '
            . 'rappeler d\'outil. Ne confonds jamais avec l\'entité Paiement, qui est la trésorerie du cabinet.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'trancheId' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Mode ciblé : identifiant de la tranche dont on veut les '
                        . 'signalements (obtenu via rechercher_entites ou une fiche attachée).',
                ],
                'lieA' => [
                    'type' => 'object',
                    'description' => 'Mode transversal : restreint aux signalements des tranches '
                        . 'rattachées à cette fiche (client ou cotation).',
                    'properties' => [
                        'entite' => ['type' => 'string', 'enum' => array_keys(self::LIE_A_CHEMINS)],
                        'id' => ['type' => 'integer', 'minimum' => 1],
                    ],
                    'required' => ['entite', 'id'],
                ],
                'du' => [
                    'type' => 'string',
                    'description' => 'Mode transversal : début de la période de paiement, AAAA-MM-JJ.',
                ],
                'au' => [
                    'type' => 'string',
                    'description' => 'Mode transversal : fin de la période de paiement, AAAA-MM-JJ.',
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
     * Chemin simulé : formulation INTERROGATIVE sur le paiement d'une prime — « quels
     * paiements de prime sur la tranche 12 ? », « la prime de la tranche 5 a-t-elle été
     * payée ? », « historique des primes réglées ce mois-ci ». Les formulations
     * IMPÉRATIVES (« signale / enregistre le paiement… ») restent à signaler_paiement_prime.
     */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        // Question sur une prime, formulée pour VOIR : l'impératif (« signale le
        // paiement… ») reste le domaine de signaler_paiement_prime.
        if (!PaiementPrimeIntent::concerne($normalized) || !PaiementPrimeIntent::estInterrogatif($normalized)) {
            return null;
        }

        $args = [];
        if (preg_match('/\btranche\s*(?:n[°o]?\s*)?#?(\d+)\b/u', $normalized, $m)) {
            $args['trancheId'] = (int) $m[1];

            return $args; // Mode ciblé : le périmètre portefeuille ne s'applique pas.
        }

        if (($p = PortefeuilleScope::detecterPerimetreDepuisTexte($normalized)) !== null) {
            $args['perimetre'] = $p;
        }

        return $args;
    }

    /** Miroir exact de la garde d'execute() : ne pas décrire un outil qui refusera. */
    public function estDisponible(AiScope $scope): bool
    {
        return $this->accessResolver->canRead($scope->invite, 'Tranche');
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $labels = $this->accessResolver->libellesEntites();
        $libelleTranche = $labels['Tranche'] ?? 'Tranches';

        // FAIL-CLOSED : sous-entité gouvernée par sa tranche — la lecture des Tranches
        // conditionne l'accès aux signalements de paiement de prime.
        if (!$this->accessResolver->canRead($scope->invite, 'Tranche')) {
            return AiToolResult::horsPerimetre($libelleTranche);
        }

        $trancheId = (int) ($args['trancheId'] ?? 0);

        return $trancheId > 0
            ? $this->executerCible($trancheId, $args, $scope, $libelleTranche)
            : $this->executerTransversal($args, $scope);
    }

    /** Mode CIBLÉ : les signalements d'une tranche, replacés dans son contexte de règlement. */
    private function executerCible(int $trancheId, array $args, AiScope $scope, string $libelleTranche): AiToolResult
    {
        // Scoping : la tranche doit exister DANS l'entreprise du scope.
        $result = $this->searchService->search(Tranche::class, ['id' => $trancheId], $scope->entreprise, null, 1, 1);
        $tranche = $result['data'][0] ?? null;
        if (($result['status']['code'] ?? 500) !== 200 || !$tranche instanceof Tranche) {
            return AiToolResult::introuvable(sprintf('%s #%d', $libelleTranche, $trancheId));
        }

        // Indicateurs calculés par le MÊME moteur que la rubrique Tranches et suivi_impayes.
        $this->tranchePaiement->chargerIndicateurs([$tranche]);

        $page = max(1, (int) ($args['page'] ?? 1));
        $signalements = $this->searchService->search(
            PaiementPrime::class,
            ['tranche' => ['operator' => '=', 'value' => $trancheId]],
            $scope->entreprise,
            null,
            $page,
            self::MAX_LIGNES,
        );
        if (($signalements['status']['code'] ?? 500) !== 200) {
            return AiToolResult::introuvable(sprintf('%s #%d', $libelleTranche, $trancheId));
        }

        $commissionExigible = (float) ($tranche->commissionExigible ?? 0);

        return AiToolResult::ok(array_filter([
            'tranche' => array_filter([
                'id' => $tranche->getId(),
                'nom' => $tranche->getNom(),
                'client' => $tranche->clientNom ?? null,
                'assureur' => $tranche->assureurNom ?? null,
                'police' => $tranche->referencePolice ?? null,
                'echeance' => $tranche->getEcheanceAt()?->format('Y-m-d'),
                'statutPaiement' => $tranche->statutPaiement ?? null,
                'urgence' => $tranche->urgenceRecouvrement ?? null,
            ], static fn ($v) => $v !== null && $v !== '' && $v !== 'N/A'),
            'prime' => [
                'totale' => round((float) ($tranche->primeTranche ?? 0), 2),
                'payee' => round((float) ($tranche->primePayee ?? 0), 2),
                'signalee' => round((float) ($tranche->primeDeclareePayee ?? 0), 2),
                'solde' => round(max(0.0, (float) ($tranche->primeSoldeDue ?? 0)), 2),
            ],
            // CE QUE CE RÈGLEMENT RAPPORTE AU CABINET. Structuré par notion (une seule
            // tranche décrite, rien ne sera rendu en tableau) : commission HT, ses deux
            // taxes avec leurs taux LUS, TTC, encaissé, solde, exigible — puis le flux
            // inverse, la rétrocommission due au partenaire. `commissionExigible` est
            // conservée à la racine : elle est nommée telle quelle par les tests et les
            // réponses depuis l'origine de l'outil.
            'commissionExigible' => $commissionExigible > 0 ? round($commissionExigible, 2) : null,
            ...EconomieTranche::bloc($tranche),
            'signalements' => array_map(fn (PaiementPrime $p) => $this->projeter($p), $signalements['data']),
            // Mode ciblé : le client, l'assureur et la police sont déjà donnés par
            // l'entête (une seule tranche), inutile de les répéter à chaque ligne.
            'presentation' => $signalements['data'] === [] ? null : Colonnes::de([
                'date'      => Colonnes::DATE,
                'reference' => Colonnes::TEXTE,
                'montant'   => Colonnes::MONTANT,
                'preuves'   => Colonnes::NOMBRE,
            ]),
            'total' => (int) $signalements['totalItems'],
            'page' => (int) $signalements['currentPage'],
            'totalPages' => (int) $signalements['totalPages'],
            'note' => "Signalement DÉCLARATIF : l'assuré a réglé la prime, encaissée par l'ASSUREUR — "
                . "jamais la trésorerie du cabinet (rien à voir avec l'entité Paiement). C'est ce qui "
                . 'rend la commission de courtage exigible. « payee » peut dépasser « signalee » : la '
                . 'prime est aussi réputée payée par les notes client encaissées ou par un bordereau '
                . 'réconcilié attestant que l\'assureur la détient. ' . EconomieTranche::NOTE,
        ], static fn ($v) => $v !== null && $v !== []));
    }

    /** Mode TRANSVERSAL : les signalements de l'entreprise, dans le périmètre de l'écran. */
    private function executerTransversal(array $args, AiScope $scope): AiToolResult
    {
        $criteria = [];

        $lien = null;
        $lieA = $args['lieA'] ?? null;
        if (\is_array($lieA) && $lieA !== []) {
            $lienType = (string) ($lieA['entite'] ?? '');
            $lienId = (int) ($lieA['id'] ?? 0);
            if (!isset(self::LIE_A_CHEMINS[$lienType]) || $lienId < 1) {
                return AiToolResult::introuvable($lienType . '#' . $lienId);
            }
            // FAIL-CLOSED sur l'entité de rattachement aussi : la référencer, c'est la lire.
            if (!$this->accessResolver->canRead($scope->invite, $lienType)) {
                return AiToolResult::horsPerimetre($this->accessResolver->libellesEntites()[$lienType] ?? $lienType);
            }
            $criteria[self::LIE_A_CHEMINS[$lienType]] = ['operator' => '=', 'value' => $lienId];
            $lien = ['entite' => $lienType, 'id' => $lienId];
        }

        // Période de RÈGLEMENT (paidAt) : plage de dates gérée nativement par le moteur.
        $du = $this->dateValide($args['du'] ?? null);
        $au = $this->dateValide($args['au'] ?? null);
        if ($du !== null || $au !== null) {
            $criteria['paidAt'] = array_filter(['from' => $du, 'to' => $au], static fn ($v) => $v !== null);
        }

        // PÉRIMÈTRE : par défaut le portefeuille de l'invité, comme les rubriques à l'écran.
        $perimetreEntreprise = PortefeuilleScope::estEntreprise($args['perimetre'] ?? null);
        $criterePortefeuille = $perimetreEntreprise
            ? []
            : $this->portefeuilleCritere->pour('PaiementPrime', $scope->invite);

        $page = max(1, (int) ($args['page'] ?? 1));
        $result = $this->searchService->search(
            PaiementPrime::class,
            $criteria + $criterePortefeuille,
            $scope->entreprise,
            null,
            $page,
            self::MAX_LIGNES,
        );
        if (($result['status']['code'] ?? 500) !== 200) {
            return AiToolResult::introuvable('Paiements de prime signalés');
        }

        // Le contexte de CHAQUE ligne (client, assureur, police) en deux requêtes, avant
        // toute projection : sans cela, vingt lignes déclencheraient une soixantaine de
        // chargements paresseux le long de tranche → cotation → piste → client.
        $this->prechargerContexte($result['data']);

        // Puis l'ÉCONOMIE de chaque tranche concernée — hydratée par la source unique du
        // projet, celle qui alimente la rubrique Tranches. Une tranche portant plusieurs
        // signalements n'est hydratée qu'une fois.
        $tranches = $this->tranchesDistinctes($result['data']);
        if ($tranches !== []) {
            $this->calculationHelper->preloadTrancheRelations($tranches);
            $this->tranchePaiement->chargerIndicateurs($tranches);
        }

        $items = array_map(fn (PaiementPrime $p) => $this->projeter($p, true), $result['data']);
        $montantPage = 0.0;
        foreach ($result['data'] as $paiement) {
            $montantPage += (float) ($paiement->getMontant() ?? 0);
        }

        return AiToolResult::ok(array_filter([
            'perimetre' => PortefeuilleScope::libellePerimetre($perimetreEntreprise, $criterePortefeuille),
            'lien' => $lien,
            'du' => $du,
            'au' => $au,
            'items' => $items,
            // Les colonnes de CE tableau, et leur rôle : c'est ce qui fixe l'ordre,
            // l'alignement des montants, le format des dates et la ligne de totaux —
            // côté modèle comme côté repli PHP. « description » et « preuves » restent
            // dans les données sans être affichées d'office.
            //
            // ELLE NE LISTE PAS TOUT CE QUE LA LIGNE PORTE, ET C'EST VOULU. Chaque ligne
            // rend désormais une vingtaine de clés ; six se lisent d'un coup d'œil. Le
            // contrat de présentation autorise le modèle à en promouvoir une AUTRE quand
            // l'utilisateur la demande, à la seule condition qu'elle figure dans les
            // résultats — c'est exactement ce qui manquait à « ajoute une colonne pour les
            // commissions exigibles » (incident du 2026-08-11).
            'presentation' => $items === [] ? null : Colonnes::de([
                'date'      => Colonnes::DATE,
                'client'    => Colonnes::TEXTE,
                'assureur'  => Colonnes::TEXTE,
                'police'    => Colonnes::TEXTE,
                'reference' => Colonnes::TEXTE,
                'montant'   => Colonnes::MONTANT,
            ]),
            // Le rôle des colonnes PROMOUVABLES, pour que l'alignement et le format
            // voyagent avec elles si le modèle en affiche une (un taux s'écrit « 16 % »,
            // un montant « 1 234,50 $ » : sans le rôle, la distinction se devine).
            'colonnesDisponibles' => $items === [] ? null : EconomieTranche::ROLES,
            'colonnesDisponiblesNote' => $items === [] ? null : 'Clés déjà présentes dans chaque ligne : '
                . 'ajoute-en UNE au tableau si l\'utilisateur la demande (« ajoute les commissions '
                . 'exigibles »), avec le format de son rôle, et sans la totaliser.',
            'montantPage' => $items === [] ? null : round($montantPage, 2),
            'montantPageNote' => $items === [] ? null : 'Somme des signalements de CETTE page uniquement — '
                . 'la seule colonne de ce tableau qui s\'additionne ligne à ligne.',
            // Les cumuls de commission, de taxes et d'exigibilité, calculés sur les
            // tranches DISTINCTES de la page : le seul total juste quand deux règlements
            // partiels renvoient à la même tranche.
            'economiePage' => EconomieTranche::cumul($tranches) ?: null,
            'economiePageNote' => $tranches === [] ? null : 'Cumuls calculés sur les TRANCHES DISTINCTES '
                . 'de cette page (nbTranches), jamais sur les lignes : une tranche réglée en deux fois '
                . 'n\'y est comptée qu\'une seule fois.',
            'total' => (int) $result['totalItems'],
            'page' => (int) $result['currentPage'],
            'totalPages' => (int) $result['totalPages'],
            'note' => "Signalements DÉCLARATIFS du règlement des primes par les assurés (encaissées par "
                . "l'ASSUREUR) : aucun impact sur la trésorerie du cabinet. " . EconomieTranche::NOTE,
        ], static fn ($v) => $v !== null && $v !== []));
    }

    /**
     * Les tranches désignées par un lot de signalements, DÉDOUBLONNÉES par identifiant :
     * ce qu'il faut hydrater, et l'assiette des cumuls. Deux règlements partiels d'une
     * même prime ne valent qu'une tranche — les compter deux fois doublerait la commission.
     *
     * @param array<int, PaiementPrime> $paiements
     *
     * @return Tranche[]
     */
    private function tranchesDistinctes(array $paiements): array
    {
        $tranches = [];
        foreach ($paiements as $paiement) {
            $tranche = $paiement->getTranche();
            if ($tranche instanceof Tranche && $tranche->getId() !== null) {
                $tranches[$tranche->getId()] = $tranche;
            }
        }

        return array_values($tranches);
    }

    /**
     * Projection compacte d'un signalement. $avecContexte rattache la ligne à sa
     * tranche, à son client, à son assureur et à sa police — le mode transversal étant
     * une liste, où l'entête ne donne aucun de ces repères.
     *
     * ── L'INCIDENT DU 2026-08-10, ET POURQUOI CES COLONNES SONT ARRIVÉES ICI ────────
     * Cette projection ne rendait NI le client NI l'assureur. Un courtier a demandé la
     * liste des primes signalées : Ket a produit un tableau avec une colonne « Client »
     * remplie en découpant le préfixe des références — « MIC-RC » n'est pas un client,
     * c'est le début de « MIC-RC0012454/2028 » — et une colonne « Assureur » remplie du
     * libellé générique « Assureur Partenaire ». Puis, relancée, elle a répondu que ses
     * outils ne lui donnaient pas le nom de la compagnie : c'était exact.
     *
     * LA RÈGLE QUI EN DÉCOULE, et qui vaut pour tout outil de liste : une colonne
     * PRÉSENTABLE est une colonne RENVOYÉE. Tant qu'une information manque au résultat,
     * le modèle n'a que deux issues — la taire, ou l'inventer. Les autres outils de
     * liste (vigie_echeances, suivi_impayes) rendaient déjà client et assureur ;
     * celui-ci était l'exception.
     *
     * Le rattachement est APLATI (chaînes + trancheId) et non imbriqué : une valeur
     * structurée n'a pas de rendu de cellule honnête, et le tableau de repli comme le
     * contrat de présentation écartent les sous-tableaux.
     */
    private function projeter(PaiementPrime $paiement, bool $avecContexte = false): array
    {
        $tranche = $paiement->getTranche();
        $cotation = $avecContexte ? $tranche?->getCotation() : null;

        // La référence de la POLICE, lue sur le premier avenant de la cotation (même
        // source que Tranche::$referencePolice). Une cotation sans avenant n'est qu'une
        // proposition : elle n'a pas de police, et on rend null plutôt qu'un libellé de
        // remplissage — c'est précisément ce qu'on reproche au modèle.
        $police = $avecContexte
            ? ($cotation?->getAvenants()->first() ?: null)?->getReferencePolice()
            : null;

        return array_filter([
            'id' => $paiement->getId(),
            'date' => $paiement->getPaidAt()?->format('Y-m-d'),
            'client' => $cotation?->getPiste()?->getClient()?->getNom(),
            'assureur' => $cotation?->getAssureur()?->getNom(),
            'police' => $police,
            'tranche' => $avecContexte ? $tranche?->getNom() : null,
            'trancheId' => $avecContexte ? $tranche?->getId() : null,
            'reference' => $paiement->getReference(),
            'montant' => round((float) ($paiement->getMontant() ?? 0), 2),
            'description' => $paiement->getDescription(),
            'preuves' => $paiement->getPreuves()->count() ?: null,
            // L'ÉCONOMIE DE LA TRANCHE, aplatie dans la ligne (prime attendue, commission
            // HT, taxes et leurs taux, TTC, encaissé, solde, exigible, rétro). Elle décrit
            // la TRANCHE et non ce règlement : la note du résultat interdit d'en sommer
            // les colonnes, et `economiePage` fournit les cumuls justes. En mode ciblé,
            // l'entête la donne déjà pour l'unique tranche décrite : on ne la répète pas.
            ...($avecContexte ? EconomieTranche::depuis($tranche) : []),
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Précharge en TROIS requêtes, quel que soit le nombre de lignes, tout ce que
     * projeter() parcourt ensuite : les pièces justificatives, le graphe to-one jusqu'au
     * client et à l'assureur, puis les avenants (pour la référence de police).
     *
     * RÈGLE À NE PAS ENFREINDRE, comme dans preloadAvenantRelations : une seule
     * collection to-many par requête. En joindre deux produirait un produit cartésien
     * dont le coût croît en O(n×m) et annule le gain. Ici chaque requête n'en joint
     * qu'une — et la deuxième, aucune.
     *
     * @param array<int, PaiementPrime> $paiements
     */
    private function prechargerContexte(array $paiements): void
    {
        // Les PIÈCES d'abord : projeter() en compte le nombre, et un Collection::count()
        // sur une collection non initialisée émet son propre COUNT(*) — soit une requête
        // PAR LIGNE. Le défaut préexistait à l'ajout du client et de l'assureur.
        $paiementIds = array_values(array_filter(
            array_map(static fn (PaiementPrime $p) => $p->getId(), $paiements),
        ));
        if ($paiementIds !== []) {
            $this->em->createQuery(
                'SELECT pp, pr
                 FROM App\Entity\PaiementPrime pp
                 LEFT JOIN pp.preuves pr
                 WHERE pp.id IN (:ids)'
            )->setParameter('ids', $paiementIds)->getResult();
        }

        $trancheIds = array_values(array_unique(array_filter(
            array_map(static fn (PaiementPrime $p) => $p->getTranche()?->getId(), $paiements),
        )));
        if ($trancheIds === []) {
            return;
        }

        $tranches = $this->em->createQuery(
            'SELECT t, c, a, p, cl
             FROM App\Entity\Tranche t
             LEFT JOIN t.cotation c
             LEFT JOIN c.assureur a
             LEFT JOIN c.piste p
             LEFT JOIN p.client cl
             WHERE t.id IN (:ids)'
        )->setParameter('ids', $trancheIds)->getResult();

        $cotationIds = array_values(array_unique(array_filter(
            array_map(static fn (Tranche $t) => $t->getCotation()?->getId(), $tranches),
        )));
        if ($cotationIds === []) {
            return;
        }

        $this->em->createQuery(
            'SELECT c, av
             FROM App\Entity\Cotation c
             LEFT JOIN c.avenants av
             WHERE c.id IN (:ids)'
        )->setParameter('ids', $cotationIds)->getResult();
    }

    /** Date AAAA-MM-JJ validée, ou null (une valeur mal formée est simplement ignorée). */
    private function dateValide(mixed $valeur): ?string
    {
        $valeur = trim((string) ($valeur ?? ''));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur) ? $valeur : null;
    }
}
