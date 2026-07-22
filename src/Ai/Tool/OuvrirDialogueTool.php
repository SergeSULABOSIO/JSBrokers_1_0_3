<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Entity\Invite;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;

/**
 * Outil d'ACTION UI : ouvre pour l'utilisateur le formulaire d'une entité de
 * son espace de travail, en création ou en édition. L'assistant n'écrit JAMAIS
 * en base : il émet une directive d'intention (AiToolResult::uiAction) que le
 * chat traduit en ouverture de dialogue — l'utilisateur relit, complète et
 * enregistre lui-même via le circuit standard (validation serveur incluse).
 *
 * FAIL-CLOSED : ouvrir un formulaire est une mutation à venir — niveau
 * Écriture exigé en création, Modification en édition (patron
 * AvenantController::getPisteDeriveeContext). En édition, l'enregistrement est
 * résolu STRICTEMENT dans l'entreprise du scope (JSBDynamicSearchService).
 */
final class OuvrirDialogueTool implements AiToolInterface
{
    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly JSBDynamicSearchService $searchService,
        private readonly EntiteLexique $lexique,
        private readonly EntiteLibelle $libelleur,
        private readonly PrefillWhitelist $prefill,
    ) {
    }

    public function name(): string
    {
        return 'ouvrir_dialogue';
    }

    public function description(): string
    {
        return "Ouvre dans l'espace de travail de l'utilisateur le formulaire d'une entité : mode "
            . "« creation » (nouvel enregistrement vierge) ou « edition » (enregistrement existant, "
            . "id requis — obtiens-le d'abord via rechercher_entites si tu ne l'as pas). À appeler "
            . 'quand l’utilisateur demande d’ouvrir, créer, ajouter ou modifier une fiche. En mode '
            . 'creation, tu peux PRÉ-REMPLIR le formulaire via « valeurs » avec STRICTEMENT les '
            . 'valeurs dictées par l’utilisateur — n’invente ni ne devine JAMAIS une valeur. '
            . 'Cet outil n’écrit rien : l’utilisateur vérifie et enregistre lui-même le formulaire. '
            . 'EXCEPTION : pour signaler le paiement d\'une PRIME sur une tranche, utiliser '
            . 'signaler_paiement_prime (jamais le formulaire Paiement, qui est un encaissement '
            . 'de trésorerie du courtier).';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entite' => [
                    'type' => 'string',
                    'description' => "Nom court de l'entité du formulaire (ex. Client, Avenant, Piste).",
                    'enum' => $this->lexique->nomsCourts(),
                ],
                'mode' => [
                    'type' => 'string',
                    'enum' => ['creation', 'edition'],
                    'description' => 'creation = formulaire vierge ; edition = modifier un enregistrement existant.',
                ],
                'id' => [
                    'type' => 'integer',
                    'description' => "Identifiant de l'enregistrement à éditer (requis en mode edition).",
                ],
                'valeurs' => [
                    'type' => 'object',
                    'description' => 'Pré-remplissage facultatif (mode creation uniquement) : champ '
                        . 'scalaire => valeur, STRICTEMENT telles que dictées par l\'utilisateur '
                        . '(ex. {"nom": "Kabila Corp", "telephone": "+243..."}). Ne jamais inventer '
                        . 'ni deviner une valeur. Les relations ne sont pas pré-remplissables.',
                    'additionalProperties' => ['type' => ['string', 'number', 'boolean']],
                ],
            ],
            'required' => ['entite', 'mode'],
        ];
    }

    /**
     * Chemin simulé : création uniquement — l'édition exige un id que le
     * matching par mots-clés ne sait pas résoudre (réservé au LLM réel, qui
     * enchaîne rechercher_entites puis ouvrir_dialogue).
     */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);
        // « ouvre la rubrique X » relève de ouvrir_rubrique, pas d'un formulaire.
        if (preg_match('/\b(rubrique|section|module)\b/', $normalized)) {
            return null;
        }
        // Le paiement d'une PRIME relève des outils dédiés (signaler_paiement_prime pour
        // l'action, paiements_prime pour la lecture) — surtout PAS du formulaire Paiement,
        // qui est la trésorerie du courtier (garde partagée, cf. PaiementPrimeIntent).
        if (PaiementPrimeIntent::concerne($normalized)) {
            return null;
        }
        if (!preg_match('/\b(cree[rsz]?|ajoute[rsz]?|nouveau|nouvelle|ouvre[sz]?|ouvrir)\b/', $normalized)) {
            return null;
        }

        $shortName = $this->lexique->matchEntite($normalized);

        return $shortName === null ? null : ['entite' => $shortName, 'mode' => 'creation'];
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $shortName = (string) ($args['entite'] ?? '');
        $labels = $this->accessResolver->libellesEntites();
        if (!isset($labels[$shortName])) {
            return AiToolResult::introuvable($shortName);
        }

        $fqcn = 'App\\Entity\\' . $shortName;
        if (!class_exists($fqcn)) {
            return AiToolResult::introuvable($shortName);
        }

        $mode = (string) ($args['mode'] ?? '');
        if (!in_array($mode, ['creation', 'edition'], true)) {
            return AiToolResult::introuvable($mode);
        }

        // FAIL-CLOSED : ouvrir un formulaire prépare une mutation — Écriture
        // en création, Modification en édition.
        $level = $mode === 'edition' ? Invite::ACCESS_MODIFICATION : Invite::ACCESS_ECRITURE;
        if (!$this->accessResolver->can($scope->invite, $shortName, $level)) {
            return AiToolResult::horsPerimetre($labels[$shortName]);
        }

        $id = null;
        $cible = null;
        if ($mode === 'edition') {
            $id = (int) ($args['id'] ?? 0);
            if ($id <= 0) {
                return AiToolResult::introuvable($labels[$shortName]);
            }

            // Scoping : l'enregistrement doit exister DANS l'entreprise du scope.
            $result = $this->searchService->search($fqcn, ['id' => $id], $scope->entreprise, null, 1, 1);
            $entity = $result['data'][0] ?? null;
            if (($result['status']['code'] ?? 500) !== 200 || $entity === null) {
                return AiToolResult::introuvable(sprintf('%s #%d', $labels[$shortName], $id));
            }
            $cible = $this->libelleur->libelle($entity, $this->libelleur->displayField($fqcn));
        }

        // Pré-remplissage (création uniquement) : whitelist défense-en-profondeur —
        // dialogContext() re-filtrera, seule SA réponse touche le DOM.
        $valeurs = [];
        if ($mode === 'creation') {
            $valeurs = $this->prefill->filtrer($fqcn, (array) ($args['valeurs'] ?? []));
        }

        return AiToolResult::ok(
            array_filter([
                'entite'    => $shortName,
                'libelle'   => $labels[$shortName],
                'mode'      => $mode,
                'id'        => $id,
                'cible'     => $cible,
                'precharge' => $valeurs !== [] ? array_keys($valeurs) : null,
                'note'      => "Le formulaire s'ouvre dans l'espace de travail"
                    . ($valeurs !== [] ? ', pré-rempli avec les valeurs dictées' : '')
                    . " : l'utilisateur le complètera et l’enregistrera lui-même.",
            ], static fn ($v) => $v !== null),
            uiAction: array_filter([
                'type'    => 'open-dialog',
                'entite'  => $shortName,
                'mode'    => $mode,
                'id'      => $id,
                'valeurs' => $valeurs !== [] ? $valeurs : null,
            ], static fn ($v) => $v !== null),
        );
    }
}
