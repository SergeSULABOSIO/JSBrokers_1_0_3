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
            . 'données — ou le TABLEAU DE BORD (entite=TableauDeBord) : l\'utilisateur voit la '
            . 'vue à l\'écran, ajoutée aux onglets et activée. À appeler quand l\'utilisateur '
            . 'demande d\'ouvrir/afficher une rubrique, une section ou le tableau de bord '
            . '(« ouvre les bordereaux », « ouvre le tableau de bord »).';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entite' => [
                    'type' => 'string',
                    'description' => "Nom court de l'entité de la rubrique à ouvrir, ou TableauDeBord.",
                    'enum' => array_merge(['TableauDeBord'], $this->lexique->nomsCourts()),
                ],
            ],
            'required' => ['entite'],
        ];
    }

    /**
     * Chemin simulé : « tableau de bord / dashboard » (vue spéciale, sans mot
     * « rubrique » requis), sinon « rubrique/section/module » + entité du lexique.
     */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        if (preg_match('/\b(tableau de bord|dashboard)\b/', $normalized)
            && preg_match('/\b(ouvre[sz]?|ouvrir|affiche[rsz]?|montre[rsz]?|va|aller)\b/', $normalized)) {
            return ['entite' => 'TableauDeBord'];
        }

        if (!preg_match('/\b(rubrique|section|module)\b/', $normalized)) {
            return null;
        }

        $shortName = $this->lexique->matchEntite($normalized);

        return $shortName === null ? null : ['entite' => $shortName];
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $shortName = (string) ($args['entite'] ?? '');

        // TABLEAU DE BORD : vue spéciale hors carte de rubriques, accessible à
        // tous les invités (son contenu est de toute façon filtré au périmètre).
        if ($shortName === 'TableauDeBord') {
            return AiToolResult::ok(
                [
                    'entite'  => 'TableauDeBord',
                    'libelle' => 'Tableau de bord',
                    'note'    => 'Le tableau de bord s\'ouvre dans un onglet de l\'espace de travail et devient actif.',
                ],
                uiAction: ['type' => 'open-rubrique', 'entite' => 'TableauDeBord'],
            );
        }

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
