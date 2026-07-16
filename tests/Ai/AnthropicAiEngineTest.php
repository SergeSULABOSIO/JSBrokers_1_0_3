<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Ai\AiRequest;
use App\Ai\Engine\AiEngineResolver;
use App\Ai\Engine\AnthropicAiEngine;
use App\Ai\Engine\GeminiAiEngine;
use App\Ai\Engine\SimulatedAiEngine;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolInterface;
use App\Ai\Tool\AiToolResult;
use App\Entity\Entreprise;
use App\Entity\Invite;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Adaptateur API Claude (Messages API + tool-calling) testé avec un client
 * HTTP mocké — aucun appel réseau. Vérifie : réponse texte simple, boucle de
 * tool-calling (résultats renvoyés dans UN message user), refus périmètre
 * propagé, et sélection du moteur par le résolveur selon la clé API.
 */
class AnthropicAiEngineTest extends TestCase
{
    private function makeRequest(string $question): AiRequest
    {
        return new AiRequest(
            systemContext: [
                'assistantNom'  => 'Jess',
                'entrepriseNom' => 'Courtage Test',
                'perimetre'     => ['owner' => true, 'gestionnaire' => true, 'modules' => []],
                'date'          => '2026-07-11',
            ],
            messages: [['role' => 'user', 'content' => $question]],
            scope: new AiScope(new Entreprise(), new Invite()),
        );
    }

    private function makeTool(AiToolResult $result): AiToolInterface
    {
        return new class($result) implements AiToolInterface {
            public array $receivedArgs = [];

            public function __construct(private AiToolResult $result)
            {
            }

            public function name(): string
            {
                return 'compter_entites';
            }

            public function description(): string
            {
                return 'Compte les enregistrements.';
            }

            public function schema(): array
            {
                return ['type' => 'object', 'properties' => ['entite' => ['type' => 'string']], 'required' => ['entite']];
            }

            public function match(string $question, AiScope $scope): ?array
            {
                return null; // jamais utilisé par le moteur réel
            }

            public function execute(array $args, AiScope $scope): AiToolResult
            {
                $this->receivedArgs = $args;

                return $this->result;
            }
        };
    }

    private function makeEngine(MockHttpClient $http, array $tools = []): AnthropicAiEngine
    {
        $contextBuilder = $this->createMock(AiContextBuilder::class);
        $contextBuilder->method('toSystemPrompt')->willReturn('SYSTEM');

        return new AnthropicAiEngine($http, $contextBuilder, $tools, 'sk-ant-test', 'claude-opus-4-8');
    }

    public function testReponseTexteSimple(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'stop_reason' => 'end_turn',
                'content'     => [['type' => 'text', 'text' => 'Bonjour ! Je suis Jess.']],
            ])),
        ]);

        $reply = $this->makeEngine($http)->reply($this->makeRequest('Qui es-tu ?'));

        $this->assertSame('Bonjour ! Je suis Jess.', $reply->content);
        $this->assertFalse($reply->refused);
        $this->assertNull($reply->toolUsed);
        $this->assertSame(1, $http->getRequestsCount());
    }

    public function testBoucleToolCalling(): void
    {
        $bodies = [];
        $reponses = [
            [
                'stop_reason' => 'tool_use',
                'content'     => [
                    ['type' => 'text', 'text' => 'Je compte vos clients.'],
                    ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'compter_entites', 'input' => ['entite' => 'Client']],
                ],
            ],
            [
                'stop_reason' => 'end_turn',
                'content'     => [['type' => 'text', 'text' => 'Vous avez 3 clients.']],
            ],
        ];
        $i = 0;
        $http = new MockHttpClient(function ($method, $url, $options) use (&$bodies, &$i, $reponses) {
            $bodies[] = json_decode($options['body'], true);

            return new MockResponse(json_encode($reponses[$i++]));
        });

        $tool = $this->makeTool(AiToolResult::ok(['entite' => 'Client', 'libelle' => 'Clients', 'count' => 3]));
        $reply = $this->makeEngine($http, [$tool])->reply($this->makeRequest('Combien de clients ?'));

        $this->assertSame('Vous avez 3 clients.', $reply->content);
        $this->assertSame('compter_entites', $reply->toolUsed);
        $this->assertFalse($reply->refused);
        $this->assertSame(['entite' => 'Client'], $tool->receivedArgs);

        // 1re requête : outils déclarés + prompt système.
        $this->assertSame('SYSTEM', $bodies[0]['system']);
        $this->assertSame('compter_entites', $bodies[0]['tools'][0]['name']);

        // 2e requête : le tool_result est renvoyé dans UN message user, lié au bon id.
        $dernier = end($bodies[1]['messages']);
        $this->assertSame('user', $dernier['role']);
        $this->assertSame('tool_result', $dernier['content'][0]['type']);
        $this->assertSame('tu_1', $dernier['content'][0]['tool_use_id']);
        $this->assertStringContainsString('"count":3', $dernier['content'][0]['content']);
    }

    /**
     * Un outil SANS paramètre (solde_tokens, quitter_workspace) est appelé avec
     * « input: {} » ; PHP décode cet objet JSON vide en TABLEAU vide, que l'écho
     * du tour assistant ré-encoderait en [] (une liste) — rejeté par l'API
     * (l'input d'un tool_use doit être un objet). L'objet vide doit repartir
     * en {} sur le réseau.
     */
    public function testEchoDesInputsVidesResteUnObjet(): void
    {
        $bodies = [];
        $reponses = [
            [
                'stop_reason' => 'tool_use',
                'content'     => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'compter_entites', 'input' => new \stdClass()]],
            ],
            [
                'stop_reason' => 'end_turn',
                'content'     => [['type' => 'text', 'text' => 'Voici votre solde.']],
            ],
        ];
        $i = 0;
        $http = new MockHttpClient(function ($method, $url, $options) use (&$bodies, &$i, $reponses) {
            $bodies[] = (string) $options['body'];

            return new MockResponse(json_encode($reponses[$i++]));
        });

        $tool = $this->makeTool(AiToolResult::ok(['total' => 1000]));
        $reply = $this->makeEngine($http, [$tool])->reply($this->makeRequest('Solde des tokens ?'));

        $this->assertSame('Voici votre solde.', $reply->content);
        $this->assertSame([], $tool->receivedArgs);
        $this->assertStringContainsString('"input":{}', $bodies[1]);
        $this->assertStringNotContainsString('"input":[]', $bodies[1]);
    }

    public function testRefusPerimetrePropage(): void
    {
        $reponses = [
            [
                'stop_reason' => 'tool_use',
                'content'     => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'compter_entites', 'input' => ['entite' => 'Client']]],
            ],
            [
                'stop_reason' => 'end_turn',
                'content'     => [['type' => 'text', 'text' => 'Désolé, les Clients sont hors de votre périmètre.']],
            ],
        ];
        $i = 0;
        $http = new MockHttpClient(function () use (&$i, $reponses) {
            return new MockResponse(json_encode($reponses[$i++]));
        });

        $tool = $this->makeTool(AiToolResult::horsPerimetre('Clients'));
        $reply = $this->makeEngine($http, [$tool])->reply($this->makeRequest('Combien de clients ?'));

        $this->assertTrue($reply->refused, 'Un outil HORS_PERIMETRE doit marquer la réponse comme refus.');
        $this->assertStringContainsString('périmètre', $reply->content);
    }

    public function testResolverChoisitLeMoteurSelonLesCles(): void
    {
        $contextBuilder = $this->createMock(AiContextBuilder::class);
        $simulated = new SimulatedAiEngine([]);
        $anthropic = $this->makeEngine(new MockHttpClient([]));
        $gemini = new GeminiAiEngine(new MockHttpClient([]), $contextBuilder, [], 'gm-x', 'gemini-2.5-flash');

        // Aucune clé → simulateur.
        $aucune = new AiEngineResolver($simulated, $anthropic, $gemini, '', '');
        $this->assertSame('simulated', $aucune->name());

        // Clé Gemini seule → Gemini.
        $geminiSeul = new AiEngineResolver($simulated, $anthropic, $gemini, '', 'gm-xxx');
        $this->assertSame('gemini', $geminiSeul->name());

        // Les deux clés → priorité à Anthropic.
        $lesDeux = new AiEngineResolver($simulated, $anthropic, $gemini, 'sk-ant-xxx', 'gm-xxx');
        $this->assertSame('anthropic', $lesDeux->name());

        // Forçage AI_ENGINE : prioritaire sur les clés (c'est la garde de .env.test).
        $force = new AiEngineResolver($simulated, $anthropic, $gemini, 'sk-ant-xxx', 'gm-xxx', 'simulated');
        $this->assertSame('simulated', $force->name());
        $forceGemini = new AiEngineResolver($simulated, $anthropic, $gemini, 'sk-ant-xxx', 'gm-xxx', 'gemini');
        $this->assertSame('gemini', $forceGemini->name());
    }
}
