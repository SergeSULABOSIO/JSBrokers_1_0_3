<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\FicheNormaliseur;
use App\Ai\Scope\AiScope;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;

/**
 * Lit la FICHE COMPLÈTE d'un enregistrement (attributs stockés) : là où
 * rechercher_entites ne rend que l'identifiant et le libellé, cet outil répond
 * à « quelle est l'adresse du client X ? », « quel est le statut de l'avenant
 * Y ? »… Sérialisation `list:read` (le même contrat que les listes et le
 * contexte de dialogue), élaguée des valeurs vides pour maîtriser les tokens.
 * Les valeurs CALCULÉES (prime totale…) restent du ressort d'indicateur_calcule.
 *
 * Résolution par id ou par nom ; un nom ambigu renvoie les candidats (id +
 * libellé) pour que le modèle demande précision. FAIL-CLOSED : canRead par
 * entité, scoping entreprise via JSBDynamicSearchService.
 */
final class LireFicheTool implements AiToolInterface
{
    /** Nombre maximal de candidats restitués sur un nom ambigu. */
    private const MAX_CANDIDATS = 6;

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly JSBDynamicSearchService $searchService,
        private readonly EntiteLexique $lexique,
        private readonly EntiteLibelle $libelleur,
        private readonly FicheNormaliseur $ficheNormaliseur,
    ) {
    }

    public function name(): string
    {
        return 'lire_fiche';
    }

    public function description(): string
    {
        return "Lit la fiche complète (tous les attributs enregistrés) d'un enregistrement précis : "
            . 'adresse, contacts, statut, dates, références… Cible par id (fourni par '
            . 'rechercher_entites) ou par nom. À appeler quand l’utilisateur demande le détail ou '
            . 'un attribut d’une fiche précise. Pour un chiffre CALCULÉ (prime, commission…), '
            . 'utiliser indicateur_calcule.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entite' => [
                    'type' => 'string',
                    'description' => "Nom court de l'entité (ex. Client, Avenant, Assureur).",
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

    /**
     * Chemin simulé : « détails / fiche / adresse… du <entité> <nom> ». Le nom
     * est capturé après le mot-clé d'entité (le LLM réel, lui, passe id ou nom
     * en argument structuré).
     */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);
        if (!preg_match('/\b(details?|fiche|coordonnees?|adresse|telephones?|e?mails?|informations?)\b/', $normalized)) {
            return null;
        }
        // Le paiement d'une PRIME a son outil dédié : sans cette garde, « les informations
        // du paiement de prime… » lisait une fiche de la rubrique Paiements (trésorerie).
        if (PaiementPrimeIntent::concerne($normalized)) {
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

        // FAIL-CLOSED : la fiche complète est une lecture comme une autre.
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
        if (($result['status']['code'] ?? 500) !== 200) {
            return AiToolResult::introuvable($labels[$shortName]);
        }

        $entities = $result['data'];
        if ($entities === []) {
            return AiToolResult::introuvable(sprintf('%s « %s »', $labels[$shortName], $nom !== '' ? $nom : '#' . $id));
        }

        // Nom ambigu : rendre les candidats, le modèle demandera précision.
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

        return AiToolResult::ok([
            'entite'  => $shortName,
            'libelle' => $labels[$shortName],
            'id'      => $entity->getId(),
            'nom'     => $this->libelleur->libelle($entity, $displayField),
            'fiche'   => $this->ficheNormaliseur->fiche($entity),
        ]);
    }
}
