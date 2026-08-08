<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Ai\AiRequest;
use App\Ai\Engine\GeminiAiEngine;
use App\Ai\Scope\AiScope;
use App\Ai\Telemetrie\JournalTokens;
use App\Ai\Tool\AiToolInterface;
use App\Ai\Tool\AiToolResult;
use App\Entity\Entreprise;
use App\Entity\Invite;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Adaptateur API Gemini (generateContent + function calling) testé avec un
 * client HTTP mocké — aucun appel réseau. Vérifie : réponse texte simple,
 * boucle functionCall/functionResponse (résultats regroupés dans UN message
 * user, rôle « model » pour l'assistant), refus périmètre propagé, blocage
 * de sécurité Gemini géré.
 */
class GeminiAiEngineTest extends TestCase
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

    /** @var list<array{message: string, context: array}> lignes de télémétrie captées */
    private array $telemetrie = [];

    private function makeEngine(MockHttpClient $http, array $tools = []): GeminiAiEngine
    {
        $contextBuilder = $this->createMock(AiContextBuilder::class);
        $contextBuilder->method('toSystemPrompt')->willReturn('SYSTEM');

        $espion = new class($this->telemetrie) extends AbstractLogger {
            public function __construct(private array &$lignes)
            {
            }

            public function log($level, $message, array $context = []): void
            {
                $this->lignes[] = ['message' => (string) $message, 'context' => $context];
            }
        };

        return new GeminiAiEngine(
            $http,
            $contextBuilder,
            $tools,
            'gm-test',
            'gemini-2.5-flash',
            new NullLogger(),
            new JournalTokens($espion),
        );
    }

    /** Le bilan du message : il doit exister quel que soit le chemin de sortie. */
    private function bilanDuMessage(): ?array
    {
        foreach ($this->telemetrie as $ligne) {
            if (($ligne['context']['evenement'] ?? null) === 'message') {
                return $ligne['context'];
            }
        }

        return null;
    }

    /** @return list<array> lignes « tour » captées */
    private function lignesDeTour(): array
    {
        return array_values(array_filter(
            array_column($this->telemetrie, 'context'),
            static fn (array $c) => ($c['evenement'] ?? null) === 'tour',
        ));
    }

    private static function texte(string $text): array
    {
        return ['candidates' => [['finishReason' => 'STOP', 'content' => ['role' => 'model', 'parts' => [['text' => $text]]]]]];
    }

    public function testReponseTexteSimple(): void
    {
        $http = new MockHttpClient([new MockResponse(json_encode(self::texte('Bonjour ! Je suis Jess.')))]);

        $reply = $this->makeEngine($http)->reply($this->makeRequest('Qui es-tu ?'));

        $this->assertSame('Bonjour ! Je suis Jess.', $reply->content);
        $this->assertFalse($reply->refused);
        $this->assertNull($reply->toolUsed);
        $this->assertSame(1, $http->getRequestsCount());
    }

    public function testBoucleFunctionCalling(): void
    {
        $bodies = [];
        $reponses = [
            ['candidates' => [[
                'content' => ['role' => 'model', 'parts' => [
                    ['functionCall' => ['name' => 'compter_entites', 'args' => ['entite' => 'Client']]],
                ]],
            ]]],
            self::texte('Vous avez 3 clients.'),
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

        // 1re requête : systemInstruction + functionDeclarations.
        $this->assertSame('SYSTEM', $bodies[0]['systemInstruction']['parts'][0]['text']);
        $this->assertSame('compter_entites', $bodies[0]['tools'][0]['functionDeclarations'][0]['name']);

        // 2e requête : le tour du modèle est rejoué en rôle « model », puis la
        // functionResponse arrive dans UN message user avec les données.
        $messages = $bodies[1]['contents'];
        $avantDernier = $messages[count($messages) - 2];
        $dernier = end($messages);
        $this->assertSame('model', $avantDernier['role']);
        $this->assertSame('user', $dernier['role']);
        $this->assertSame('compter_entites', $dernier['parts'][0]['functionResponse']['name']);
        $this->assertSame(3, $dernier['parts'][0]['functionResponse']['response']['count']);
    }

    /**
     * Le proto Schema de Gemini rejette en 400 INVALID_ARGUMENT tout mot-clé
     * JSON-Schema qu'il ne connaît pas (vécu : `additionalProperties` posé par
     * ouvrir_dialogue pour le pré-remplissage libre → TOUS les messages du chat
     * échouaient). Les déclarations envoyées doivent être élaguées, à tous les
     * niveaux d'imbrication, sans perdre le reste du schéma.
     */
    public function testSchemaElaguePourLeDialecteGemini(): void
    {
        $bodies = [];
        $http = new MockHttpClient(function ($method, $url, $options) use (&$bodies) {
            $bodies[] = json_decode($options['body'], true);

            return new MockResponse(json_encode(self::texte('OK')));
        });

        $tool = new class implements AiToolInterface {
            public function name(): string
            {
                return 'ouvrir_dialogue';
            }

            public function description(): string
            {
                return 'Ouvre un formulaire.';
            }

            public function schema(): array
            {
                return [
                    'type' => 'object',
                    'properties' => [
                        'entite'  => ['type' => 'string'],
                        'valeurs' => [
                            'type' => 'object',
                            'description' => 'Pré-remplissage libre.',
                            'additionalProperties' => ['type' => ['string', 'number', 'boolean']],
                        ],
                        'imbrique' => [
                            'type'  => 'array',
                            'items' => ['type' => 'object', 'additionalProperties' => true],
                        ],
                    ],
                    'required' => ['entite'],
                ];
            }

            public function match(string $question, AiScope $scope): ?array
            {
                return null;
            }

            public function execute(array $args, AiScope $scope): AiToolResult
            {
                return AiToolResult::ok([]);
            }
        };

        $this->makeEngine($http, [$tool])->reply($this->makeRequest('Crée un client'));

        $declaration = $bodies[0]['tools'][0]['functionDeclarations'][0];
        $this->assertStringNotContainsString(
            'additionalProperties',
            json_encode($declaration),
            'Aucun mot-clé inconnu du proto Schema Gemini ne doit partir sur le réseau.'
        );
        // Le reste du schéma est intact (structure, description, required).
        $this->assertSame('object', $declaration['parameters']['properties']['valeurs']['type']);
        $this->assertSame('Pré-remplissage libre.', $declaration['parameters']['properties']['valeurs']['description']);
        $this->assertSame(['entite'], $declaration['parameters']['required']);
        $this->assertSame('object', $declaration['parameters']['properties']['imbrique']['items']['type']);
    }

    /**
     * Un outil SANS paramètre (solde_tokens, quitter_workspace) est appelé avec
     * « args: {} » ; PHP décode cet objet JSON vide en TABLEAU vide, que l'écho
     * du tour model ré-encoderait en [] (une liste) — 400 INVALID_ARGUMENT
     * « Proto field is not repeating, cannot start list » (vécu). L'objet vide
     * doit repartir en {} sur le réseau.
     */
    public function testEchoDesArgsVidesResteUnObjet(): void
    {
        $bodies = [];
        $reponses = [
            ['candidates' => [[
                'content' => ['role' => 'model', 'parts' => [
                    ['functionCall' => ['name' => 'compter_entites', 'args' => new \stdClass()]],
                ]],
            ]]],
            self::texte('Voici votre solde.'),
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
        $this->assertStringContainsString('"args":{}', $bodies[1]);
        $this->assertStringNotContainsString('"args":[]', $bodies[1]);
    }

    public function testRefusPerimetrePropage(): void
    {
        $reponses = [
            ['candidates' => [[
                'content' => ['role' => 'model', 'parts' => [
                    ['functionCall' => ['name' => 'compter_entites', 'args' => ['entite' => 'Client']]],
                ]],
            ]]],
            self::texte('Désolé, les Clients sont hors de votre périmètre.'),
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

    public function testBlocageSecuriteGereProprement(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['promptFeedback' => ['blockReason' => 'SAFETY'], 'candidates' => []])),
        ]);

        $reply = $this->makeEngine($http)->reply($this->makeRequest('Question problématique'));

        $this->assertTrue($reply->refused);
        $this->assertStringContainsString('Reformulez', $reply->content);
    }

    /**
     * L'API étant sans mémoire, chaque tour de function calling renvoie tout le
     * contexte : un enchaînement d'outils finit par franchir le plafond de tokens
     * d'ENTRÉE par minute du fournisseur (429, tour perdu ET déjà débité). Le
     * moteur doit s'arrêter de lui-même avant le mur, en le disant.
     */
    public function testBudgetDeTokensDEntreeArreteLaBoucleAvantLe429(): void
    {
        // Chaque tour déclare 60 000 tokens d'entrée. Après le 3e, le cumul est de
        // 180 000 et un 4e tour (estimé au même prix) porterait le total à 240 000,
        // au-delà du budget de 200 000 : la boucle doit s'arrêter là.
        $appelOutil = [
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [
                    ['functionCall' => ['name' => 'compter_entites', 'args' => ['entite' => 'Client']]],
                ]],
            ]],
            'usageMetadata' => ['promptTokenCount' => 60000],
        ];

        $appels = 0;
        $http = new MockHttpClient(function () use (&$appels, $appelOutil) {
            ++$appels;

            return new MockResponse(json_encode($appelOutil));
        });

        $tool = $this->makeTool(AiToolResult::ok(['count' => 3]));
        $reply = $this->makeEngine($http, [$tool])->reply($this->makeRequest('Analyse tout mon portefeuille'));

        $this->assertSame(3, $appels, 'La boucle doit cesser d\'appeler l\'API dès le budget atteint.');
        $this->assertStringContainsString('trop de recherches', $reply->content);
        $this->assertStringContainsString('plusieurs questions', $reply->content);
    }

    /**
     * La campagne de mesure ne vaut que si AUCUN chemin de sortie ne lui
     * échappe — or ce sont justement les sorties anormales (budget, blocage,
     * tours épuisés) qui l'intéressent le plus : ce sont les messages les plus
     * coûteux, et donc ceux qui expliquent la saturation.
     *
     * @dataProvider cheminsDeSortie
     */
    public function testChaqueCheminDeSortieProduitUnBilanDeMessage(
        callable $reponses,
        string $issueAttendue,
        int $toursAttendus,
    ): void {
        $http = new MockHttpClient($reponses);
        $tool = $this->makeTool(AiToolResult::ok(['count' => 3]));

        $this->makeEngine($http, [$tool])->reply($this->makeRequest('Question'));

        $bilan = $this->bilanDuMessage();

        $this->assertNotNull($bilan, 'Aucun bilan de message émis pour cette sortie.');
        $this->assertSame($issueAttendue, $bilan['issue']);
        $this->assertSame($toursAttendus, $bilan['tours']);
        $this->assertSame('gemini', $bilan['moteur']);
        $this->assertSame('gemini-2.5-flash', $bilan['modele']);
    }

    public static function cheminsDeSortie(): iterable
    {
        $appelOutil = static fn (int $tokens = 0) => array_filter([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [
                    ['functionCall' => ['name' => 'compter_entites', 'args' => ['entite' => 'Client']]],
                ]],
            ]],
            'usageMetadata' => $tokens > 0 ? ['promptTokenCount' => $tokens] : null,
        ]);

        yield 'réponse directe' => [
            static fn () => new MockResponse(json_encode([
                'candidates' => [['finishReason' => 'STOP', 'content' => ['role' => 'model', 'parts' => [['text' => 'Bonjour.']]]]],
            ])),
            JournalTokens::ISSUE_REPONSE,
            1,
        ];

        yield 'blocage de sécurité' => [
            static fn () => new MockResponse(json_encode(['promptFeedback' => ['blockReason' => 'SAFETY'], 'candidates' => []])),
            JournalTokens::ISSUE_BLOCAGE_SECURITE,
            1,
        ];

        // 60 000 tokens par tour : au 4e, la projection dépasse le budget.
        yield 'budget de tokens atteint' => [
            static fn () => new MockResponse(json_encode($appelOutil(60000))),
            JournalTokens::ISSUE_BUDGET_ATTEINT,
            3,
        ];

        // Le modèle rappelle un outil indéfiniment : MAX_TOOL_ROUNDS borne la boucle.
        yield 'tours épuisés' => [
            static fn () => new MockResponse(json_encode($appelOutil())),
            JournalTokens::ISSUE_TOURS_EPUISES,
            9,
        ];
    }

    /**
     * La répartition des octets est ce qui permet de dire OÙ partent les tokens
     * — sans elle, on saurait qu'un tour coûte cher, pas quel bloc allégér.
     */
    public function testLaLigneDeTourPorteLaRepartitionDesOctets(): void
    {
        $http = new MockHttpClient([new MockResponse(json_encode(
            self::texte('OK') + ['usageMetadata' => ['promptTokenCount' => 1234, 'candidatesTokenCount' => 7]],
        ))]);

        $this->makeEngine($http)->reply($this->makeRequest('Qui es-tu ?'));

        $tour = $this->lignesDeTour()[0];

        $this->assertSame(1234, $tour['tokensEntree']);
        $this->assertSame(7, $tour['tokensSortie']);
        $this->assertSame(\strlen('SYSTEM'), $tour['octetsSysteme']);
        $this->assertGreaterThan(0, $tour['octetsHistorique']);
        // Aucun outil déclaré dans ce test : le bloc reste un tableau JSON vide.
        $this->assertSame(2, $tour['octetsOutils']);
    }

    /** Sans usageMetadata, le garde-fou ne doit pas brider la boucle. */
    public function testAbsenceDeUsageMetadataNeBridePasLaBoucle(): void
    {
        $appelOutil = [
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [
                    ['functionCall' => ['name' => 'compter_entites', 'args' => ['entite' => 'Client']]],
                ]],
            ]],
        ];

        $appels = 0;
        $http = new MockHttpClient(function () use (&$appels, $appelOutil) {
            return ++$appels <= 3
                ? new MockResponse(json_encode($appelOutil))
                : new MockResponse(json_encode(self::texte('Vous avez 3 clients.')));
        });

        $tool = $this->makeTool(AiToolResult::ok(['count' => 3]));
        $reply = $this->makeEngine($http, [$tool])->reply($this->makeRequest('Combien de clients ?'));

        $this->assertSame('Vous avez 3 clients.', $reply->content);
        $this->assertSame(4, $appels);
    }
}
