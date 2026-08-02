<?php

namespace App\Ai\Tool;

use App\Ai\Mouvement\MouvementAvenant;
use App\Ai\Mouvement\MouvementAvenantBuilder;
use App\Ai\Scope\AiScope;
use App\Entity\Avenant;
use App\Services\AvenantRenouvellementResolver;
use App\Services\JSBDynamicSearchService;

/**
 * Outil DÉDIÉ aux quatre MOUVEMENTS d'une police existante : renouvellement,
 * prorogation, annulation, résiliation.
 *
 * Sa raison d'être est de supprimer les questions. Par le chemin générique, un
 * renouvellement « à l'identique » obligeait l'assistant à dérouler le parcours
 * de saisie, l'inventaire des champs et un jeu de questions/réponses — alors que
 * la réponse à CHACUNE de ces questions est déjà dans la police en cours. Ici,
 * un seul argument suffit (la police), le serveur calcule le décalque complet,
 * et l'utilisateur n'a plus qu'à valider. Les trois autres mouvements exigent
 * une date : c'est la SEULE information que l'assistant ait le droit de demander.
 *
 * Il n'introduit AUCUNE logique d'écriture : MouvementAvenantBuilder traduit le
 * mouvement en opérations génériques, et l'outil DÉLÈGUE à preparer_operations
 * (donc au même WorkspaceMutationService : validation, budget, verrou de plan,
 * boutons de validation, exécution, journal). DRY strict.
 *
 * Comme preparer_operations, il n'écrit rien : il prépare un PLAN + un BUDGET
 * que l'utilisateur valide ou annule.
 */
final class PreparerMouvementAvenantTool implements AiToolInterface
{
    /** Nombre maximal de polices proposées quand la référence est ambiguë. */
    private const MAX_CANDIDATS = 8;

    public function __construct(
        private readonly PreparerOperationsTool $preparer,
        private readonly MouvementAvenantBuilder $builder,
        private readonly JSBDynamicSearchService $searchService,
        private readonly AvenantRenouvellementResolver $renouvellementResolver,
    ) {
    }

    public function name(): string
    {
        return 'preparer_mouvement_avenant';
    }

    public function description(): string
    {
        return 'Fait ÉVOLUER une police EXISTANTE — les quatre mouvements du métier : '
            . '"renouvellement" (« renouvelle / reconduis cette police », « à l\'identique », « refais la même '
            . 'police pour l\'année prochaine »), "prorogation" (« proroge / prolonge de 20 jours »), '
            . '"annulation" (« annule cet avenant au 15 juin 2026 ») et "resiliation" (« résilie au 30 janvier »). '
            . 'Désigne la police par avenantId, ou par "police" (la référence dictée par l\'utilisateur). '
            . 'RENOUVELLEMENT : ne demande RIEN à l\'utilisateur — période, prime, composition, échéancier, '
            . 'commission, assureur, référence, partenaires et conditions de partage sont tous dérivés de la '
            . 'police de base par le serveur. PROROGATION / ANNULATION / RÉSILIATION : fournis la durée '
            . '(dureeJours) ou la date d\'effet (dateEffet) — c\'est la seule chose à demander, et seulement si '
            . 'l\'utilisateur ne l\'a pas déjà dite. N\'appelle NI parcours_saisie NI inventaire_champs pour un '
            . 'mouvement. Si l\'utilisateur annonce un écart (« la prime passe à 12 000 », « à effet du 1er août », '
            . '« chez SUNU »), passe-le dans le MÊME appel. L\'outil prépare un PLAN + BUDGET à valider (comme '
            . 'preparer_operations) ; après validation, c\'est TOI qui enregistres. N\'écrit rien.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'mouvement' => [
                    'type' => 'string',
                    'enum' => MouvementAvenant::valeurs(),
                    'description' => 'Le mouvement à préparer. "renouvellement" reconduit la police à l\'identique '
                        . 'sur la période suivante ; "prorogation" prolonge la couverture en cours ; "annulation" et '
                        . '"resiliation" y mettent fin à une date donnée.',
                ],
                'avenantId' => [
                    'type' => 'integer',
                    'description' => 'Identifiant de la police (avenant) à faire évoluer. À privilégier : sans '
                        . 'ambiguïté. Obtenu par rechercher_entites ou vigie_echeances.',
                ],
                'police' => [
                    'type' => 'string',
                    'description' => 'À défaut d\'identifiant : la référence de police dictée par l\'utilisateur. '
                        . 'Si plusieurs polices correspondent, l\'outil renvoie la liste à départager.',
                ],
                'dureeJours' => [
                    'type' => 'integer',
                    'description' => 'PROROGATION : nombre de jours de prolongation au-delà de l\'échéance actuelle.',
                ],
                'dateEffet' => [
                    'type' => 'string',
                    'description' => 'ANNULATION / RÉSILIATION : date de prise d\'effet, au format AAAA-MM-JJ.',
                ],
                'dateDebut' => [
                    'type' => 'string',
                    'description' => 'ÉCART facultatif : prise d\'effet imposée (AAAA-MM-JJ), au lieu du lendemain '
                        . 'de l\'échéance. Ne le renseigne QUE si l\'utilisateur l\'a dicté.',
                ],
                'dateFin' => [
                    'type' => 'string',
                    'description' => 'ÉCART facultatif : échéance imposée (AAAA-MM-JJ), au lieu d\'une durée '
                        . 'identique à la police de base.',
                ],
                'referencePolice' => [
                    'type' => 'string',
                    'description' => 'ÉCART facultatif : nouvelle référence de police (par défaut, celle de la '
                        . 'police de base est reconduite).',
                ],
                'numero' => [
                    'type' => 'string',
                    'description' => 'ÉCART facultatif : numéro d\'avenant imposé (par défaut, celui de la police '
                        . 'de base est incrémenté).',
                ],
                'assureurId' => [
                    'type' => 'integer',
                    'description' => 'ÉCART facultatif : autre assureur que celui de la police de base.',
                ],
                'composantes' => [
                    'type' => 'array',
                    'description' => 'ÉCART facultatif : composition de la prime dictée par l\'utilisateur, qui '
                        . 'REMPLACE celle de la police de base. Ne la renseigne que s\'il a donné des montants.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'nom' => ['type' => 'string', 'description' => 'Libellé de la composante (ex. "Prime nette", "TVA").'],
                            'montant' => ['type' => 'number', 'description' => 'Montant de la composante.'],
                        ],
                        'required' => ['nom', 'montant'],
                    ],
                ],
                'remplacerPlanEnAttente' => [
                    'type' => 'boolean',
                    'description' => 'Ne mets true QUE si un plan attend déjà une décision ET que l\'utilisateur '
                        . 'demande de le CHANGER : le plan en attente sera annulé et remplacé.',
                ],
            ],
            'required' => ['mouvement'],
        ];
    }

    /** Chemin simulé neutralisé : un mouvement relève du LLM réel (comme preparer_operations). */
    public function match(string $question, AiScope $scope): ?array
    {
        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $mouvement = MouvementAvenant::depuis((string) ($args['mouvement'] ?? ''));
        if ($mouvement === null) {
            return AiToolResult::introuvable(sprintf(
                'mouvement de police (valeurs acceptées : %s)',
                implode(', ', MouvementAvenant::valeurs()),
            ));
        }

        // Police résolue STRICTEMENT dans l'entreprise du scope (fail-closed).
        $candidats = $this->candidats($args, $scope);
        if ($candidats === []) {
            return AiToolResult::introuvable(sprintf(
                'police %s',
                isset($args['police']) ? '« ' . trim((string) $args['police']) . ' »' : '#' . (int) ($args['avenantId'] ?? 0),
            ));
        }
        if (count($candidats) > 1) {
            return AiToolResult::ok([
                'pret'   => false,
                'ambigu' => array_map($this->resumer(...), $candidats),
                'note'   => 'Plusieurs polices correspondent à cette référence. Demande à l’utilisateur LAQUELLE, '
                    . 'en UNE ligne (liste courte), puis rappelle cet outil avec « avenantId ». Ne pose aucune '
                    . 'autre question et ne présente aucun plan tant qu’il n’a pas tranché.',
            ]);
        }

        $base = $candidats[0];

        // Idempotence : une police ne porte qu'un mouvement à la fois. Sans cette
        // garde, redemander « renouvelle-la » créerait un second jeu d'écritures.
        if ($base->getPisteDeRenouvellement() !== null) {
            // On ne dit pas seulement QU'un mouvement existe, on dit ce que la police
            // est DEVENUE : « déjà renouvelée par l'avenant #120 » plutôt que « porte
            // une opportunité dérivée ». Sans ce fait, l'assistante décrivait le
            // mouvement comme simplement « initié » et laissait croire la police
            // encore à renouveler.
            $suite = $this->renouvellementResolver->resoudre($base);

            return AiToolResult::ok([
                'pret'         => false,
                'dejaTraite'   => true,
                'police'       => $base->getReferencePolice(),
                'mouvementExistant' => $base->getPisteDeRenouvellement()->getNom(),
                'statutRenouvellement' => $suite['statut'],
                'suiteDeLaPolice' => $suite['phrase'],
                'note'         => 'Cette police porte DÉJÀ un mouvement enregistré. Ne prépare AUCUN plan et '
                    . 'n’affiche aucun bouton. Dis à l’utilisateur ce que la police est DEVENUE, en reprenant '
                    . '« suiteDeLaPolice » : si un avenant lui succède, NOMME-le (numéro et période) ; si le '
                    . 'mouvement est amorcé sans avenant, dis-le tel quel. Propose d’ouvrir la fiche pour modifier.',
            ]);
        }

        $decalque = $this->builder->construire($mouvement, $base, $args, $scope);

        if (isset($decalque['bloquant'])) {
            return AiToolResult::ok([
                'pret'     => false,
                'bloquant' => $decalque['bloquant'],
                'note'     => 'Explique ce blocage à l’utilisateur en une phrase et propose la correction. '
                    . 'Ne présente aucun plan.',
            ]);
        }

        if (isset($decalque['aDemander'])) {
            return AiToolResult::ok([
                'pret'      => false,
                'aDemander' => $decalque['aDemander'],
                'note'      => 'Il manque la SEULE information que tu aies le droit de demander pour ce mouvement. '
                    . 'Pose la question telle quelle, en UNE ligne, sans rien demander d’autre (ni période, ni '
                    . 'prime, ni assureur : tout le reste est dérivé de la police). Rappelle ensuite cet outil.',
            ]);
        }

        // Traduction en opérations génériques + délégation au moteur unique : le
        // verrou anti-empilement de plans, le budget et les boutons « Valider et
        // exécuter » / « Annuler » viennent de là, jamais d'ici.
        $resultat = $this->preparer->execute([
            'operations'             => $decalque['operations'],
            'remplacerPlanEnAttente' => ($args['remplacerPlanEnAttente'] ?? false) === true,
        ], $scope);

        if ($resultat->status !== AiToolResult::STATUS_OK) {
            return $resultat;
        }

        // Le contexte du décalque enrichit la réponse du moteur : c'est ce que
        // l'assistant doit ANNONCER (défauts appliqués, écarts, ce qui est
        // reconduit) plutôt que de le demander.
        $data = $resultat->data;
        $data['avertissements'] = array_values(array_unique(array_merge(
            array_map(strval(...), (array) ($data['avertissements'] ?? [])),
            $decalque['avertissements'],
        )));
        $data += [
            'mouvement'        => $mouvement->value,
            'libelleMouvement' => $mouvement->libelle(),
            'source'           => $decalque['source'],
            'defauts'          => $decalque['defauts'],
            'ecarts'           => $decalque['ecarts'],
            'reconduit'        => $decalque['reconduit'],
            'consigne'         => 'Présente le plan et le budget comme pour toute écriture, et ÉNONCE les '
                . '« defauts » : ce sont les hypothèses que tu as appliquées à la place de questions. Si « ecarts » '
                . 'n’est pas vide, dis-le explicitement — ce n’est alors plus « à l’identique ». Ne redemande '
                . 'AUCUNE des informations dérivées. Les tâches et comptes-rendus de la police de base ne sont pas '
                . 'repris ; la tâche de suivi du paiement ajoutée par le plan est décochable.',
        ];

        return AiToolResult::ok($data, $resultat->uiAction);
    }

    /**
     * Polices candidates dans l'entreprise du scope : par identifiant (une seule)
     * ou par référence de police (plusieurs possibles, à départager).
     *
     * @return array<int, Avenant>
     */
    private function candidats(array $args, AiScope $scope): array
    {
        $id = (int) ($args['avenantId'] ?? 0);
        if ($id > 0) {
            $result = $this->searchService->search(Avenant::class, ['id' => $id], $scope->entreprise, null, 1, 1);
        } else {
            $police = trim((string) ($args['police'] ?? ''));
            if ($police === '') {
                return [];
            }
            $result = $this->searchService->search(
                Avenant::class,
                ['referencePolice' => ['operator' => 'LIKE', 'value' => $police, 'mode' => 'contains']],
                $scope->entreprise,
                null,
                1,
                self::MAX_CANDIDATS,
            );

            // Une correspondance EXACTE tranche d'elle-même : « AXA-1 » ne doit pas
            // rester ambiguë au seul motif que « AXA-12 » existe aussi.
            $exacts = array_values(array_filter(
                array_filter($result['data'] ?? [], static fn ($a) => $a instanceof Avenant),
                static fn (Avenant $a) => mb_strtolower(trim((string) $a->getReferencePolice())) === mb_strtolower($police),
            ));
            if (count($exacts) === 1) {
                return $exacts;
            }
        }

        if (($result['status']['code'] ?? 500) !== 200) {
            return [];
        }

        return array_values(array_filter($result['data'] ?? [], static fn ($a) => $a instanceof Avenant));
    }

    /** @return array<string, mixed> Fiche courte d'une police, pour départager une ambiguïté. */
    private function resumer(Avenant $avenant): array
    {
        $piste = $avenant->getCotation()?->getPiste();

        return [
            'id'       => $avenant->getId(),
            'police'   => $avenant->getReferencePolice(),
            'numero'   => $avenant->getNumero(),
            'client'   => $piste?->getClient()?->getNom(),
            'assureur' => $avenant->getCotation()?->getAssureur()?->getNom(),
            'periode'  => sprintf(
                '%s → %s',
                $avenant->getStartingAt()?->format('d/m/Y') ?? '?',
                $avenant->getEndingAt()?->format('d/m/Y') ?? '?',
            ),
        ];
    }
}
