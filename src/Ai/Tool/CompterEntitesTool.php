<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\AvenantEcheanceScope;
use App\Services\Search\TranchePaiementScope;

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
            . 'demande « combien de … » ou « le nombre de … ». Les paramètres echeance (Avenant) '
            . 'et statutPaiement (Tranche) appliquent EXACTEMENT les mêmes règles que les filtres '
            . 'rapides de ces rubriques : à utiliser dès que la question porte sur une fenêtre '
            . 'd’échéance (« combien d’avenants échoient dans les 30 jours ? ») ou un statut de '
            . 'paiement (« combien de tranches impayées ? »), afin que la réponse coïncide avec '
            . 'ce que l’utilisateur voit à l’écran.';
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
                'echeance' => [
                    'type' => 'string',
                    'enum' => array_keys(AvenantEcheanceScope::VALEURS),
                    'description' => 'AVENANT uniquement : restreint à une fenêtre d\'échéance — '
                        . 'echus (déjà expirés), sous_30j (échéance dans les 30 prochains jours), '
                        . 'de_31_a_60j, au_dela_60j. Mêmes bornes que les filtres rapides de la rubrique.',
                ],
                'statutPaiement' => [
                    'type' => 'string',
                    'enum' => array_keys(TranchePaiementScope::VALEURS),
                    'description' => 'TRANCHE uniquement : restreint à un statut de paiement — '
                        . 'impayees, echues, a_echoir, partiellement, payees, retro_a_payer, '
                        . 'commission_exigible. Mêmes règles que les filtres rapides de la rubrique.',
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
        if ($shortName === null) {
            return null;
        }

        // Le comptage doit coïncider avec ce que l'utilisateur voit dans la rubrique : si la
        // question exprime une fenêtre d'échéance ou un statut de paiement, on applique le
        // MÊME critère que le chip correspondant (sources uniques : les classes de scope).
        $args = ['entite' => $shortName];
        if ($shortName === 'Avenant' && ($f = AvenantEcheanceScope::detecterDepuisTexte($normalized)) !== null) {
            $args['echeance'] = $f;
        } elseif ($shortName === 'Tranche' && ($s = TranchePaiementScope::detecterDepuisTexte($normalized)) !== null) {
            $args['statutPaiement'] = $s;
        }

        return $args;
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

        // Filtres rapides des rubriques (mêmes critères synthétiques que les chips, donc
        // même moteur et même résultat) : fenêtre d'échéance pour Avenant, statut de
        // paiement pour Tranche. Ignorés si l'entité ne s'y prête pas.
        $criteres = AvenantEcheanceScope::critereRecherche($shortName, $args['echeance'] ?? null)
            + TranchePaiementScope::critereRecherche($shortName, $args['statutPaiement'] ?? null);

        $result = $this->searchService->search($fqcn, $criteres, $scope->entreprise, null, 1, 1);
        if (($result['status']['code'] ?? 500) !== 200) {
            return AiToolResult::introuvable($labels[$shortName]);
        }

        $filtreApplique = $criteres === [] ? null : (
            isset($criteres[AvenantEcheanceScope::CRITERION_KEY])
                ? AvenantEcheanceScope::libelle((string) $criteres[AvenantEcheanceScope::CRITERION_KEY]['value'])
                : TranchePaiementScope::libelle((string) $criteres[TranchePaiementScope::CRITERION_KEY]['value'])
        );

        return AiToolResult::ok(array_filter([
            'entite'  => $shortName,
            'libelle' => $labels[$shortName],
            'filtre'  => $filtreApplique,
            'count'   => (int) $result['totalItems'],
        ], static fn ($v) => $v !== null));
    }
}
