<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;

/**
 * Compte les enregistrements d'une rubrique du workspace (clients, avenants,
 * pistes…) pour l'entreprise active. Lexique dérivé des libellés de la carte
 * de permissions (DRY) ; comptage délégué à JSBDynamicSearchService, dont le
 * scoping entreprise est systématique.
 */
final class CompterEntitesTool implements AiToolInterface
{
    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly JSBDynamicSearchService $searchService,
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
                    'enum' => array_keys($this->lexique()),
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

        foreach ($this->lexique() as $shortName => $keywords) {
            foreach ($keywords as $keyword) {
                if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $normalized)) {
                    return ['entite' => $shortName];
                }
            }
        }

        return null;
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

    /**
     * Lexique mots-clés => nom court, dérivé des libellés de rubrique
     * (« Clients » → clients/client) et du nom court lui-même. Les
     * pseudo-entités sans classe Doctrine (DocumentComptable) sont exclues.
     *
     * @return array<string, string[]>
     */
    private function lexique(): array
    {
        $lexique = [];
        foreach ($this->accessResolver->libellesEntites() as $shortName => $label) {
            if (!class_exists('App\\Entity\\' . $shortName)) {
                continue;
            }

            $keywords = [];
            foreach ([AiText::normalize($label), AiText::normalize($shortName)] as $candidate) {
                $keywords[] = $candidate;
                // Variante singulier/pluriel naïve, suffisante pour un lexique FR.
                $keywords[] = str_ends_with($candidate, 's') ? rtrim($candidate, 's') : $candidate . 's';
            }
            $lexique[$shortName] = array_values(array_unique($keywords));
        }

        return $lexique;
    }
}
