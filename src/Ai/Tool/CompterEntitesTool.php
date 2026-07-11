<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;

/**
 * Compte les enregistrements d'une rubrique du workspace (clients, avenants,
 * pistes…) pour l'entreprise active. Lexique dérivé des libellés de la carte
 * de permissions (EntiteLexique, DRY) ; comptage délégué à
 * JSBDynamicSearchService, dont le scoping entreprise est systématique.
 */
final class CompterEntitesTool implements AiToolInterface
{
    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly JSBDynamicSearchService $searchService,
        private readonly EntiteLexique $lexique,
    ) {
    }

    public function name(): string
    {
        return 'compter_entites';
    }

    public function description(): string
    {
        return "Compte le nombre d'enregistrements d'une catégorie de données de l'entreprise "
            . '(clients, avenants, pistes, notes, sinistres…). À appeler quand l’utilisateur '
            . 'demande « combien de … » ou « le nombre de … ».';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entite' => [
                    'type' => 'string',
                    'description' => "Nom court de l'entité à compter (ex. Client, Avenant, Piste).",
                    'enum' => $this->lexique->nomsCourts(),
                ],
            ],
            'required' => ['entite'],
        ];
    }

    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);
        if (!preg_match('/\b(combien|nombre|compte[sz]?)\b/', $normalized)) {
            return null;
        }

        $shortName = $this->lexique->matchEntite($normalized);

        return $shortName === null ? null : ['entite' => $shortName];
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $shortName = (string) ($args['entite'] ?? '');
        $labels = $this->accessResolver->libellesEntites();
        if (!isset($labels[$shortName])) {
            return AiToolResult::introuvable($shortName);
        }

        // FAIL-CLOSED : sans droit de lecture explicite, les données n'existent
        // pas pour l'assistant.
        if (!$this->accessResolver->canRead($scope->invite, $shortName)) {
            return AiToolResult::horsPerimetre($labels[$shortName]);
        }

        $fqcn = 'App\\Entity\\' . $shortName;
        if (!class_exists($fqcn)) {
            return AiToolResult::introuvable($shortName);
        }

        $result = $this->searchService->search($fqcn, [], $scope->entreprise, null, 1, 1);
        if (($result['status']['code'] ?? 500) !== 200) {
            return AiToolResult::introuvable($labels[$shortName]);
        }

        return AiToolResult::ok([
            'entite'  => $shortName,
            'libelle' => $labels[$shortName],
            'count'   => (int) $result['totalItems'],
        ]);
    }
}
