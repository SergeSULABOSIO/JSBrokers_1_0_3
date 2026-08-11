<?php

namespace App\Ai\Tool;

use App\Ai\Trousse\AiToolEcriture;

use App\Ai\Mutation\MutationAllowlist;
use App\Ai\Mutation\MutationOperation;
use App\Ai\Mutation\MutationPlan;
use App\Ai\Mutation\PlanBuilder;
use App\Ai\Programme\EtapeAssemblable;
use App\Ai\Scope\AiScope;

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
final class PreparerOperationsTool implements AiToolProduisantUnPlan, AiToolEcriture, EtapeAssemblable
{
    public function __construct(
        private readonly PlanBuilder $planBuilder,
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

    public function aiguillage(): string
    {
        return 'Créer, modifier ou supprimer toi-même un enregistrement, quand l\'utilisateur veut que TU t\'en '
            . 'charges (« fais-le », « crée-moi », « enregistre »). Je n\'écris rien : je valide, je chiffre le '
            . 'coût et je rends un PLAN à valider.';
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
                                    . 'l\'utilisateur (create/edit). Une relation se donne par son NOM, tel que '
                                    . 'l\'utilisateur l\'a prononcé — le serveur le résout lui-même (ex. '
                                    . '{"client": "Mme Marlette", "assureur": "SUNU"}). NE VA DONC PAS chercher '
                                    . 'd\'identifiant avec rechercher_entites : c\'est inutile et coûteux. Si un '
                                    . 'nom est introuvable ou ambigu, l\'outil te renvoie « aDemander » avec les '
                                    . 'candidats, et tu poses UNE question groupée. Un id reste accepté quand tu '
                                    . 'en disposes déjà (ex. {"portefeuille": 42}) ; une relation MULTIPLE se '
                                    . 'donne par liste (ex. {"partenaires": ["Alpha Courtage", 12]}) ; une '
                                    . 'relation vers une création du même '
                                    . 'plan se donne par son étiquette (ex. {"client": "@client"}). Pour DÉPOSER '
                                    . 'une PIÈCE JOINTE de la conversation dans un champ fichier (ex. le champ '
                                    . '"fichier" d\'un Document), donne au champ la valeur "@fichier:<id>" où <id> '
                                    . 'est l\'identifiant du fichier attaché indiqué dans la section PIÈCES JOINTES '
                                    . '(ex. créer un Document d\'un avenant : {"nom":"Police signée","avenant":42,'
                                    . '"fichier":"@fichier:7"}). Jamais l\'id ni les champs d\'audit.',
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

    /**
     * UNE ÉTAPE DE PROGRAMME DEVIENT UNE OPÉRATION. C'est ce qui permet à une série
     * de porter des écritures QUELCONQUES — « créer le fournisseur », puis
     * « enregistrer la dépense » — là où seuls des outils spécialisés (paiement de
     * prime, marquage de police) pouvaient être enchaînés.
     *
     * Le modèle décrit l'étape à plat ; la structure est fabriquée ici, par le seul
     * code qui connaisse la forme attendue. Le `ref` posé sur une étape et le
     * « @ref » d'une étape suivante sont conservés TELS QUELS : c'est le programme
     * qui les résout, une fois l'étape précédente réellement écrite (à ce moment-là
     * l'identifiant existe).
     */
    public function argumentsDepuisEtape(array $etape): array
    {
        $entite = trim((string) ($etape['entite'] ?? ''));
        if ($entite === '' || !MutationAllowlist::autorise($entite)) {
            return [];
        }

        $op = trim((string) ($etape['operation'] ?? MutationOperation::OP_CREATE));
        if (!in_array($op, MutationOperation::OPS, true)) {
            return [];
        }

        $champs = [];
        foreach ((array) ($etape['champs'] ?? []) as $paire) {
            if (!is_array($paire)) {
                continue;
            }
            $cle = trim((string) ($paire['cle'] ?? ''));
            if ($cle === '' || !array_key_exists('valeur', $paire)) {
                continue;
            }
            $champs[$cle] = $paire['valeur'];
        }

        $cibleId = isset($etape['cibleId']) ? (int) $etape['cibleId'] : 0;
        // Une modification ou une suppression SANS cible n'a pas de sens : mieux vaut
        // rendre l'étape inexploitable (elle sera traversée avec son motif) que de la
        // transformer en création silencieuse.
        if ($op !== MutationOperation::OP_CREATE && $cibleId <= 0) {
            return [];
        }
        // Une création sans aucun champ n'écrirait rien : même raisonnement.
        if ($op === MutationOperation::OP_CREATE && $champs === []) {
            return [];
        }

        $operation = ['op' => $op, 'entite' => $entite, 'champs' => $champs];
        if ($cibleId > 0) {
            $operation['id'] = $cibleId;
        }
        if (($ref = trim((string) ($etape['ref'] ?? ''))) !== '') {
            $operation['ref'] = $ref;
        }

        return ['operations' => [$operation]];
    }

    public function aideEtape(): string
    {
        return 'preparer_operations : étape d\'écriture ORDINAIRE — donne « entite » (nom court de '
            . 'l\'entité), « operation » (create/edit/delete), « champs » en paires '
            . '[{"cle":"montant","valeur":"150"}], et « cibleId » pour un edit/delete. Pose « ref » sur une '
            . 'étape de CRÉATION dont une étape SUIVANTE a besoin, et donne alors à son champ de relation la '
            . 'valeur "@ref" : l\'identifiant sera injecté après l\'écriture de la première';
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
        $refus = $this->planBuilder->refusSiPlanEnAttente(
            $scope,
            ($args['remplacerPlanEnAttente'] ?? false) === true,
            $this->name(),
        );
        if ($refus !== null) {
            return $refus;
        }

        // La construction du plan (analyse, impacts, aperçu, budget, action
        // d'interface) appartient à PlanBuilder : cet outil ne sait que traduire
        // les opérations DICTÉES par le modèle en MutationPlan. Un second outil
        // les dérive d'un parcours métier — les deux doivent produire strictement
        // le même plan, d'où la source unique.
        return $this->planBuilder->construire(MutationPlan::fromArray($operations), $scope, $this->name());
    }
}
