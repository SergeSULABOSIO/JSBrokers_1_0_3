<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;

/**
 * Liste (ou recherche par texte) les enregistrements d'une rubrique du
 * workspace pour l'entreprise active, avec pagination. Complément naturel de
 * CompterEntitesTool : là où celui-ci répond « combien », celui-ci répond
 * « lesquels ». Recherche déléguée à JSBDynamicSearchService (scoping
 * entreprise systématique) ; restitution volontairement compacte (id +
 * libellé) pour maîtriser les tokens — les détails d'un enregistrement
 * relèvent d'outils dédiés (ex. indicateur_calcule).
 */
final class RechercherEntitesTool implements AiToolInterface
{
    /** Taille de page fixe côté serveur : maîtrise des tokens restitués au modèle. */
    private const PAGE_SIZE = 20;

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly JSBDynamicSearchService $searchService,
        private readonly EntiteLexique $lexique,
        private readonly EntiteLibelle $libelleur,
    ) {
    }

    public function name(): string
    {
        return 'rechercher_entites';
    }

    public function description(): string
    {
        return "Liste ou recherche les enregistrements d'une catégorie de données de l'entreprise "
            . '(clients, avenants, pistes, notes, sinistres…), avec filtre texte optionnel et '
            . 'pagination (' . self::PAGE_SIZE . ' par page). À appeler quand l’utilisateur demande '
            . '« liste », « affiche », « montre-moi », « quels sont »… Renvoie l’identifiant et le '
            . 'libellé de chaque enregistrement.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entite' => [
                    'type' => 'string',
                    'description' => "Nom court de l'entité à lister (ex. Client, Avenant, Piste).",
                    'enum' => $this->lexique->nomsCourts(),
                ],
                'filtre' => [
                    'type' => 'string',
                    'description' => 'Texte recherché dans le libellé des enregistrements (optionnel).',
                ],
                'page' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Numéro de page à restituer (défaut : 1).',
                ],
            ],
            'required' => ['entite'],
        ];
    }

    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);
        if (!preg_match('/\b(liste[rsz]?|affiche[rsz]?|montre[rsz]?|enumere[rsz]?|quel(?:le)?s sont)\b/', $normalized)) {
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

        $filtre = trim((string) ($args['filtre'] ?? ''));
        $page = max(1, (int) ($args['page'] ?? 1));
        $displayField = $this->libelleur->displayField($fqcn);

        // Le filtre texte exige un champ de libellé persisté ; sans lui, on
        // liste sans filtrer et on le signale au modèle (filtreIgnore).
        $criteria = ($filtre !== '' && $displayField !== null)
            ? [$displayField => ['operator' => 'LIKE', 'value' => $filtre, 'mode' => 'contains']]
            : [];

        $result = $this->searchService->search($fqcn, $criteria, $scope->entreprise, null, $page, self::PAGE_SIZE);
        if (($result['status']['code'] ?? 500) !== 200) {
            return AiToolResult::introuvable($labels[$shortName]);
        }

        $items = [];
        foreach ($result['data'] as $entity) {
            $items[] = [
                'id'      => $entity->getId(),
                'libelle' => $this->libelleur->libelle($entity, $displayField),
            ];
        }

        return AiToolResult::ok(array_filter([
            'entite'       => $shortName,
            'libelle'      => $labels[$shortName],
            'filtre'       => $filtre !== '' ? $filtre : null,
            'filtreIgnore' => ($filtre !== '' && $displayField === null) ? true : null,
            'page'         => (int) $result['currentPage'],
            'totalPages'   => (int) $result['totalPages'],
            'totalItems'   => (int) $result['totalItems'],
            'items'        => $items,
        ], static fn ($v) => $v !== null));
    }
}
