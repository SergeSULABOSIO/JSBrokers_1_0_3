<?php

namespace App\Ai\Tool;

use App\Ai\Mutation\MutationAllowlist;
use App\Ai\Mutation\MutationOperation;
use App\Ai\Mutation\MutationPlan;
use App\Ai\Mutation\MutationReferences;
use App\Ai\Mutation\PlanEnAttente;
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
        private readonly PlanEnAttente $planEnAttente,
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
                            'ref' => [
                                'type' => 'string',
                                'description' => 'Étiquette libre posée sur une CRÉATION pour qu\'une opération '
                                    . 'SUIVANTE du même plan y renvoie alors que son identifiant n\'existe pas '
                                    . 'encore : dans l\'opération suivante, donne au champ de relation la valeur '
                                    . '"@etiquette". Ex. créer un client (ref:"client") puis sa piste '
                                    . '(champs:{"client":"@client"}) — le tout validé EN UNE SEULE FOIS.',
                            ],
                            'etape' => [
                                'type' => 'string',
                                'description' => 'Libellé de l\'ÉTAPE de parcours à laquelle cette opération '
                                    . 'appartient (reprends EXACTEMENT le libellé donné par parcours_saisie, ex. '
                                    . '"La composition de la prime"). C\'est ce qui permet à l\'utilisateur de '
                                    . 'décocher une étape avant d\'exécuter, et au budget d\'être ventilé par étape.',
                            ],
                            'champs' => [
                                'type' => 'object',
                                'description' => 'Champ => valeur, STRICTEMENT telles que dictées par '
                                    . 'l\'utilisateur (create/edit). Les relations se donnent par id (ex. '
                                    . '{"portefeuille": 42}) ; une relation MULTIPLE se donne par liste d\'id '
                                    . '(ex. {"partenaires": [7, 12]}) ; une relation vers une création du même '
                                    . 'plan se donne par son étiquette (ex. {"client": "@client"}). Jamais l\'id '
                                    . 'ni les champs d\'audit.',
                                'additionalProperties' => ['type' => ['string', 'number', 'boolean', 'array']],
                            ],
                            // Structure en ARRAY (et non map dynamique) : le nom de la
                            // collection est une VALEUR, pas une clé — indispensable pour
                            // les modèles dont le dialecte de schéma ignore/élague
                            // additionalProperties (ex. Gemini). Marche aussi pour Anthropic.
                            'collections' => [
                                'type' => 'array',
                                'description' => 'Sous-opérations sur les collections ÉDITABLES du parent, telles '
                                    . 'qu\'exposées par son formulaire (parité avec l\'écran). Une entrée par collection. '
                                    . 'Ex. pour éditer la composition de la prime d\'une Cotation : '
                                    . '[{"collection":"chargements","elements":[{"op":"create","champs":{"nom":"Prime nette",'
                                    . '"montantFlatExceptionel":9000,"type":<id Chargement>}}, …]}]. Chaque chargement porte '
                                    . '"nom", "montantFlatExceptionel" (le montant) et "type" (= id d\'un Chargement, à '
                                    . 'résoudre par son nom au préalable). NE mets JAMAIS ces composantes dans "champs" de '
                                    . 'la Cotation : elles y seraient ignorées.',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'collection' => ['type' => 'string', 'description' => 'Nom de la collection (ex. "chargements").'],
                                        'elements' => [
                                            'type' => 'array',
                                            'description' => 'Éléments à créer/modifier/supprimer dans la collection.',
                                            'items' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'op'     => ['type' => 'string', 'enum' => MutationOperation::OPS],
                                                    'id'     => ['type' => 'integer', 'description' => 'Id de l\'élément (edit/delete).'],
                                                    'champs' => ['type' => 'object', 'description' => 'Champs de l\'élément (scalaires ; relations par id).'],
                                                    'etape'  => ['type' => 'string', 'description' => 'Libellé de l\'étape de parcours de cet élément (décochable par l\'utilisateur).'],
                                                ],
                                                'required' => ['op'],
                                            ],
                                        ],
                                    ],
                                    'required' => ['collection', 'elements'],
                                ],
                            ],
                        ],
                        'required' => ['op', 'entite'],
                    ],
                ],
                'remplacerPlanEnAttente' => [
                    'type' => 'boolean',
                    'description' => 'Ne mets true QUE si un plan attend déjà une décision ET que l\'utilisateur '
                        . 'demande explicitement de le CHANGER (« non, plutôt… », « corrige le montant »). Le plan '
                        . 'en attente est alors ANNULÉ et remplacé par celui-ci — il n\'y a jamais deux plans à '
                        . 'valider. Sinon laisse false : tant qu\'un plan attend, la préparation est REFUSÉE et tu '
                        . 'dois renvoyer l\'utilisateur vers la barre « Valider et exécuter / Annuler » déjà affichée.',
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

        // VERROU (état de la conversation) : un plan attend déjà la décision de
        // l'utilisateur. En préparer un second lui imposerait d'empiler les
        // validations — exactement ce qu'on veut lui épargner. Deux issues, et
        // deux seulement : il tranche sur la barre déjà affichée, ou il demande
        // à CHANGER de plan (remplacerPlanEnAttente), auquel cas l'ancien est
        // annulé ici même. Il n'y a donc jamais deux plans en attente.
        if ($refus = $this->verrouPlanEnAttente($args, $scope)) {
            return $refus;
        }

        $plan = MutationPlan::fromArray($operations);
        if ($plan->estVide()) {
            return AiToolResult::introuvable('opérations');
        }

        $lignes = [];
        $manquants = [];
        $blocages = [];
        $facturables = [];
        $avertissements = [];
        // Registre PARTAGÉ par toutes les opérations du plan : c'est lui qui rend
        // validable en une seule fois un plan couvrant plusieurs entités dépendantes
        // (« @etiquette » vers une création précédente).
        $refs = MutationReferences::dryRun();
        $n = 0;
        foreach ($plan->operations as $op) {
            $n++;
            $analyse = $this->mutationService->analyserOperation($op, $scope, $refs);

            // Fail-closed : toute opération hors périmètre fait échouer la préparation entière.
            if ($analyse['statut'] === 'hors_perimetre') {
                return AiToolResult::horsPerimetre($analyse['libelle']);
            }
            if ($analyse['statut'] === 'introuvable') {
                return AiToolResult::introuvable(sprintf('%s %s', $analyse['libelle'], $op->targetId ? '#' . $op->targetId : ''));
            }

            // Budget : chaque nœud écrit RÉELLEMENT (tête + enfants de collection,
            // TOUTES imbrications), étiqueté par son étape. Source UNIQUE du
            // chiffrage, partagée à l'identique avec le pré-vol de l'exécution.
            foreach ($this->mutationService->facturablesDetailles($op, $scope) as $facturable) {
                $facturables[] = $facturable;
            }

            $lignes[] = [
                'n'            => $n,
                'op'           => $op->op,
                'entite'       => $op->entityShortName,
                'libelle'      => $analyse['libelle'],
                'cible'        => $analyse['cible'],
                'etape'        => $op->etape,
                'ref'          => $op->ref,
                'champs'       => array_keys($op->fields),
                'collections'  => $this->resumerCollections($op),
                'impacts'      => $analyse['impacts'],
                'portefeuille' => $analyse['portefeuille'] ?? null,
            ];

            foreach ($this->collectionsNonCouvertes($op) as $suggestion) {
                $avertissements[] = $suggestion;
            }

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
        // a été agrégé sur tout l'arbre pendant l'analyse, étape par étape — le
        // budget couvre donc D'UN SEUL TENANT tout ce que l'utilisateur validera.
        $cout = $this->tokenAccountService->estimateWriteCost(array_column($facturables, 'fqcn'));
        $solde = $this->tokenAccountService->availableFor($scope->entreprise);
        $suffisant = $solde >= $cout;
        $requiresPassword = $plan->contientSuppression();

        $budget = [
            'coutEstime'      => $cout,
            'soldeDisponible' => $solde,
            'resteApres'      => max(0, $solde - $cout),
            'suffisant'       => $suffisant,
            'enregistrements' => count($facturables),
            'parEtape'        => $this->budgetParEtape($facturables, $plan),
        ];
        $etapes = $plan->etapes();

        return AiToolResult::ok(
            [
                'pret'             => true,
                'plan'             => $lignes,
                'etapes'           => $etapes,
                'budget'           => $budget,
                'requiresPassword' => $requiresPassword,
                'avertissements'   => $avertissements,
                'note'             => $suffisant
                    ? ('Présente le plan EN UNE FOIS : tableau des opérations (avec la colonne Étape), liste des '
                        . 'impacts, et tableau du budget ventilé par étape (« parEtape ») avec son total. Précise '
                        . 'que l\'utilisateur peut encore DÉCOCHER une étape facultative avant d\'exécuter — le '
                        . 'budget se réajuste — et qu\'une seule validation suffit pour l\'ensemble'
                        . ($requiresPassword ? ' (une suppression exige son mot de passe).' : '.')
                        . ($avertissements !== []
                            ? ' Signale AUSSI, en une phrase et UNE SEULE FOIS, les éléments listés dans '
                                . '« avertissements » : demande à l\'utilisateur s\'il dispose de ces informations '
                                . 'MAINTENANT, pour tout regrouper dans CE plan. S\'il ne les a pas, laisse le plan tel quel.'
                            : ''))
                    : ('Solde de tokens INSUFFISANT pour cette mission : présente le plan et le budget, puis propose '
                        . 'd\'acheter des tokens ou d\'abandonner. N\'exécute pas.'),
            ],
            uiAction: [
                'type'             => PlanEnAttente::ACTION_REVUE,
                'plan'             => $plan->toArray(),
                // `budget.parEtape` porte les étapes décochables (libellé, clé,
                // caractère obligatoire, coût) : source unique du sélecteur d'étendue.
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
    /**
     * Le verrou anti-empilement. Renvoie le refus structuré si un plan attend une
     * décision et que l'utilisateur n'a pas demandé à le remplacer ; null si la
     * voie est libre (aucun plan en attente, ou remplacement demandé — l'ancien
     * plan est alors annulé, comme s'il avait cliqué « Annuler »).
     */
    private function verrouPlanEnAttente(array $args, AiScope $scope): ?AiToolResult
    {
        $message = $this->planEnAttente->messageEnAttente($scope->conversation);
        if ($message === null) {
            return null;
        }

        $resume = $this->planEnAttente->resume(($message->getMeta() ?? [])['mutationPlan'] ?? []);

        if (($args['remplacerPlanEnAttente'] ?? false) === true) {
            $this->planEnAttente->annulerLePlanEnAttente($scope->conversation);

            return null;
        }

        return AiToolResult::ok([
            'pret'            => false,
            'planEnAttente'   => true,
            'resumePlanEnAttente' => $resume,
            'note'            => sprintf(
                'REFUSÉ : un plan que tu as déjà présenté (%s) attend la décision de l’utilisateur — sa barre '
                . '« Valider et exécuter / Annuler » est encore affichée dans le fil. Ne prépare PAS un second '
                . 'plan : ce serait lui imposer deux validations. Explique-lui en une phrase qu’un plan est en '
                . 'attente et invite-le soit à le VALIDER, soit à l’ANNULER. S’il te dit qu’il veut CHANGER ce '
                . 'plan (autre montant, autre étendue, autre étape), rappelle-moi avec '
                . 'remplacerPlanEnAttente=true : j’annulerai l’ancien et présenterai le nouveau. '
                . 'N’affiche AUCUN tableau de plan tant que celui-ci n’est pas tranché.',
                $resume,
            ),
        ]);
    }

    /**
     * Ventilation du budget PAR ÉTAPE : ce que coûte chacune des étapes que
     * l'utilisateur a acceptées. Aucun barème n'est recalculé ici — c'est le même
     * estimateWriteCost, appliqué à des sous-ensembles du MÊME jeu de facturables.
     *
     * @param array<int, array{fqcn: string, entite: string, etape: ?string}> $facturables
     *
     * @return array<int, array{cle: string, libelle: string, obligatoire: bool, enregistrements: int, cout: int}>
     */
    private function budgetParEtape(array $facturables, MutationPlan $plan): array
    {
        $obligatoires = [];
        foreach ($plan->etapes() as $etape) {
            $obligatoires[$etape['cle']] = $etape['obligatoire'];
        }

        $groupes = [];
        foreach ($facturables as $facturable) {
            $libelle = $facturable['etape'] ?? 'Sans étape';
            $cle = MutationPlan::cleEtape($libelle);
            $groupes[$cle] ??= ['cle' => $cle, 'libelle' => $libelle, 'fqcns' => []];
            $groupes[$cle]['fqcns'][] = $facturable['fqcn'];
        }

        $detail = [];
        foreach ($groupes as $cle => $groupe) {
            $detail[] = [
                'cle'             => $cle,
                'libelle'         => $groupe['libelle'],
                'obligatoire'     => $obligatoires[$cle] ?? true,
                'enregistrements' => count($groupe['fqcns']),
                'cout'            => $this->tokenAccountService->estimateWriteCost($groupe['fqcns']),
            ];
        }

        return $detail;
    }

    /**
     * Collections éditables du formulaire d'une CRÉATION qui ne sont pas couvertes
     * par le plan — c'est-à-dire tout ce que l'utilisateur pourrait renseigner
     * MAINTENANT, dans ce même plan, plutôt que dans une seconde validation.
     * Purement informatif : ne bloque jamais, ne modifie pas le plan.
     *
     * @return string[]
     */
    private function collectionsNonCouvertes(MutationOperation $op): array
    {
        if (!$op->isCreate()) {
            return [];
        }

        $manquantes = [];
        foreach ($this->mutationService->collectionsProposables($op->entityShortName) as $nom => $libelle) {
            if (!isset($op->collections[$nom])) {
                $manquantes[] = $libelle;
            }
        }
        if ($manquantes === []) {
            return [];
        }

        return [sprintf(
            'L’opération « %s » ne couvre pas : %s. Demande à l’utilisateur, DANS LE MÊME MESSAGE que le plan, '
            . 's’il dispose de ces informations maintenant — pour tout regrouper ici plutôt que d’avoir à '
            . 'valider un second plan plus tard.',
            $op->entityShortName,
            implode(', ', $manquantes),
        )];
    }

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
