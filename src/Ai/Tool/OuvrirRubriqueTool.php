<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Service\Workspace\WorkspaceAccessResolver;

/**
 * Outil d'ACTION UI : ouvre la RUBRIQUE (liste) d'une entité dans le menu du
 * workspace — « ouvre la rubrique bordereaux », « va dans les clients ». Émet
 * une directive d'intention (uiAction) que le chat traduit en navigation via
 * le bus (`app:workspace.open-rubrique`, geste identique au clic sur le menu).
 * FAIL-CLOSED : lecture requise sur l'entité — le menu lui-même est filtré au
 * périmètre, l'assistant respecte le même contrat.
 */
final class OuvrirRubriqueTool implements AiToolInterface
{
    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly EntiteLexique $lexique,
    ) {
    }

    public function name(): string
    {
        return 'ouvrir_rubrique';
    }

    public function description(): string
    {
        return "Ouvre dans l'espace de travail la RUBRIQUE (liste complète) d'une catégorie de "
            . 'données : l\'utilisateur voit la liste à l\'écran avec ses filtres et outils. À '
            . 'appeler quand l\'utilisateur demande d\'ouvrir/afficher une rubrique ou d\'aller '
            . 'dans une section (« ouvre les bordereaux », « montre-moi la rubrique clients »).';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entite' => [
                    'type' => 'string',
                    'description' => "Nom court de l'entité de la rubrique à ouvrir.",
                    'enum' => $this->lexique->nomsCourts(),
                ],
            ],
            'required' => ['entite'],
        ];
    }

    /** Chemin simulé : le mot « rubrique/section/module » + une entité du lexique. */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);
        if (!preg_match('/\b(rubrique|section|module)\b/', $normalized)) {
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

        // FAIL-CLOSED : le menu est filtré au périmètre — même contrat ici.
        if (!$this->accessResolver->canRead($scope->invite, $shortName)) {
            return AiToolResult::horsPerimetre($labels[$shortName]);
        }

        return AiToolResult::ok(
            [
                'entite'  => $shortName,
                'libelle' => $labels[$shortName],
                'note'    => 'La rubrique s\'ouvre dans l\'espace de travail de l\'utilisateur.',
            ],
            uiAction: ['type' => 'open-rubrique', 'entite' => $shortName],
        );
    }
}
