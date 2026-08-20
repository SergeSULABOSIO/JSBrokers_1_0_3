<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Ai\Trousse\AiToolEcriture;
use App\Entity\Avenant;
use App\Entity\Invite;
use App\Repository\InviteRepository;
use App\Service\RetroAgent\RapportProductionAgentBuilder;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\JSBDynamicSearchService;

/**
 * Outil d'ÉCRITURE : enregistre le reversement d'une rétrocommission à un AGENT INTERNE —
 * une affaire, ou plusieurs d'un seul geste (LOT).
 *
 * Il n'introduit AUCUNE logique d'écriture : il TRADUIT ses arguments en opérations
 * génériques (create ReversementRetroAgent) et DÉLÈGUE à preparer_operations — donc au même
 * WorkspaceMutationService : validation, budget, plan à valider, exécution transactionnelle,
 * journal. DRY strict, même pattern que SignalerPaiementPrimeTool.
 *
 * ── LE LOT EST NATIF ────────────────────────────────────────────────────────────────
 * `lignes` est une LISTE. Une entrée = un reversement isolé ; N entrées = N opérations dans
 * UN SEUL plan, partageant une référence de lot. L'utilisateur voit les N lignes et le total
 * avant de valider ; un seul budget, une seule confirmation. En comptabilité, le lot
 * n'émettra qu'UNE écriture — celle du virement réel — alors que le solde reste exact
 * affaire par affaire.
 *
 * ── CE QUI EST PROPOSÉ, ET CE QUI EST REFUSÉ ────────────────────────────────────────
 * Le montant par défaut est le solde EXIGIBLE de l'affaire, jamais son simple dû : payer un
 * agent avant que le cabinet ait encaissé sa commission, c'est avancer sa trésorerie sur une
 * créance non recouvrée. Une affaire sans solde exigible est refusée avec la raison — pas
 * écartée en silence.
 *
 * ── FAIL-CLOSED, ET PLUS STRICT QUE LA LECTURE ──────────────────────────────────────
 * Consulter ses propres rétrocommissions est un droit ; se les VERSER n'en est pas un.
 * L'outil exige donc canManageInvites() — personne ne se paie soi-même. Les avenants sont
 * de surcroît résolus STRICTEMENT dans l'entreprise du scope.
 */
final class SignalerReversementRetroAgentTool implements AiToolProduisantUnPlan, AiToolConditionnel, AiToolEcriture
{
    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly InviteRepository $inviteRepository,
        private readonly JSBDynamicSearchService $searchService,
        private readonly PreparerOperationsTool $preparer,
        private readonly IndicatorCalculationHelper $calculationHelper,
        private readonly RapportProductionAgentBuilder $rapportBuilder,
    ) {
    }

    public function name(): string
    {
        return 'signaler_reversement_retro_agent';
    }

    public function description(): string
    {
        return 'Enregistre le REVERSEMENT d\'une rétrocommission à un AGENT INTERNE du cabinet, '
            . 'sur une ou PLUSIEURS affaires à la fois. Fournis agentId et `lignes` : une entrée '
            . 'par affaire réglée, chacune avec son avenantId (obtenu via retrocommissions '
            . 'en mode par_ligne) et, si l\'utilisateur le précise, son montant — sinon le solde '
            . 'EXIGIBLE de l\'affaire s\'applique. Plusieurs lignes = UN SEUL virement : elles '
            . 'partagent une référence de lot, et la comptabilité n\'émettra qu\'une écriture '
            . '(charges de personnel, SYSCOHADA 6611). '
            . 'À appeler quand l\'utilisateur veut payer, verser ou régler la rétrocommission d\'un '
            . 'agent. NE PAS utiliser pour un PARTENAIRE externe : sa rétrocommission se facture '
            . 'par note de crédit, tout autre circuit. NE PAS utiliser ouvrir_dialogue avec '
            . 'l\'entité Paiement : Paiement = encaissement du courtier. L\'outil prépare un PLAN '
            . '+ BUDGET à valider ; après validation, c\'est TOI qui enregistres. Pour seulement '
            . 'CONSULTER ce qui est dû ou déjà versé, utiliser retrocommissions.';
    }

    public function aiguillage(): string
    {
        return 'VERSER une rétrocommission à un agent interne (« paie à Alice ce qu\'on lui doit », « règle les '
            . 'trois polices en attente de Bruno »). Ne touche ni aux partenaires externes, ni à l\'entité '
            . 'Paiement.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'agentId' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Identifiant de l\'agent bénéficiaire (un invité du cabinet).',
                ],
                'lignes' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'description' => 'Les affaires réglées par ce versement. Une seule entrée pour '
                        . 'un reversement isolé ; plusieurs pour un virement unique couvrant '
                        . 'plusieurs affaires. Omets `lignes` pour régler TOUT ce qui est exigible.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'avenantId' => [
                                'type' => 'integer',
                                'minimum' => 1,
                                'description' => 'Identifiant de l\'avenant (la police) réglé.',
                            ],
                            'montant' => [
                                'type' => 'number',
                                'description' => 'Montant versé sur cette affaire. Omets-le pour '
                                    . 'le solde exigible (versements partiels possibles).',
                            ],
                        ],
                        'required' => ['avenantId'],
                    ],
                ],
                'paidAt' => [
                    'type' => 'string',
                    'description' => 'Date de sortie des fonds (AAAA-MM-JJ). Omets-la pour aujourd\'hui.',
                ],
                'reference' => [
                    'type' => 'string',
                    'description' => 'Référence du virement. Omets-la pour une référence auto-générée.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Précision facultative sur ce versement.',
                ],
                'remplacerPlanEnAttente' => [
                    'type' => 'boolean',
                    'description' => 'Ne mets true QUE si un plan attend déjà une décision ET que '
                        . 'l\'utilisateur demande de le CHANGER : le plan en attente est annulé et '
                        . 'remplacé. Sinon, tant qu\'un plan attend, la préparation est refusée.',
                ],
            ],
            'required' => ['agentId'],
        ];
    }

    /**
     * Chemin simulé : « verse la rétrocommission de l'agent 7 », « paie l'agent 3 ». L'id
     * doit figurer dans la question (le LLM réel sait le chercher, pas le simulé).
     */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        $veutVerser = preg_match('/\b(verse[rsz]?|paie|paye[rsz]?|regle[rsz]?|reverse[rsz]?)\b/', $normalized);
        $parleDeRetroAgent = preg_match('/\b(retrocom\w*|retro\s*commission\w*)\b/', $normalized)
            && preg_match('/\b(agents?|apporteur\w*|interne\w*)\b/', $normalized);
        if (!$veutVerser || !$parleDeRetroAgent) {
            return null;
        }

        if (!preg_match('/\bagent\s*(?:n[°o]?\s*)?#?(\d+)\b/u', $normalized, $m)) {
            return null;
        }

        return ['agentId' => (int) $m[1]];
    }

    /** Miroir exact de la garde d'execute() : ne pas décrire un outil qui refusera. */
    public function estDisponible(AiScope $scope): bool
    {
        return $this->accessResolver->canManageInvites($scope->invite);
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        // FAIL-CLOSED, et plus strict que la lecture : verser relève de la gestion de
        // l'espace. Personne ne se paie soi-même.
        if (!$this->accessResolver->canManageInvites($scope->invite)) {
            return AiToolResult::horsPerimetre('Reversements de rétrocommission');
        }

        $agentId = (int) ($args['agentId'] ?? 0);
        $agent = $agentId > 0
            ? $this->inviteRepository->findOneBy(['id' => $agentId, 'entreprise' => $scope->entreprise])
            : null;
        if ($agent === null) {
            return AiToolResult::introuvable('Agent #' . $agentId);
        }

        $lignesDemandees = $this->lignesDemandees($args, $agent);
        if ($lignesDemandees === []) {
            return AiToolResult::introuvable(
                'Affaires à régler pour ' . $agent->getNom(),
                'Aucune affaire de cet agent n\'a de solde exigible : une rétrocommission ne '
                . 'devient réclamable qu\'une fois la commission de courtage encaissée par le cabinet.',
            );
        }

        $paidAt = $this->resoudrePaidAt($args);
        $reference = $this->resoudreReference($args, $paidAt);
        // Un lot n'existe qu'à partir de DEUX lignes : un reversement isolé garde
        // lotReference vide, pour ne jamais être fondu dans le lot d'un autre.
        $lotReference = count($lignesDemandees) > 1 ? $reference : null;

        $operations = [];
        $refuses = [];
        foreach ($lignesDemandees as $ligne) {
            $avenant = $this->avenantDuPerimetre((int) $ligne['avenantId'], $scope);
            if ($avenant === null) {
                $refuses[] = sprintf('Avenant #%d : hors de votre espace de travail.', $ligne['avenantId']);
                continue;
            }

            $exigible = round($this->calculationHelper->getAvenantRetroAgentExigible($avenant, $agent), 2);
            $montant = isset($ligne['montant']) && $ligne['montant'] !== null && $ligne['montant'] !== ''
                ? round((float) $ligne['montant'], 2)
                : $exigible;

            if ($montant <= 0.0) {
                $refuses[] = sprintf(
                    'Police %s : rien d\'exigible (la commission de cette affaire n\'est pas encore encaissée).',
                    $avenant->getReferencePolice() ?: ('#' . $avenant->getId()),
                );
                continue;
            }

            $champs = [
                'agent'     => $agent->getId(),
                'avenant'   => $avenant->getId(),
                'montant'   => $montant,
                'paidAt'    => $paidAt,
                'reference' => $reference,
            ];
            if ($lotReference !== null) {
                $champs['lotReference'] = $lotReference;
            }
            if (($args['description'] ?? null) !== null && $args['description'] !== '') {
                $champs['description'] = (string) $args['description'];
            }

            $operations[] = [
                'op'     => 'create',
                'entite' => 'ReversementRetroAgent',
                'champs' => $champs,
                // Une étape par affaire : l'aperçu du plan nomme la police réglée, plutôt
                // que d'afficher N lignes indiscernables.
                'etape'  => sprintf(
                    'Reversement à %s — police %s',
                    $agent->getNom(),
                    $avenant->getReferencePolice() ?: ('#' . $avenant->getId()),
                ),
            ];
        }

        if ($operations === []) {
            return AiToolResult::introuvable(
                'Affaires réglables pour ' . $agent->getNom(),
                implode(' ', $refuses),
            );
        }

        return $this->preparer->execute([
            'operations' => $operations,
            'remplacerPlanEnAttente' => ($args['remplacerPlanEnAttente'] ?? false) === true,
        ], $scope);
    }

    /**
     * Les lignes à traiter : celles fournies, ou — à défaut — TOUTES les affaires de
     * l'agent dont le solde est exigible. « Paie à Alice ce qu'on lui doit » est une
     * demande légitime qui ne nomme aucune police.
     *
     * @return array<int, array{avenantId:int, montant?:float}>
     */
    private function lignesDemandees(array $args, Invite $agent): array
    {
        $fournies = $args['lignes'] ?? null;
        if (is_array($fournies) && $fournies !== []) {
            $lignes = [];
            foreach ($fournies as $ligne) {
                $avenantId = (int) ($ligne['avenantId'] ?? 0);
                if ($avenantId > 0) {
                    $lignes[] = array_filter(
                        ['avenantId' => $avenantId, 'montant' => $ligne['montant'] ?? null],
                        static fn ($v) => $v !== null,
                    );
                }
            }

            return $lignes;
        }

        $lignes = [];
        foreach ($this->rapportBuilder->lignesAVerser($agent) as $ligne) {
            $lignes[] = ['avenantId' => (int) $ligne['avenant']->getId()];
        }

        return $lignes;
    }

    /** L'avenant doit exister DANS l'entreprise du scope : scoping strict. */
    private function avenantDuPerimetre(int $id, AiScope $scope): ?Avenant
    {
        if ($id <= 0) {
            return null;
        }

        $result = $this->searchService->search(Avenant::class, ['id' => $id], $scope->entreprise, null, 1, 1);

        return ($result['status']['code'] ?? 500) === 200 ? ($result['data'][0] ?? null) : null;
    }

    /** Date fournie, sinon maintenant — format attendu par DateTimeType single_text. */
    private function resoudrePaidAt(array $args): string
    {
        $brut = trim((string) ($args['paidAt'] ?? ''));
        try {
            $date = $brut !== '' ? new \DateTimeImmutable($brut) : new \DateTimeImmutable('now');
        } catch (\Throwable) {
            $date = new \DateTimeImmutable('now');
        }

        return $date->format('Y-m-d\TH:i:s');
    }

    /** Référence fournie, sinon générée — même schéma que le picker de l'écran. */
    private function resoudreReference(array $args, string $paidAt): string
    {
        $ref = trim((string) ($args['reference'] ?? ''));
        if ($ref !== '') {
            return $ref;
        }

        try {
            return 'RETRO-' . (new \DateTimeImmutable($paidAt))->format('dmY-His');
        } catch (\Throwable) {
            return 'RETRO-' . (new \DateTimeImmutable('now'))->format('dmY-His');
        }
    }
}
