<?php

namespace App\Ai\Tool;

use App\Ai\Mutation\MutationAllowlist;
use App\Ai\Scope\AiScope;
use App\Entity\Invite;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Service\Workspace\WorkspaceMutationService;
use App\Services\JSBDynamicSearchService;

/**
 * Décrit, de façon TRANSPARENTE, les champs d'une entité métier avant une
 * création/édition par Ket : ce que l'utilisateur DOIT fournir (obligatoires),
 * ce qu'il PEUT fournir (facultatifs) et ce que Ket remplit AUTOMATIQUEMENT
 * d'après ce qu'elle sait déjà (entreprise active, l'invité, son portefeuille
 * s'il n'en gère qu'un). À appeler AVANT de collecter les informations, pour que
 * l'utilisateur sache exactement quoi fournir.
 *
 * FAIL-CLOSED : mêmes gardes que l'écriture — allowlist de mutation + niveau
 * d'accès requis (Écriture en création, Modification en édition). N'écrit rien.
 */
final class InventaireChampsTool implements AiToolInterface
{
    public function __construct(
        private readonly WorkspaceMutationService $mutationService,
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly JSBDynamicSearchService $searchService,
    ) {
    }

    public function name(): string
    {
        return 'inventaire_champs';
    }

    public function description(): string
    {
        return "Décrit les champs d'une entité (" . implode(', ', MutationAllowlist::membres()) . ') pour une '
            . 'création (mode=creation) ou une édition (mode=edition, id requis). Renvoie trois groupes : '
            . 'OBLIGATOIRES (à fournir impérativement), FACULTATIFS (au choix de l\'utilisateur) et AUTO '
            . '(remplis par toi d\'après le contexte : entreprise, l\'utilisateur, et son portefeuille s\'il '
            . 'n\'en gère qu\'un). À appeler AVANT de préparer une opération d\'écriture, pour présenter '
            . 'clairement à l\'utilisateur ce qu\'il DOIT et ce qu\'il PEUT fournir, et ce que tu complètes '
            . 'toi-même. N\'écrit rien.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entite' => [
                    'type' => 'string',
                    'enum' => MutationAllowlist::membres(),
                    'description' => 'Nom court de l\'entité métier.',
                ],
                'mode' => [
                    'type' => 'string',
                    'enum' => ['creation', 'edition'],
                    'description' => 'creation = champs à fournir ; edition = champs modifiables + valeur actuelle.',
                ],
                'id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Identifiant de l\'enregistrement à éditer (requis en mode edition).',
                ],
            ],
            'required' => ['entite', 'mode'],
        ];
    }

    /** Réservé au LLM réel (aucun routage par mots-clés). */
    public function match(string $question, AiScope $scope): ?array
    {
        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $shortName = (string) ($args['entite'] ?? '');
        $labels = $this->accessResolver->libellesEntites();
        $libelle = $labels[$shortName] ?? $shortName;

        // FAIL-CLOSED : périmètre métier + droit correspondant à l'opération visée.
        if (!MutationAllowlist::autorise($shortName)) {
            return AiToolResult::introuvable($shortName);
        }
        $mode = (string) ($args['mode'] ?? 'creation');
        if (!in_array($mode, ['creation', 'edition'], true)) {
            $mode = 'creation';
        }
        $level = $mode === 'edition' ? Invite::ACCESS_MODIFICATION : Invite::ACCESS_ECRITURE;
        if (!$this->accessResolver->can($scope->invite, $shortName, $level)) {
            return AiToolResult::horsPerimetre($libelle);
        }

        // Édition : résolution de la cible STRICTEMENT dans l'entreprise du scope.
        $cible = null;
        if ($mode === 'edition') {
            $id = (int) ($args['id'] ?? 0);
            $fqcn = 'App\\Entity\\' . $shortName;
            if ($id <= 0 || !class_exists($fqcn)) {
                return AiToolResult::introuvable($libelle);
            }
            $result = $this->searchService->search($fqcn, ['id' => $id], $scope->entreprise, null, 1, 1);
            $cible = $result['data'][0] ?? null;
            if (($result['status']['code'] ?? 500) !== 200 || $cible === null) {
                return AiToolResult::introuvable(sprintf('%s #%d', $libelle, $id));
            }
        }

        $inventaire = $this->mutationService->inventaireChamps($shortName, $scope, $cible);

        return AiToolResult::ok($inventaire + [
            'note' => 'Présente ces trois groupes clairement (tableau : champ · nature · valeur). Demande les '
                . 'OBLIGATOIRES, propose les FACULTATIFS, et indique que tu renseignes toi-même les champs AUTO '
                . '(ne les demande pas). Puis appelle preparer_operations.',
        ]);
    }
}
