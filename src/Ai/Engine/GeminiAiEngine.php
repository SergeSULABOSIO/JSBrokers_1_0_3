<?php

namespace App\Ai\Engine;

use App\Ai\AiContextBuilder;
use App\Ai\AiReply;
use App\Ai\AiRequest;
use App\Ai\Telemetrie\JournalTokens;
use App\Ai\Tool\AiToolInterface;
use App\Ai\Tool\AiToolResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Moteur réel alternatif : API Google Gemini (generateContent) via
 * symfony/http-client, avec function calling — équivalent Gemini du
 * tool-calling de l'adaptateur Claude (AnthropicAiEngine) : le modèle décide
 * d'appeler nos outils métier, la boucle functionCall/functionResponse est
 * bornée, et TOUS les résultats d'un tour sont renvoyés dans UN message user.
 *
 * Différences de dialecte avec Claude gérées ici : rôles user/model (pas
 * assistant), prompt système dans systemInstruction, outils déclarés en
 * functionDeclarations (nos schema() JSON-Schema se mappent directement),
 * clé transmise par en-tête x-goog-api-key (jamais dans l'URL).
 *
 * SÉCURITÉ : identique aux autres moteurs — le périmètre ne dépend PAS du
 * modèle, chaque outil re-vérifie canRead() dans execute() (fail-closed).
 */
final class GeminiAiEngine implements AiEngineInterface
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models';
    /** Assez ample pour restituer une page de liste (rechercher_entites) sans troncature. */
    private const MAX_OUTPUT_TOKENS = 4096;
    /** Garde-fou : nombre maximal d'allers-retours de function calling par message. */
    private const MAX_TOOL_ROUNDS = 8;
    /**
     * Budget de tokens d'ENTRÉE pour UN message de l'utilisateur, tous tours de
     * function calling confondus.
     *
     * L'API est sans mémoire : chaque tour renvoie l'INTÉGRALITÉ du contexte
     * (prompt système + déclarations des 33 outils + historique + résultats des
     * outils déjà exécutés ≈ 130 Ko, soit ~35 000 tokens), si bien que 8 tours
     * coûtent 8 fois ce prix. Or le palier gratuit Gemini plafonne les tokens
     * d'entrée à 250 000 PAR MINUTE (quota
     * GenerateContentInputTokensPerModelPerMinute-FreeTier) : une seule question
     * un peu fouillée suffisait donc à faire tomber le tour en 429, APRÈS que
     * plusieurs outils aient déjà tourné — travail perdu et message « moteur
     * saturé » incompréhensible pour l'utilisateur.
     *
     * On s'arrête donc AVANT le mur. Le budget est calé un peu sous le plafond,
     * pas beaucoup : trop bas, on couperait des enchaînements qui auraient abouti ;
     * trop haut, on retombe dans le 429 — et celui-là coûte cher, puisque les
     * tokens du message sont DÉJÀ débités au compte de l'utilisateur (meterWrite)
     * avant l'appel au moteur. Un arrêt net qui explique quoi faire vaut mieux
     * qu'un tour perdu et facturé.
     */
    private const MAX_INPUT_TOKENS_PAR_MESSAGE = 200000;

    /** @var iterable<AiToolInterface> */
    private iterable $tools;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AiContextBuilder $contextBuilder,
        #[AutowireIterator('app.ai_tool')] iterable $tools,
        #[Autowire(env: 'GEMINI_API_KEY')] private readonly string $apiKey,
        #[Autowire(env: 'GEMINI_MODEL')] private readonly string $model,
        private readonly LoggerInterface $logger,
        private readonly JournalTokens $journal,
    ) {
        $this->tools = $tools;
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function modelName(): string
    {
        return $this->model;
    }

    public function reply(AiRequest $request): AiReply
    {
        // Ouvre la mesure : rattache les lignes « tour » qui suivent à ce message,
        // et permet au contrôleur de savoir combien de tours ont été payés si un
        // 429 interrompt la boucle.
        $this->journal->nouveauMessage();

        // Historique : notre rôle « assistant » devient « model » chez Gemini.
        $contents = array_map(
            static fn (array $m) => [
                'role'  => $m['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string) $m['content']]],
            ],
            $request->messages,
        );

        // Pièces jointes lisibles nativement (PDF scannés, images) : jointes au
        // DERNIER tour utilisateur en inlineData, pour que Gemini les lise par
        // vision avec la question courante (elles restent en contexte des rounds
        // de function calling suivants).
        if ($request->piecesNatives !== []) {
            for ($i = count($contents) - 1; $i >= 0; $i--) {
                if (($contents[$i]['role'] ?? null) !== 'user') {
                    continue;
                }
                foreach ($request->piecesNatives as $piece) {
                    $contents[$i]['parts'][] = ['inlineData' => [
                        'mimeType' => $piece['mimeType'],
                        'data'     => $piece['donneesBase64'],
                    ]];
                }
                break;
            }
        }

        $refused = false;
        $toolUsed = null;
        $actions = [];
        $cumulInput = 0;
        $cumulSortie = 0;
        $sequenceOutils = [];

        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            ['reponse' => $response, 'octets' => $octets] = $this->call($request, $contents);

            // Comptabilité des tokens d'ENTRÉE : le tour suivant renverra tout le
            // contexte, augmenté des résultats d'outils qu'on vient d'ajouter — il
            // coûtera donc au moins autant que celui-ci. Si ce prochain tour ferait
            // franchir le budget, on conclut MAINTENANT avec ce qu'on a plutôt que
            // de le perdre dans un 429.
            $usage = $response['usageMetadata'] ?? [];
            $tokensDuTour = (int) ($usage['promptTokenCount'] ?? 0);
            $cumulInput += $tokensDuTour;
            $cumulSortie += (int) ($usage['candidatesTokenCount'] ?? 0);

            $parts = $response['candidates'][0]['content']['parts'] ?? [];
            $functionCalls = array_values(array_filter($parts, static fn (array $p) => isset($p['functionCall'])));
            $outilsDuTour = array_map(
                static fn (array $p) => (string) $p['functionCall']['name'],
                $functionCalls,
            );

            $this->journal->tour(
                $request,
                $this->name(),
                $this->model,
                $round + 1,
                [
                    'entree' => $tokensDuTour,
                    'sortie' => (int) ($usage['candidatesTokenCount'] ?? 0),
                    'cache'  => (int) ($usage['cachedContentTokenCount'] ?? 0),
                ],
                $octets,
                $outilsDuTour,
            );

            // Requête bloquée par les garde-fous Gemini (prompt ou réponse).
            if ($this->estBloquee($response)) {
                return $this->conclure(
                    $request,
                    JournalTokens::ISSUE_BLOCAGE_SECURITE,
                    $round + 1,
                    $cumulInput,
                    $cumulSortie,
                    $sequenceOutils,
                    new AiReply(
                        "Je ne peux pas traiter cette demande. Reformulez votre question sur les données "
                        . 'de votre espace de travail et je vous aiderai volontiers.',
                        refused: true,
                    ),
                );
            }

            if ($functionCalls === []) {
                return $this->conclure(
                    $request,
                    JournalTokens::ISSUE_REPONSE,
                    $round + 1,
                    $cumulInput,
                    $cumulSortie,
                    $sequenceOutils,
                    new AiReply($this->extractText($parts), refused: $refused, toolUsed: $toolUsed, actions: $actions),
                );
            }

            // Budget d'entrée épuisé : on ne relance pas un tour condamné au 429.
            if ($cumulInput + $tokensDuTour > self::MAX_INPUT_TOKENS_PAR_MESSAGE) {
                $this->logger->warning('Assistant IA (gemini) : budget de tokens d\'entrée atteint, boucle arrêtée.', [
                    'tours'        => $round + 1,
                    'cumulEntree'  => $cumulInput,
                    'dernierOutil' => $outilsDuTour[0] ?? null,
                ]);

                return $this->conclure(
                    $request,
                    JournalTokens::ISSUE_BUDGET_ATTEINT,
                    $round + 1,
                    $cumulInput,
                    $cumulSortie,
                    // Les outils que le modèle VOULAIT encore appeler comptent : ce
                    // sont eux qui auraient fait franchir le mur.
                    [...$sequenceOutils, ...$outilsDuTour],
                    new AiReply(
                        "Votre demande m'a obligé à enchaîner trop de recherches d'un seul coup : je "
                        . "dois m'arrêter avant d'épuiser le quota de mon moteur. Découpez-la en "
                        . 'plusieurs questions plus ciblées (une entité, une période) et je la '
                        . 'traiterai entièrement.',
                        refused: $refused,
                        toolUsed: $toolUsed,
                        actions: $actions,
                    ),
                );
            }

            // Function calling : exécuter TOUS les appels demandés (fail-closed
            // dans chaque outil), réponses regroupées dans UN message user.
            $contents[] = ['role' => 'model', 'parts' => $this->preserverArgsObjets($parts)];
            $responseParts = [];
            foreach ($functionCalls as $part) {
                $name = (string) $part['functionCall']['name'];
                $args = (array) ($part['functionCall']['args'] ?? []);
                $result = $this->executeTool($name, $args, $request);
                $toolUsed = $name;
                $sequenceOutils[] = $name;
                if ($result->status === AiToolResult::STATUS_HORS_PERIMETRE) {
                    $refused = true;
                }
                if ($result->uiAction !== null) {
                    $actions[] = $result->uiAction;
                }
                $responseParts[] = [
                    'functionResponse' => [
                        'name'     => $name,
                        'response' => ['status' => $result->status] + $result->data,
                    ],
                ];
            }
            $contents[] = ['role' => 'user', 'parts' => $responseParts];
        }

        return $this->conclure(
            $request,
            JournalTokens::ISSUE_TOURS_EPUISES,
            self::MAX_TOOL_ROUNDS + 1,
            $cumulInput,
            $cumulSortie,
            $sequenceOutils,
            new AiReply(
                "Je n'ai pas réussi à conclure ma recherche dans le temps imparti. Reformulez votre "
                . 'question de façon plus ciblée, je réessaierai.',
                refused: $refused,
                toolUsed: $toolUsed,
                actions: $actions,
            ),
        );
    }

    /**
     * Point de sortie unique de reply() : journalise le bilan du message puis
     * rend la réponse. Passer par ici garantit qu'AUCUN chemin de sortie ne
     * manque à la campagne de mesure — or ce sont justement les sorties
     * anormales (budget, blocage, tours épuisés) qui l'intéressent le plus.
     *
     * @param list<string> $sequenceOutils
     */
    private function conclure(
        AiRequest $request,
        string $issue,
        int $tours,
        int $cumulEntree,
        int $cumulSortie,
        array $sequenceOutils,
        AiReply $reply,
    ): AiReply {
        $this->journal->message(
            $request,
            $this->name(),
            $this->model,
            $issue,
            $tours,
            $cumulEntree,
            $cumulSortie,
            $sequenceOutils,
        );

        return $reply;
    }

    /**
     * Appel HTTP generateContent (synchrone, sans streaming).
     *
     * Rend aussi la taille des trois blocs du payload. C'est la seule façon de
     * savoir OÙ partent les tokens sans payer un aller-retour countTokens : le
     * fournisseur ne renvoie qu'un total. Le rapport convertit ces octets en
     * tokens via le ratio observé.
     *
     * @return array{reponse: array, octets: array<string, int>}
     */
    private function call(AiRequest $request, array $contents): array
    {
        $promptSysteme = $this->contextBuilder->toSystemPrompt($request);
        $declarations = $this->toolDeclarations();

        $response = $this->httpClient->request('POST', sprintf('%s/%s:generateContent', self::API_BASE, $this->model), [
            'headers' => [
                'x-goog-api-key' => $this->apiKey,
                'content-type'   => 'application/json',
            ],
            'json' => [
                'systemInstruction' => ['parts' => [['text' => $promptSysteme]]],
                'contents'          => $contents,
                'tools'             => [['functionDeclarations' => $declarations]],
                'generationConfig'  => ['maxOutputTokens' => self::MAX_OUTPUT_TOKENS],
            ],
            'timeout' => 90,
        ]);

        return [
            'reponse' => $response->toArray(), // lève une exception explicite sur 4xx/5xx
            'octets'  => [
                'systeme'    => \strlen($promptSysteme),
                'outils'     => \strlen((string) json_encode($declarations, JSON_UNESCAPED_UNICODE)),
                'historique' => \strlen((string) json_encode($contents, JSON_UNESCAPED_UNICODE)),
            ],
        ];
    }

    /** Déclarations de fonctions au format Gemini (name/description/parameters). */
    private function toolDeclarations(): array
    {
        $declarations = [];
        foreach ($this->tools as $tool) {
            $declarations[] = [
                'name'        => $tool->name(),
                'description' => $tool->description(),
                'parameters'  => $this->sanitizeSchema($tool->schema()),
            ];
        }

        return $declarations;
    }

    /**
     * Le proto Schema de Gemini ne connaît qu'un SOUS-ENSEMBLE de JSON-Schema :
     * un mot-clé inconnu (ex. additionalProperties, posé par ouvrir_dialogue
     * pour le pré-remplissage libre) fait rejeter TOUTE la requête en 400
     * INVALID_ARGUMENT. On élague donc récursivement ces mots-clés ici — le
     * schéma des outils reste du JSON-Schema standard pour les autres moteurs
     * (Claude les accepte) ; c'est le dialecte Gemini qui s'adapte.
     */
    private function sanitizeSchema(array $schema): array
    {
        unset($schema['additionalProperties']);
        foreach ($schema as $key => $value) {
            if (\is_array($value)) {
                $schema[$key] = $this->sanitizeSchema($value);
            }
        }

        return $schema;
    }

    /**
     * PHP décode « args: {} » (objet JSON vide) en TABLEAU vide ; ré-encodé tel
     * quel dans l'écho du tour model, il redeviendrait [] (une liste), rejetée
     * par le proto Gemini (400 « Proto field is not repeating, cannot start
     * list »). On restitue l'objet vide — cas de tout outil SANS paramètre
     * (solde_tokens, quitter_workspace).
     */
    private function preserverArgsObjets(array $parts): array
    {
        foreach ($parts as $i => $part) {
            if (isset($part['functionCall']) && ($part['functionCall']['args'] ?? null) === []) {
                $parts[$i]['functionCall']['args'] = new \stdClass();
            }
        }

        return $parts;
    }

    private function executeTool(string $name, array $args, AiRequest $request): AiToolResult
    {
        foreach ($this->tools as $tool) {
            if ($tool->name() === $name) {
                return $tool->execute($args, $request->scope);
            }
        }

        return AiToolResult::introuvable($name);
    }

    /** Prompt bloqué ou réponse coupée par les filtres de sécurité Gemini ? */
    private function estBloquee(array $response): bool
    {
        return isset($response['promptFeedback']['blockReason'])
            || ($response['candidates'][0]['finishReason'] ?? null) === 'SAFETY';
    }

    /** Concatène les blocs texte de la réponse finale. */
    private function extractText(array $parts): string
    {
        $textes = [];
        foreach ($parts as $part) {
            if (isset($part['text']) && trim((string) $part['text']) !== '') {
                $textes[] = trim((string) $part['text']);
            }
        }

        return $textes === []
            ? "Je n'ai pas de réponse à formuler sur ce point. Pouvez-vous préciser votre question ?"
            : implode("\n\n", $textes);
    }
}
