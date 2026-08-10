<?php

namespace App\Ai\Tool;

use App\Ai\Action\TypeAction;

use App\Ai\Trousse\AiToolEcriture;

use App\Ai\AiText;
use App\Ai\Resolution\ResolveurDeReferences;
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
final class OuvrirDialogueTool implements AiToolInterface, AiToolEcriture
{
    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly JSBDynamicSearchService $searchService,
        private readonly EntiteLexique $lexique,
        private readonly EntiteLibelle $libelleur,
        private readonly PrefillWhitelist $prefill,
        // Source unique « un nom dicté → un identifiant ». Sans elle, cet outil
        // réclamait un id que le modèle n'a pas : voir le paramètre « nom ».
        private readonly ResolveurDeReferences $resolveur,
    ) {
    }

    public function name(): string
    {
        return 'ouvrir_dialogue';
    }

    public function description(): string
    {
        return "Ouvre dans l'espace de travail le formulaire d'une entité (mode « creation » vierge "
            . "ou « edition ») que l'utilisateur remplira et "
            . "enregistrera LUI-MÊME. En mode edition, désigne l'enregistrement par son NOM "
            . '(nom: "Olea") aussi bien que par son id : le serveur résout le nom lui-même, dans '
            . "l'entreprise et le périmètre de l'utilisateur. NE FAIS DONC JAMAIS une recherche "
            . "préalable pour obtenir un identifiant — tu n'aurais pas le tour suivant pour ouvrir "
            . 'le formulaire, et l\'utilisateur se retrouverait devant une liste au lieu du '
            . 'formulaire qu\'il a demandé. '
            . "À utiliser quand l'utilisateur veut SAISIR/VALIDER lui-même "
            . '(« ouvre le formulaire », « je vais le remplir/éditer moi-même »), ou pour une entité '
            . 'non gérée par preparer_operations. Pour un Client/Tâche/Note/Piste/Avenant, deux '
            . 'procédures coexistent : ici (l\'utilisateur enregistre lui-même) OU preparer_operations '
            . '(c\'est toi qui enregistres) — si l\'utilisateur n\'a pas dit laquelle il veut, '
            . 'DEMANDE-LUI d\'abord au lieu d\'ouvrir le formulaire. En creation, pré-remplissage '
            . 'possible via « valeurs » avec STRICTEMENT les valeurs dictées (jamais inventées). '
            . 'EXCEPTION : pour signaler le paiement d\'une PRIME sur une tranche, utiliser '
            . 'signaler_paiement_prime (jamais le formulaire Paiement, qui est la trésorerie du courtier).';
    }

    public function aiguillage(): string
    {
        return '« ouvre le formulaire de X », « ouvre le formulaire d\'ÉDITION de X », « modifie X moi-même », '
            . '« je vais le saisir / remplir / éditer moi-même » (l\'utilisateur veut remplir et enregistrer '
            . 'LUI-MÊME), ou création/édition d\'une entité que preparer_operations ne gère pas. Un FORMULAIRE '
            . 'n\'est pas une RUBRIQUE : « ouvre le formulaire d\'édition d\'Olea » appelle CET outil avec '
            . 'nom: "Olea" — jamais ouvrir_rubrique, qui n\'afficherait que la liste, et jamais '
            . 'rechercher_entites d\'abord, qui consommerait le seul tour d\'outils disponible.';
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
                    'description' => "Identifiant de l'enregistrement à éditer (mode edition). "
                        . 'Facultatif si « nom » est fourni.',
                ],
                'nom' => [
                    'type' => 'string',
                    'description' => "Nom de l'enregistrement à éditer, tel que l'utilisateur l'a dit "
                        . '(ex. "Olea", "Kibali Goldmines"). Le serveur le résout en identifiant : '
                        . "n'appelle PAS rechercher_entites pour cela.",
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

            // LE NOM SUFFIT — et c'est ce qui manquait le 2026-08-10. « Ouvre-moi le
            // formulaire d'édition pour Olea » exigeait un identifiant que le modèle
            // n'a pas : il dépensait donc son UNIQUE tour d'outils à chercher Olea, et
            // le message d'après, faute de pouvoir enchaîner, il ouvrait la RUBRIQUE
            // des partenaires. L'utilisateur demandait un formulaire et recevait une
            // liste. Le serveur résout donc le nom lui-même, comme partout ailleurs
            // (ResolveurDeReferences) : scopé à l'entreprise, fail-closed sur le droit
            // de lecture, et sans jamais deviner.
            $nom = trim((string) ($args['nom'] ?? ''));
            if ($id <= 0 && $nom !== '') {
                $reference = $this->resolveur->resoudre($shortName, $nom, $scope);
                if (!$reference->estResolue()) {
                    // Introuvable ou ambigu : une QUESTION, pas une erreur. Elle est posée
                    // telle quelle par la rédaction, avec les candidats à départager.
                    return AiToolResult::ok([
                        'pret'      => false,
                        'aDemander' => [$reference->question()],
                        'note'      => sprintf(
                            'Le formulaire d’édition n’a PAS été ouvert : « %s » ne désigne pas un '
                            . 'enregistrement unique. Pose la question telle quelle, en UNE ligne, en '
                            . 'PROPOSANT les « valeurs » quand il y en a. N’invente aucun identifiant, '
                            . 'ne relance AUCUN outil et n’ouvre aucune rubrique en remplacement.',
                            $nom,
                        ),
                    ]);
                }
                $id = (int) $reference->id;
            }

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
                'type'    => TypeAction::OUVRIR_DIALOGUE->value,
                'entite'  => $shortName,
                'mode'    => $mode,
                'id'      => $id,
                'valeurs' => $valeurs !== [] ? $valeurs : null,
            ], static fn ($v) => $v !== null),
        );
    }
}
