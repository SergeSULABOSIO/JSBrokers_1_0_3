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
 * Moteur RÉEL de l'assistant : API Claude (Anthropic Messages API) via
 * symfony/http-client, avec tool-calling natif — le modèle décide lui-même
 * d'appeler nos outils métier pour répondre aux questions de données.
 *
 * Adaptateur direct (sans SDK) : le projet est verrouillé en Symfony 7.1.*,
 * incompatible avec les paquets symfony/ai-* (qui exigent clock ^7.3 et
 * phpdoc-parser ^2). Le jour où le socle passera en 7.3+, un adaptateur
 * Symfony AI pourra remplacer celui-ci par simple repointage d'alias.
 *
 * SÉCURITÉ : le périmètre ne dépend PAS du modèle — chaque outil re-vérifie
 * canRead() dans execute() (fail-closed). Le prompt système ne fait qu'énoncer
 * la politesse du refus ; la garde est dans le code.
 */
final class AnthropicAiEngine implements AiEngineInterface
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    /** Assez ample pour restituer une page de liste (rechercher_entites) sans troncature. */
    private const MAX_OUTPUT_TOKENS = 4096;
    /** Garde-fou : nombre maximal d'allers-retours de tool-calling par message. */
    private const MAX_TOOL_ROUNDS = 8;

    /** @var iterable<AiToolInterface> */
    private iterable $tools;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AiContextBuilder $contextBuilder,
        #[AutowireIterator('app.ai_tool')] iterable $tools,
        #[Autowire(env: 'ANTHROPIC_API_KEY')] private readonly string $apiKey,
        #[Autowire(env: 'ANTHROPIC_MODEL')] private readonly string $model,
    ) {
        $this->tools = $tools;
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function reply(AiRequest $request): AiReply
    {
        $messages = array_map(
            static fn (array $m) => ['role' => $m['role'], 'content' => $m['content']],
            $request->messages,
        );

        $refused = false;
        $toolUsed = null;
        $actions = [];

        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            $response = $this->call($request, $messages);

            // Garde de sécurité Anthropic : la requête a été déclinée.
            if (($response['stop_reason'] ?? null) === 'refusal') {
                return new AiReply(
                    "Je ne peux pas traiter cette demande. Reformulez votre question sur les données "
                    . 'de votre espace de travail et je vous aiderai volontiers.',
                    refused: true,
                );
            }

            if (($response['stop_reason'] ?? null) !== 'tool_use') {
                return new AiReply($this->extractText($response), refused: $refused, toolUsed: $toolUsed, actions: $actions);
            }

            // Tool-calling : exécuter TOUS les appels demandés (fail-closed dans
            // chaque outil) et renvoyer tous les résultats dans UN SEUL message user.
            $messages[] = ['role' => 'assistant', 'content' => $response['content']];
            $toolResults = [];
            foreach ($response['content'] as $block) {
                if (($block['type'] ?? null) !== 'tool_use') {
                    continue;
                }
                $result = $this->executeTool((string) $block['name'], (array) ($block['input'] ?? []), $request);
                $toolUsed = (string) $block['name'];
                if ($result->status === AiToolResult::STATUS_HORS_PERIMETRE) {
                    $refused = true;
                }
                if ($result->uiAction !== null) {
                    $actions[] = $result->uiAction;
                }
                $toolResults[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => (string) $block['id'],
                    'content'     => json_encode(
                        ['status' => $result->status] + $result->data,
                        JSON_UNESCAPED_UNICODE,
                    ),
                ];
            }
            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        return new AiReply(
            "Je n'ai pas réussi à conclure ma recherche dans le temps imparti. Reformulez votre "
            . 'question de façon plus ciblée, je réessaierai.',
            refused: $refused,
            toolUsed: $toolUsed,
            actions: $actions,
        );
    }

    /** Appel HTTP Messages API (synchrone, sans streaming). */
    private function call(AiRequest $request, array $messages): array
    {
        $response = $this->httpClient->request('POST', self::API_URL, [
            'headers' => [
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ],
            'json' => [
                'model'      => $this->model,
                'max_tokens' => self::MAX_OUTPUT_TOKENS,
                'system'     => $this->contextBuilder->toSystemPrompt($request),
                'tools'      => $this->toolDefinitions(),
                'messages'   => $messages,
            ],
            'timeout' => 90,
        ]);

        return $response->toArray(); // lève une exception explicite sur 4xx/5xx
    }

    /** Définitions de tools au format Messages API (name/description/input_schema). */
    private function toolDefinitions(): array
    {
        $definitions = [];
        foreach ($this->tools as $tool) {
            $definitions[] = [
                'name'         => $tool->name(),
                'description'  => $tool->description(),
                'input_schema' => $tool->schema(),
            ];
        }

        return $definitions;
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

    /** Concatène les blocs texte de la réponse finale. */
    private function extractText(array $response): string
    {
        $parts = [];
        foreach (($response['content'] ?? []) as $block) {
            if (($block['type'] ?? null) === 'text' && trim((string) $block['text']) !== '') {
                $parts[] = trim((string) $block['text']);
            }
        }

        return $parts === []
            ? "Je n'ai pas de réponse à formuler sur ce point. Pouvez-vous préciser votre question ?"
            : implode("\n\n", $parts);
    }
}
