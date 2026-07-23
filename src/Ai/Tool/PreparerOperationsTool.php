<?php

namespace App\Ai\Tool;

use App\Ai\Mutation\MutationAllowlist;
use App\Ai\Mutation\MutationOperation;
use App\Ai\Mutation\MutationPlan;
use App\Ai\Scope\AiScope;
use App\Service\Workspace\WorkspaceMutationService;
use App\Token\TokenAccountService;

/**
 * Outil d'ÉCRITURE/SUPPRESSION de l'assistant IA (Ket) — en DRY-RUN.
 *
 * À la différence de tous les autres outils, celui-ci prépare une MUTATION
 * réelle des données métier de l'utilisateur (create/edit/delete). Mais il
 * n'écrit RIEN : il valide l'intention (droits fail-closed, périmètre entreprise,
 * champs, impacts de cascade), estime le COÛT en tokens, et renvoie un PLAN
 * numéroté que l'utilisateur devra VALIDER (une suppression exige en plus une
 * confirmation renforcée par mot de passe). L'écriture effective est réalisée par
 * un exécuteur déterministe hors-LLM (endpoint execute), qui re-valide tout.
 *
 * FAIL-CLOSED : périmètre strictement limité aux entités MÉTIER de l'allowlist
 * (jamais les paramètres/rôles de l'espace). Toute opération hors périmètre fait
 * échouer la préparation.
 */
final class PreparerOperationsTool implements AiToolInterface
{
    public function __construct(
        private readonly WorkspaceMutationService $mutationService,
        private readonly TokenAccountService $tokenAccountService,
    ) {
    }

    public function name(): string
    {
        return 'preparer_operations';
    }

    public function description(): string
    {
        return "L'outil par lequel TU crées, modifies et supprimes toi-même les données de l'utilisateur : "
            . 'créer (op=create), modifier (op=edit, id requis) ou supprimer (op=delete, id requis) '
            . 'un enregistrement. Entités autorisées UNIQUEMENT : ' . implode(', ', MutationAllowlist::membres()) . '. '
            . "À utiliser quand l'utilisateur veut que TOI tu t'en charges (« fais-le / crée-moi / "
            . "enregistre toi-même »). S'il préfère remplir et enregistrer le formulaire LUI-MÊME, "
            . "utilise plutôt ouvrir_dialogue ; s'il n'a pas précisé, DEMANDE-LUI d'abord. N'APPELLE cet outil que "
            . "lorsque tu disposes de 100 % des informations (pose d'abord toutes les questions utiles). "
            . "L'outil N'ÉCRIT RIEN : il valide, chiffre le coût, et renvoie un PLAN numéroté + le BUDGET en "
            . 'tokens que tu présentes clairement (tableaux + listes). Après validation de l’utilisateur, '
            . "l'écriture est exécutée AUTOMATIQUEMENT — c'est TOI qui enregistres, sans formulaire à "
            . "soumettre à la main. En édition/suppression, obtiens l'id via rechercher_entites. Si l'outil "
            . 'renvoie « manquants », repose les questions ; s’il renvoie « bloque », explique le blocage '
            . 'sans exécuter. Une suppression demandera le mot de passe. Ne devine jamais une valeur.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'operations' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'description' => 'Opérations à préparer, dans l\'ordre métier souhaité.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'op' => [
                                'type' => 'string',
                                'enum' => MutationOperation::OPS,
                                'description' => 'create = créer, edit = modifier (id requis), delete = supprimer (id requis).',
                            ],
                            'entite' => [
                                'type' => 'string',
                                'enum' => MutationAllowlist::membres(),
                                'description' => 'Nom court de l\'entité métier concernée.',
                            ],
                            'id' => [
                                'type' => 'integer',
                                'minimum' => 1,
                                'description' => 'Identifiant de la cible (obligatoire pour edit et delete).',
                            ],
                            'champs' => [
                                'type' => 'object',
                                'description' => 'Champ scalaire => valeur, STRICTEMENT telles que dictées par '
                                    . 'l\'utilisateur (create/edit). Les relations se donnent par id (ex. '
                                    . '{"portefeuille": 42}). Jamais l\'id ni les champs d\'audit.',
                                'additionalProperties' => ['type' => ['string', 'number', 'boolean']],
                            ],
                            'collections' => [
                                'type' => 'object',
                                'description' => 'Sous-opérations sur les collections ÉDITABLES du parent, telles '
                                    . 'qu\'exposées par son formulaire (parité avec l\'écran), RÉCURSIF. Clé = nom '
                                    . 'de la collection (ex. "chargements" d\'une Cotation) ; valeur = liste d\'éléments '
                                    . 'à créer/modifier/supprimer. Chaque élément de "chargements" porte "nom", '
                                    . '"montantFlatExceptionel" et "type" (= id d\'un Chargement, à résoudre par son '
                                    . 'nom au préalable). N\'indique le nom court de l\'entité enfant nulle part : il '
                                    . 'est déduit du formulaire parent.',
                                'additionalProperties' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'op'     => ['type' => 'string', 'enum' => MutationOperation::OPS],
                                            'id'     => ['type' => 'integer', 'minimum' => 1, 'description' => 'Id de l\'élément (edit/delete).'],
                                            'champs' => ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'number', 'boolean']]],
                                        ],
                                        'required' => ['op'],
                                    ],
                                ],
                            ],
                        ],
                        'required' => ['op', 'entite'],
                    ],
                ],
            ],
            'required' => ['operations'],
        ];
    }

    /**
     * Chemin simulé : neutralisé. La préparation d'écritures multi-opérations
     * relève du LLM réel (le moteur simulé continue de router création/édition
     * simple vers ouvrir_dialogue). Aucune régression du simulateur.
     */
    public function match(string $question, AiScope $scope): ?array
    {
        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $operations = $args['operations'] ?? null;
        if (!is_array($operations) || $operations === []) {
            return AiToolResult::introuvable('opérations');
        }

        $plan = MutationPlan::fromArray($operations);
        if ($plan->estVide()) {
            return AiToolResult::introuvable('opérations');
        }

        $lignes = [];
        $manquants = [];
        $blocages = [];
        $facturables = [];
        $n = 0;
        foreach ($plan->operations as $op) {
            $n++;
            $analyse = $this->mutationService->analyserOperation($op, $scope);

            // Fail-closed : toute opération hors périmètre fait échouer la préparation entière.
            if ($analyse['statut'] === 'hors_perimetre') {
                return AiToolResult::horsPerimetre($analyse['libelle']);
            }
            if ($analyse['statut'] === 'introuvable') {
                return AiToolResult::introuvable(sprintf('%s %s', $analyse['libelle'], $op->targetId ? '#' . $op->targetId : ''));
            }

            // Budget : FQCN de chaque nœud écrit RÉELLEMENT (tête + enfants de
            // collection, TOUTES imbrications). Source UNIQUE du chiffrage,
            // partagée à l'identique avec le pré-vol de l'endpoint d'exécution.
            foreach ($this->mutationService->facturablesArbre($op, $scope) as $fqcn) {
                $facturables[] = $fqcn;
            }

            $lignes[] = [
                'n'            => $n,
                'op'           => $op->op,
                'entite'       => $op->entityShortName,
                'libelle'      => $analyse['libelle'],
                'cible'        => $analyse['cible'],
                'champs'       => array_keys($op->fields),
                'collections'  => $this->resumerCollections($op),
                'impacts'      => $analyse['impacts'],
                'portefeuille' => $analyse['portefeuille'] ?? null,
            ];

            if ($analyse['statut'] === 'invalide') {
                foreach ($analyse['manquants'] as $champ => $msgs) {
                    $manquants[] = sprintf('#%d %s — %s : %s', $n, $analyse['libelle'], $champ, implode(' ', $msgs));
                }
            }
            if ($analyse['statut'] === 'bloque') {
                foreach ($analyse['impacts'] as $impact) {
                    $blocages[] = sprintf('#%d %s : %s', $n, $analyse['libelle'], $impact);
                }
            }
        }

        // Informations incomplètes : Ket doit reposer des questions (aucun plan présenté).
        if ($manquants !== []) {
            return AiToolResult::ok([
                'pret'      => false,
                'manquants' => $manquants,
                'note'      => 'Informations incomplètes : demande à l\'utilisateur de fournir les champs manquants, '
                    . 'puis rappelle preparer_operations.',
            ]);
        }
        // Suppression bloquée par une contrainte : on ne présente pas de plan exécutable.
        if ($blocages !== []) {
            return AiToolResult::ok([
                'pret'     => false,
                'blocages' => $blocages,
                'note'     => 'Suppression impossible en l\'état : explique le blocage à l\'utilisateur (liens '
                    . 'obligatoires à détacher d\'abord). Ne présente pas de plan exécutable.',
            ]);
        }

        // Budget en tokens : chaque nœud écrit réellement (tête ET enfants de
        // collection) est facturé ; les suppressions sont gratuites. $facturables
        // a été agrégé sur tout l'arbre pendant l'analyse.
        $cout = $this->tokenAccountService->estimateWriteCost($facturables);
        $solde = $this->tokenAccountService->availableFor($scope->entreprise);
        $suffisant = $solde >= $cout;
        $requiresPassword = $plan->contientSuppression();

        $budget = [
            'coutEstime'      => $cout,
            'soldeDisponible' => $solde,
            'resteApres'      => max(0, $solde - $cout),
            'suffisant'       => $suffisant,
        ];

        return AiToolResult::ok(
            [
                'pret'             => true,
                'plan'             => $lignes,
                'budget'           => $budget,
                'requiresPassword' => $requiresPassword,
                'note'             => $suffisant
                    ? ('Présente le plan (tableau des opérations + liste des impacts + tableau du budget) et invite '
                        . 'l\'utilisateur à confirmer'
                        . ($requiresPassword ? ' (une suppression exige son mot de passe).' : '.'))
                    : ('Solde de tokens INSUFFISANT pour cette mission : présente le plan et le budget, puis propose '
                        . 'd\'acheter des tokens ou d\'abandonner. N\'exécute pas.'),
            ],
            uiAction: [
                'type'             => 'ket-mutation.review',
                'plan'             => $plan->toArray(),
                'budget'           => $budget,
                'requiresPassword' => $requiresPassword,
                // Impacts agrégés (cascades de suppression) : alimentent la liste
                // d'éléments de la confirmation renforcée côté front.
                'impacts'          => array_values(array_merge(...array_map(
                    static fn (array $l) => $l['impacts'] ?? [],
                    $lignes,
                ) ?: [[]])),
            ],
        );
    }

    /**
     * Résumé lisible des sous-opérations de collection d'une opération (récursif),
     * pour la présentation du plan : nom de collection => liste { op, champs, id,
     * collections }. Le nom court d'entité enfant n'est pas exposé (déduit serveur).
     *
     * @return array<string, array<int, array>>
     */
    private function resumerCollections(MutationOperation $op): array
    {
        $resume = [];
        foreach ($op->collections as $nom => $enfants) {
            foreach ($enfants as $enfant) {
                $item = ['op' => $enfant->op, 'champs' => array_keys($enfant->fields)];
                if ($enfant->targetId !== null) {
                    $item['id'] = $enfant->targetId;
                }
                $sous = $this->resumerCollections($enfant);
                if ($sous !== []) {
                    $item['collections'] = $sous;
                }
                $resume[$nom][] = $item;
            }
        }

        return $resume;
    }
}
