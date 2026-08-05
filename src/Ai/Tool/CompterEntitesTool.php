<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\AvenantEcheanceScope;
use App\Services\Search\CotationSouscriptionScope;
use App\Services\Search\PisteTransformationScope;
use App\Services\Search\PortefeuilleCritereFactory;
use App\Services\Search\PortefeuilleScope;
use App\Services\Search\TranchePaiementScope;

/**
 * Compte les enregistrements d'une rubrique du workspace (clients, avenants,
 * pistes…) pour l'entreprise active. Lexique dérivé des libellés de la carte
 * de permissions (EntiteLexique, DRY) ; comptage délégué à
 * JSBDynamicSearchService, dont le scoping entreprise est systématique.
 *
 * Le comptage est en outre restreint PAR DÉFAUT au portefeuille de l'invité —
 * exactement comme la rubrique correspondante à l'écran, qui pose le même
 * critère par défaut (cf. PortefeuilleCritereFactory, source unique).
 */
final class CompterEntitesTool implements AiToolInterface
{
    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly JSBDynamicSearchService $searchService,
        private readonly EntiteLexique $lexique,
        private readonly PortefeuilleCritereFactory $portefeuilleCritere,
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
            . 'demande « combien de … » ou « le nombre de … ». Les paramètres echeance (Avenant), '
            . 'statutPaiement (Tranche), validation (Cotation) et transformation (Piste) '
            . 'appliquent EXACTEMENT les mêmes règles que les filtres rapides de ces rubriques : à '
            . 'utiliser dès que la question porte sur une fenêtre d’échéance (« combien d’avenants '
            . 'échoient dans les 30 jours ? »), un statut de paiement (« combien de tranches '
            . 'impayées ? »), un statut de souscription (« combien de propositions en attente ? ») '
            . 'ou un statut de transformation (« combien de pistes en cours ? »), afin que la '
            . 'réponse coïncide avec ce que l’utilisateur voit à l’écran. Le comptage porte par '
            . 'défaut sur le PORTEFEUILLE de l’utilisateur, comme la rubrique affichée (paramètre perimetre).';
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
                'axes' => TranchePaiementScope::proprieteSchema(
                    'TRANCHE uniquement, mêmes règles que les groupes de chips de la rubrique.'
                ),
                'validation' => [
                    'type' => 'string',
                    'enum' => array_keys(CotationSouscriptionScope::VALEURS),
                    'description' => 'COTATION uniquement : restreint à un statut de souscription — '
                        . 'souscrites (transformées en police, au moins un avenant), en_attente '
                        . '(non transformées, encore en course), caduques (non transformées mais '
                        . 'une autre proposition de la même piste est souscrite = marché perdu, '
                        . 'sans suite). Mêmes règles que les filtres rapides de la rubrique.',
                ],
                'transformation' => [
                    'type' => 'string',
                    'enum' => array_keys(PisteTransformationScope::VALEURS),
                    'description' => 'PISTE uniquement : restreint à un statut de transformation — '
                        . 'transformees (au moins une cotation souscrite/transformée en police), '
                        . 'en_cours (aucune cotation encore transformée). Mêmes règles que les '
                        . 'filtres rapides de la rubrique.',
                ],
                'perimetre' => PortefeuilleScope::proprieteSchema(),
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
        // Le paiement d'une PRIME a son outil dédié : sans cette garde, « combien de
        // paiements de prime… » comptait la rubrique Paiements (trésorerie du courtier).
        if (PaiementPrimeIntent::concerne($normalized)) {
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
        } elseif ($shortName === 'Tranche' && ($s = TranchePaiementScope::versNomsCourts(TranchePaiementScope::detecterAxesDepuisTexte($normalized))) !== []) {
            $args['axes'] = $s;
        } elseif ($shortName === 'Cotation' && ($v = CotationSouscriptionScope::detecterDepuisTexte($normalized)) !== null) {
            $args['validation'] = $v;
        } elseif ($shortName === 'Piste' && ($t = PisteTransformationScope::detecterDepuisTexte($normalized)) !== null) {
            $args['transformation'] = $t;
        }

        // Le périmètre par défaut est celui de l'écran (portefeuille de l'invité) : seule une
        // demande explicite d'élargissement est détectée ici.
        if (($p = PortefeuilleScope::detecterPerimetreDepuisTexte($normalized)) !== null) {
            $args['perimetre'] = $p;
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
        // Scopé à Tranche : sans cette garde, des `axes` transmis par erreur sur une autre
        // entité annonceraient un filtre que le comptage n'a pas appliqué.
        $axesTranche = $shortName === 'Tranche'
            ? TranchePaiementScope::normaliserAxes(is_array($args['axes'] ?? null) ? $args['axes'] : [])
            : [];
        $criteres = AvenantEcheanceScope::critereRecherche($shortName, $args['echeance'] ?? null)
            + TranchePaiementScope::critereRecherche($shortName, $axesTranche)
            + CotationSouscriptionScope::critereRecherche($shortName, $args['validation'] ?? null)
            + PisteTransformationScope::critereRecherche($shortName, $args['transformation'] ?? null);

        // PÉRIMÈTRE : par défaut le portefeuille de l'invité, comme la rubrique à l'écran.
        // Le critère provient de la fabrique partagée avec le contrôleur de liste, donc il
        // traverse la même interception SQL et produit le même nombre — par construction.
        $perimetreEntreprise = PortefeuilleScope::estEntreprise($args['perimetre'] ?? null);
        $criterePortefeuille = $perimetreEntreprise
            ? []
            : $this->portefeuilleCritere->pour($shortName, $scope->invite);
        $criteres += $criterePortefeuille;

        $result = $this->searchService->search($fqcn, $criteres, $scope->entreprise, null, 1, 1);
        if (($result['status']['code'] ?? 500) !== 200) {
            return AiToolResult::introuvable($labels[$shortName]);
        }

        $filtreApplique = match (true) {
            isset($criteres[AvenantEcheanceScope::CRITERION_KEY]) => AvenantEcheanceScope::libelle(
                (string) $criteres[AvenantEcheanceScope::CRITERION_KEY]['value']
            ),
            // Combinaison lisible (« Prime impayée · Échues ») : le compte annoncé doit
            // porter son filtre, axe par axe, sinon le nombre seul redevient ambigu.
            $axesTranche !== [] => TranchePaiementScope::libelleCombinaison($axesTranche),
            isset($criteres[CotationSouscriptionScope::CRITERION_KEY]) => CotationSouscriptionScope::libelle(
                (string) $criteres[CotationSouscriptionScope::CRITERION_KEY]['value']
            ),
            isset($criteres[PisteTransformationScope::CRITERION_KEY]) => PisteTransformationScope::libelle(
                (string) $criteres[PisteTransformationScope::CRITERION_KEY]['value']
            ),
            default => null,
        };

        return AiToolResult::ok(array_filter([
            'entite'    => $shortName,
            'libelle'   => $labels[$shortName],
            'filtre'    => $filtreApplique,
            'perimetre' => PortefeuilleScope::libellePerimetre($perimetreEntreprise, $criterePortefeuille),
            'count'     => (int) $result['totalItems'],
        ], static fn ($v) => $v !== null));
    }
}
