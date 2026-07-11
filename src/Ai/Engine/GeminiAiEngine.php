<?php

namespace App\Ai\Engine;

use App\Ai\AiContextBuilder;
use App\Ai\AiReply;
use App\Ai\AiRequest;
use App\Ai\Tool\AiToolInterface;
use App\Ai\Tool\AiToolResult;
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

    /** @var iterable<AiToolInterface> */
    private iterable $tools;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AiContextBuilder $contextBuilder,
        #[AutowireIterator('app.ai_tool')] iterable $tools,
        #[Autowire(env: 'GEMINI_API_KEY')] private readonly string $apiKey,
        #[Autowire(env: 'GEMINI_MODEL')] private readonly string $model,
    ) {
        $this->tools = $tools;
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function reply(AiRequest $request): AiReply
    {
        // Historique : notre rôle « assistant » devient « model » chez Gemini.
        $contents = array_map(
            static fn (array $m) => [
                'role'  => $m['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string) $m['content']]],
            ],
            $request->messages,
        );

        $refused = false;
        $toolUsed = null;
        $actions = [];

        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            $response = $this->call($request, $contents);

            // Requête bloquée par les garde-fous Gemini (prompt ou réponse).
            if ($this->estBloquee($response)) {
                return new AiReply(
                    "Je ne peux pas traiter cette demande. Reformulez votre question sur les données "
                    . 'de votre espace de travail et je vous aiderai volontiers.',
                    refused: true,
                );
            }

            $parts = $response['candidates'][0]['content']['parts'] ?? [];
            $functionCalls = array_values(array_filter($parts, static fn (array $p) => isset($p['functionCall'])));

            if ($functionCalls === []) {
                return new AiReply($this->extractText($parts), refused: $refused, toolUsed: $toolUsed, actions: $actions);
            }

            // Function calling : exécuter TOUS les appels demandés (fail-closed
            // dans chaque outil), réponses regroupées dans UN message user.
            $contents[] = ['role' => 'model', 'parts' => $parts];
            $responseParts = [];
            foreach ($functionCalls as $part) {
                $name = (string) $part['functionCall']['name'];
                $args = (array) ($part['functionCall']['args'] ?? []);
                $result = $this->executeTool($name, $args, $request);
                $toolUsed = $name;
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

        return new AiReply(
            "Je n'ai pas réussi à conclure ma recherche dans le temps imparti. Reformulez votre "
            . 'question de façon plus ciblée, je réessaierai.',
            refused: $refused,
            toolUsed: $toolUsed,
            actions: $actions,
        );
    }

    /** Appel HTTP generateContent (synchrone, sans streaming). */
    private function call(AiRequest $request, array $contents): array
    {
        $response = $this->httpClient->request('POST', sprintf('%s/%s:generateContent', self::API_BASE, $this->model), [
            'headers' => [
                'x-goog-api-key' => $this->apiKey,
                'content-type'   => 'application/json',
            ],
            'json' => [
                'systemInstruction' => ['parts' => [['text' => $this->contextBuilder->toSystemPrompt($request)]]],
                'contents'          => $contents,
                'tools'             => [['functionDeclarations' => $this->toolDeclarations()]],
                'generationConfig'  => ['maxOutputTokens' => self::MAX_OUTPUT_TOKENS],
            ],
            'timeout' => 90,
        ]);

        return $response->toArray(); // lève une exception explicite sur 4xx/5xx
    }

    /** Déclarations de fonctions au format Gemini (name/description/parameters). */
    private function toolDeclarations(): array
    {
        $declarations = [];
        foreach ($this->tools as $tool) {
            $declarations[] = [
                'name'        => $tool->name(),
                'description' => $tool->description(),
                'parameters'  => $tool->schema(),
            ];
        }

        return $declarations;
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
