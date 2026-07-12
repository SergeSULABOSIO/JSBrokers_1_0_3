<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;

/**
 * Outil d'ACTION UI : ouvre un enregistrement dans la COLONNE DE VISUALISATION
 * du workspace (l'onglet de consultation standard, avec ses indicateurs) —
 * « visualise le client X ». Émet une directive d'intention que le chat
 * traduit via l'endpoint visual-context (re-validation fail-closed) puis
 * l'événement `app:liste-element:openned` (même circuit que l'ouverture depuis
 * une liste). Lecture seule : pour ÉDITER, c'est ouvrir_dialogue.
 */
final class VisualiserFicheTool implements AiToolInterface
{
    /** Nombre maximal de candidats restitués sur un nom ambigu. */
    private const MAX_CANDIDATS = 6;

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly JSBDynamicSearchService $searchService,
        private readonly EntiteLexique $lexique,
        private readonly EntiteLibelle $libelleur,
    ) {
    }

    public function name(): string
    {
        return 'visualiser_fiche';
    }

    public function description(): string
    {
        return "Ouvre un enregistrement précis dans la colonne de visualisation de l'espace de "
            . 'travail (consultation riche : fiche + indicateurs). Cible par id (fourni par '
            . 'rechercher_entites) ou par nom. À appeler quand l\'utilisateur veut VOIR une fiche '
            . 'à l\'écran — pour la modifier, utiliser ouvrir_dialogue ; pour répondre en texte, '
            . 'lire_fiche.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entite' => [
                    'type' => 'string',
                    'description' => "Nom court de l'entité (ex. Client, Avenant).",
                    'enum' => $this->lexique->nomsCourts(),
                ],
                'id' => [
                    'type' => 'integer',
                    'description' => "Identifiant de l'enregistrement (prioritaire sur nom).",
                ],
                'nom' => [
                    'type' => 'string',
                    'description' => "Nom (ou partie du nom) de l'enregistrement, si l'id est inconnu.",
                ],
            ],
            'required' => ['entite'],
        ];
    }

    /** Chemin simulé : « visualise le <entité> <nom> ». */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);
        if (!preg_match('/\bvisualise[rsz]?\b/', $normalized)) {
            return null;
        }

        $shortName = $this->lexique->matchEntite($normalized);
        if ($shortName === null) {
            return null;
        }

        foreach ($this->lexique->lexique()[$shortName] as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\s+(?:de |du |de la |d )?(.{2,60}?)(?:\s*\?|$)/', $normalized, $m)) {
                return ['entite' => $shortName, 'nom' => trim($m[1])];
            }
        }

        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $shortName = (string) ($args['entite'] ?? '');
        $labels = $this->accessResolver->libellesEntites();
        $fqcn = 'App\\Entity\\' . $shortName;
        if (!isset($labels[$shortName]) || !class_exists($fqcn)) {
            return AiToolResult::introuvable($shortName);
        }

        // FAIL-CLOSED : visualiser = lire.
        if (!$this->accessResolver->canRead($scope->invite, $shortName)) {
            return AiToolResult::horsPerimetre($labels[$shortName]);
        }

        $id = (int) ($args['id'] ?? 0);
        $nom = trim((string) ($args['nom'] ?? ''));
        $displayField = $this->libelleur->displayField($fqcn);

        if ($id > 0) {
            $criteria = ['id' => $id];
        } elseif ($nom !== '' && $displayField !== null) {
            $criteria = [$displayField => ['operator' => 'LIKE', 'value' => $nom, 'mode' => 'contains']];
        } else {
            return AiToolResult::introuvable($labels[$shortName]);
        }

        $result = $this->searchService->search($fqcn, $criteria, $scope->entreprise, null, 1, self::MAX_CANDIDATS);
        $entities = ($result['status']['code'] ?? 500) === 200 ? $result['data'] : [];
        if ($entities === []) {
            return AiToolResult::introuvable(sprintf('%s « %s »', $labels[$shortName], $nom !== '' ? $nom : '#' . $id));
        }
        if (count($entities) > 1) {
            return AiToolResult::ok([
                'entite'    => $shortName,
                'libelle'   => $labels[$shortName],
                'ambigu'    => true,
                'candidats' => array_map(
                    fn (object $e) => ['id' => $e->getId(), 'libelle' => $this->libelleur->libelle($e, $displayField)],
                    $entities,
                ),
            ]);
        }

        $entity = $entities[0];

        return AiToolResult::ok(
            [
                'entite'  => $shortName,
                'libelle' => $labels[$shortName],
                'cible'   => $this->libelleur->libelle($entity, $displayField),
                'note'    => 'La fiche s\'ouvre dans la colonne de visualisation de l\'utilisateur.',
            ],
            uiAction: ['type' => 'open-visualization', 'entite' => $shortName, 'id' => $entity->getId()],
        );
    }
}
