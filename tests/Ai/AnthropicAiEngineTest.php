<?php

namespace App\Tests\Ai;

use App\Ai\Fournisseur\MemoireDEpuisement;
use App\Ai\Mutation\OutilsDePlan;
use App\Ai\AiContextBuilder;
use App\Ai\AiRequest;
use App\Ai\Comprehension\AppelGemini;
use App\Ai\Comprehension\Comprehenseur;
use App\Ai\Debit\BudgetDebit;
use App\Ai\Engine\AnthropicAiEngine;
use App\Ai\Engine\AppelDOutilEnTexte;
use App\Ai\Engine\DialecteGemini;
use App\Ai\Presentation\TableauMarkdown;
use App\Ai\Redaction\RepliPrecis;
use App\Ai\Programme\ProgrammeEnCours;
use App\Ai\Scope\AiScope;
use App\Ai\Telemetrie\JournalTokens;
use App\Ai\Tool\AiToolInterface;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\ExecuteurDOutils;
use App\Ai\Trousse\SelecteurDeTrousse;
use App\Ai\Trousse\TrousseCatalogue;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Repository\AssistantProgrammeRepository;
use App\Services\ServiceNombres;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * CE QUI EST PROPRE À L'ADAPTATEUR CLAUDE, et rien d'autre.
 *
 * Le comportement que les DEUX moteurs doivent partager — nombre d'appels,
 * relance du tour muet, plans refusés, chiffres des outils, bilan de message —
 * vit dans ContratDesMoteursTest, qui le joue contre l'un comme contre l'autre.
 * Ici ne restent que les particularités du fournisseur : le format du fil, les
 * pièces natives en blocs image/document, l'écho des inputs vides, les points de
 * rupture du cache, la lecture de l'« usage », et sa politique de réessai.
 *
 * Le choix du moteur, lui, est devenu une chaîne : cf. AiEngineResolverChaineTest.
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

            public function aiguillage(): string
            {
                return '';
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

    /** Le repli de rédaction, avec le rendu de tableau que Ket partage avec lui. */
    private function repliPrecis(): RepliPrecis
    {
        return new RepliPrecis(new TableauMarkdown(new ServiceNombres(new LocaleSwitcher('fr', []))));
    }

    /** Le modèle de test, le même partout : les clés de compteur en dépendent. */
    private const MODELE = 'claude-haiku-4-5';

    /** Plafond du compteur de débit des tests, pour en déduire ce qui a été déclaré. */
    private const PLAFOND = 2000000;

    /** @var list<array{message: string, context: array}> lignes de télémétrie captées */
    private array $telemetrie = [];

    /** Le compteur de débit du dernier moteur construit, pour l'interroger après coup. */
    private ?BudgetDebit $budget = null;

    /** @var list<int> secondes que le moteur a DEMANDÉ d'attendre (jamais dormies) */
    private array $attentes = [];

    /**
     * Compteur vierge, horloge figée, marge neutralisée : ce qu'on vérifie ici est
     * ce que le moteur DÉCLARE au compteur, pas l'arithmétique de la fenêtre
     * glissante — celle-ci a sa propre suite.
     */
    private function makeBudget(int $plafond = self::PLAFOND, int $instant = 1_000_000): BudgetDebit
    {
        return new BudgetDebit(new ArrayAdapter(), $plafond, 0.0, static fn (): int => $instant);
    }

    private function selecteurFige(): SelecteurDeTrousse
    {
        return new SelecteurDeTrousse(
            new ProgrammeEnCours(
                $this->createMock(AssistantProgrammeRepository::class),
                $this->createMock(EntityManagerInterface::class),
            ),
            new TrousseCatalogue([]),
        );
    }

    /**
     * COMPRENANT NEUTRE : il conclut toujours « claire », sur son PROPRE client HTTP.
     *
     * La phase de compréhension parle encore à Google en direct, quel que soit le
     * moteur de texte : elle a donc son client à elle, et les appels comptés sur le
     * client du moteur restent ceux du moteur. C'est aussi ce qui rend lisible le
     * test « un message ne coûte jamais plus de deux appels de travail ».
     */
    private function comprehenseurFige(AiContextBuilder $contextBuilder, JournalTokens $journal, BudgetDebit $budget): Comprehenseur
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode([
            'candidates'    => [['content' => ['parts' => [['text' => json_encode(
                ['claire' => true, 'intention' => 'Question de test'],
                JSON_THROW_ON_ERROR,
            )]]]]],
            'usageMetadata' => ['promptTokenCount' => 300],
        ], JSON_THROW_ON_ERROR)));

        return new Comprehenseur(
            new AppelGemini(
                $http,
                $contextBuilder,
                new DialecteGemini(new TrousseCatalogue([])),
                new ExecuteurDOutils([]),
                $budget,
                new MemoireDEpuisement(new ArrayAdapter()),
                'gm-test',
                'gemini-flash-lite-test',
            ),
            new ProgrammeEnCours(
                $this->createMock(AssistantProgrammeRepository::class),
                $this->createMock(EntityManagerInterface::class),
            ),
            $budget,
            $journal,
            new NullLogger(),
        );
    }

    private function makeEngine(
        MockHttpClient $http,
        array $tools = [],
        bool $cacheActif = true,
        ?BudgetDebit $budget = null,
    ): AnthropicAiEngine {
        $contextBuilder = $this->createMock(AiContextBuilder::class);
        $contextBuilder->method('toSystemPrompt')->willReturn('SYSTEM');
        // Le moteur Claude lit le prompt EN DEUX MORCEAUX : il lui faut savoir où
        // s'arrête l'invariant pour y poser le point de rupture du cache.
        $contextBuilder->method('promptSystemeEnDeux')->willReturn(['stable' => 'STABLE', 'volatil' => 'VOLATIL']);

        $espion = new class($this->telemetrie) extends AbstractLogger {
            public function __construct(private array &$lignes)
            {
            }

            public function log($level, $message, array $context = []): void
            {
                $this->lignes[] = ['message' => (string) $message, 'context' => $context];
            }
        };

        $this->budget = $budget ?? $this->makeBudget();
        $journal = new JournalTokens($espion, new OutilsDePlan([]));

        return new AnthropicAiEngine(
            $http,
            $contextBuilder,
            new TrousseCatalogue($tools),
            $this->selecteurFige(),
            new ExecuteurDOutils($tools),
            'sk-ant-test',
            self::MODELE,
            new NullLogger(),
            $journal,
            $this->budget,
            $this->repliPrecis(),
            new AppelDOutilEnTexte(),
            new OutilsDePlan([]),
            $this->comprehenseurFige($contextBuilder, $journal, $this->budget),
            $cacheActif,
            function (int $secondes): void {
                $this->attentes[] = $secondes;
            },
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

    /** Ce que le moteur a déclaré au compteur de débit depuis le début du test. */
    private function debitDeclare(): int
    {
        return self::PLAFOND - $this->budget->restant('anthropic:in:' . self::MODELE);
    }

    /**
     * Une réponse texte ordinaire, avec le bloc « usage » que l'API renvoie
     * toujours — et que ce moteur est désormais censé lire.
     */
    private static function reponseTexte(string $texte, array $usage = []): MockResponse
    {
        return new MockResponse(json_encode([
            'content'     => [['type' => 'text', 'text' => $texte]],
            'stop_reason' => 'end_turn',
            'usage'       => $usage + [
                'input_tokens'                => 0,
                'output_tokens'               => 0,
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens'     => 0,
            ],
        ]));
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

    /**
     * Une image ou un PDF scanné doit partir NATIVEMENT (vision), joint au dernier
     * tour utilisateur. Sans cela le prompt promettait au modèle une lecture
     * visuelle que ce moteur ne fournissait pas — et un modèle sommé de lire une
     * pièce qu'il ne reçoit pas n'a d'autre issue que d'inventer.
     */
    public function testPiecesNativesJointesAuDernierTourUtilisateur(): void
    {
        $bodies = [];
        $http = new MockHttpClient(function ($method, $url, $options) use (&$bodies) {
            $bodies[] = json_decode($options['body'], true);

            return new MockResponse(json_encode([
                'stop_reason' => 'end_turn',
                'content'     => [['type' => 'text', 'text' => 'La police court du 1er janvier au 31 décembre.']],
            ]));
        });

        $request = new AiRequest(
            systemContext: ['assistantNom' => 'Ket', 'entrepriseNom' => 'Courtage Test', 'perimetre' => [], 'date' => '2026-08-07'],
            messages: [
                ['role' => 'user', 'content' => 'Bonjour'],
                ['role' => 'assistant', 'content' => 'Bonjour !'],
                ['role' => 'user', 'content' => 'Que dit ce document ?'],
            ],
            scope: new AiScope(new Entreprise(), new Invite()),
            piecesNatives: [
                ['mimeType' => 'application/pdf', 'donneesBase64' => 'UERGREFUQQ==', 'nom' => 'police-scannee.pdf'],
                ['mimeType' => 'image/png', 'donneesBase64' => 'aW1hZ2U=', 'nom' => 'cachet.png'],
            ],
        );

        $this->makeEngine($http)->reply($request);

        $messages = $bodies[0]['messages'];
        // Les tours précédents restent de simples chaînes.
        $this->assertSame('Bonjour', $messages[0]['content']);
        $this->assertSame('Bonjour !', $messages[1]['content']);

        // Le DERNIER tour user devient une liste de blocs : texte, puis les pièces.
        $blocs = $messages[2]['content'];
        $this->assertIsArray($blocs);
        $this->assertSame('text', $blocs[0]['type']);
        $this->assertSame('Que dit ce document ?', $blocs[0]['text']);

        // Un PDF est un bloc « document », une image un bloc « image ».
        $this->assertSame('document', $blocs[1]['type']);
        $this->assertSame('application/pdf', $blocs[1]['source']['media_type']);
        $this->assertSame('base64', $blocs[1]['source']['type']);
        $this->assertSame('UERGREFUQQ==', $blocs[1]['source']['data']);

        $this->assertSame('image', $blocs[2]['type']);
        $this->assertSame('image/png', $blocs[2]['source']['media_type']);
        $this->assertSame('aW1hZ2U=', $blocs[2]['source']['data']);
    }

    /** Sans pièce native, les messages restent des chaînes nues (non-régression). */
    public function testSansPieceNativeLesMessagesRestentDesChaines(): void
    {
        $bodies = [];
        $http = new MockHttpClient(function ($method, $url, $options) use (&$bodies) {
            $bodies[] = json_decode($options['body'], true);

            return new MockResponse(json_encode([
                'stop_reason' => 'end_turn',
                'content'     => [['type' => 'text', 'text' => 'Ok.']],
            ]));
        });

        $this->makeEngine($http)->reply($this->makeRequest('Combien de clients ?'));

        $this->assertSame('Combien de clients ?', $bodies[0]['messages'][0]['content']);
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
        // Le prompt part en DEUX blocs : l'invariant, marqué pour le cache, puis ce
        // qui change à chaque message. Leur concaténation reste le prompt entier.
        $this->assertSame(
            ['STABLE', 'VOLATIL'],
            array_column($bodies[0]['system'], 'text'),
        );
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

    /**
     * LE MUR DU 2026-09-08, côté Anthropic — et sa sortie.
     *
     * Un tour de planification revenu sans texte ni appel d'outil arrêtait le message sur
     * place, et extractText() servait « Pouvez-vous préciser votre question ? » : notre
     * silence présenté comme l'imprécision de l'utilisateur. Comme chez Gemini, la
     * rédaction ne partira jamais dans ce cas — le second des deux appels est donc libre,
     * et le dépenser à redemander une réponse vaut mieux que de servir un mur.
     */
    public function testUnTourMuetEstRejoueUneFois(): void
    {
        $muet = ['stop_reason' => 'end_turn', 'content' => []];
        $bodies = [];
        $http = new MockHttpClient(function ($method, $url, $options) use (&$bodies, $muet) {
            $bodies[] = json_decode($options['body'] ?? '{}', true);

            return new MockResponse(json_encode(count($bodies) === 1 ? $muet : [
                'stop_reason' => 'end_turn',
                'content'     => [['type' => 'text', 'text' => 'J’enregistre les sept assureurs sous leur nom.']],
            ]));
        });

        $reply = $this->makeEngine($http)->reply($this->makeRequest('Invente pour moi des numéros.'));

        $this->assertSame('J’enregistre les sept assureurs sous leur nom.', $reply->content);
        $this->assertSame(2, $http->getRequestsCount(), 'Deux appels au total : la reprise remplace la rédaction.');

        // La relance est un échafaudage : elle sert à la reprise et ne doit laisser
        // aucune trace dans le fil que l'utilisateur relira.
        $contenus = array_column($bodies[1]['messages'] ?? [], 'content');
        $this->assertTrue(
            (bool) array_filter($contenus, static fn ($c) => is_string($c) && str_contains($c, 'NI texte NI appel')),
            'La reprise doit dire au modèle que son tour précédent était muet.',
        );
        $this->assertCount(1, $bodies[0]['messages'] ?? [], 'Le premier appel part sans relance.');
    }

    /**
     * DEUX TOURS MUETS D'AFFILÉE : il n'y a plus rien à tenter, mais la faute ne se
     * renvoie pas pour autant. L'outil du tour avait TROUVÉ quelque chose — on le
     * restitue (RepliPrecis) au lieu de demander à l'utilisateur de préciser.
     */
    public function testDeuxToursMuetsRestituentLeTravailDesOutilsEtNonUneQuestion(): void
    {
        $reponses = [
            [
                'stop_reason' => 'tool_use',
                'content'     => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'compter_entites', 'input' => ['entite' => 'Client']]],
            ],
            ['stop_reason' => 'end_turn', 'content' => []],
            ['stop_reason' => 'end_turn', 'content' => []],
        ];
        $i = 0;
        $http = new MockHttpClient(function () use (&$i, $reponses) {
            return new MockResponse(json_encode($reponses[$i++]));
        });

        $tool = $this->makeTool(AiToolResult::ok([
            'bloquant' => 'Aucun contrat ne porte cette référence dans votre portefeuille.',
        ]));
        $reply = $this->makeEngine($http, [$tool])->reply($this->makeRequest('Où en est le contrat MIC-RC0012454 ?'));

        $this->assertStringContainsString('Aucun contrat ne porte cette référence', $reply->content);
        $this->assertStringNotContainsString('préciser votre question', $reply->content,
            'Un repli ne doit pas renvoyer à l’utilisateur un travail que les outils ont déjà fait.');
    }

    // ──────────────────────────────────────────────────────────────────────────────
    // Robustesse réseau : ce qu'on rejoue, et surtout ce qu'on ne rejoue jamais
    // ──────────────────────────────────────────────────────────────────────────────

    /** 429 de débit, avec le délai que le fournisseur annonce lui-même. */
    private static function saturation(int $retryAfter): MockResponse
    {
        return new MockResponse(
            json_encode(['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'Rate limit.']]),
            ['http_code' => 429, 'response_headers' => ['content-type' => 'application/json', 'retry-after' => (string) $retryAfter]],
        );
    }

    /**
     * Le 429 du PLAFOND DE DÉPENSE mensuel, tel qu'Anthropic le renvoie : même code
     * HTTP que la saturation, aucun en-tête « retry-after », et une date de
     * réouverture écrite en toutes lettres dans le message.
     */
    private static function plafondDeDepense(): MockResponse
    {
        return new MockResponse(json_encode(['type' => 'error', 'error' => [
            'type'    => 'rate_limit_error',
            'message' => 'You have reached your API usage limits: your organization has crossed its '
                . 'monthly API usage threshold. You will regain access on 2026-10-01 at 00:00 UTC.',
            'details' => ['error_code' => 'enforced_spend_limit_reached'],
        ]]), ['http_code' => 429, 'response_headers' => ['content-type' => 'application/json']]);
    }

    public function testUneSaturationAvecDelaiCourtEstRejoueeUneFois(): void
    {
        $http = new MockHttpClient([self::saturation(3), self::reponseTexte('34 clients.')]);

        $reply = $this->makeEngine($http)->reply($this->makeRequest('Combien de clients ?'));

        $this->assertSame('34 clients.', $reply->content);
        $this->assertSame(2, $http->getRequestsCount(), 'Un refus rattrapable vaut un second essai, pas une excuse.');
        $this->assertSame([3], $this->attentes, 'On attend le délai ANNONCÉ par le fournisseur, jamais un délai deviné.');
    }

    public function testUneSaturationAuDelaiTropLongNEstPasRejouee(): void
    {
        $http = new MockHttpClient([self::saturation(120)]);

        try {
            $this->makeEngine($http)->reply($this->makeRequest('Combien de clients ?'));
            $this->fail('Le refus aurait dû remonter au point de repli du contrôleur.');
        } catch (\Throwable) {
        }

        $this->assertSame(1, $http->getRequestsCount());
        $this->assertSame([], $this->attentes,
            'Deux minutes de silence puis le même refus : mieux vaut une réponse honnête avec la bonne durée.');
    }

    /**
     * LE CAS QUI JUSTIFIE TOUTE LA MÉTHODE. Le plafond de dépense arrive en 429,
     * comme une saturation, mais aucune attente ne le rouvre : le rejouer, c'est
     * payer un aller-retour pour reproduire à l'identique un refus déjà certain.
     */
    public function testLePlafondDeDepenseNEstJamaisRejoue(): void
    {
        $http = new MockHttpClient([self::plafondDeDepense()]);

        $reply = $this->makeEngine($http)->reply($this->makeRequest('Combien de clients ?'));

        $this->assertSame(1, $http->getRequestsCount(), 'Un seul appel : rien ne sert de réessayer.');
        $this->assertSame([], $this->attentes);

        // Le message ne doit PAS promettre un retour dans quelques minutes : il doit
        // nommer la vraie cause et la date que le fournisseur annonce. C'est la
        // confusion que l'extraction du socle a mise au jour — le chemin commun
        // traitait ce 429 comme une saturation ordinaire.
        $this->assertStringContainsString('plafond de dépense mensuel', $reply->content);
        $this->assertStringContainsString('2026-10-01', $reply->content);
        $this->assertStringNotContainsString('quelques minutes', $reply->content);
        $this->assertStringNotContainsString('Relancez-la dans', $reply->content);

        // Et le message est CONCLU, pas jeté : un quota du fournisseur n'est pas une
        // panne de l'application, le fil doit rester intact.
        $this->assertSame('budget_atteint', $this->bilanDuMessage()['issue'] ?? null);
    }

    public function testUnModeleSurchargeEstRejoueUneFois(): void
    {
        $surcharge = new MockResponse(
            json_encode(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]),
            ['http_code' => 529, 'response_headers' => ['content-type' => 'application/json']],
        );
        $http = new MockHttpClient([$surcharge, self::reponseTexte('34 clients.')]);

        $reply = $this->makeEngine($http)->reply($this->makeRequest('Combien de clients ?'));

        $this->assertSame('34 clients.', $reply->content);
        $this->assertSame(2, $http->getRequestsCount());
        $this->assertSame([2], $this->attentes, 'Aucun délai annoncé sur une surcharge : on attend brièvement.');
    }

    public function testUneRequeteInvalideNEstJamaisRejouee(): void
    {
        $http = new MockHttpClient([new MockResponse(
            json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'bad schema']]),
            ['http_code' => 400, 'response_headers' => ['content-type' => 'application/json']],
        )]);

        try {
            $this->makeEngine($http)->reply($this->makeRequest('Combien de clients ?'));
            $this->fail('Un 400 aurait dû remonter.');
        } catch (\Throwable) {
        }

        $this->assertSame(1, $http->getRequestsCount(),
            'Un 400 est un défaut de NOTRE requête : le rejouer produirait exactement la même erreur.');
    }

    // ──────────────────────────────────────────────────────────────────────────────
    // Le cache de prompt : la moitié de la facture, et un échec parfaitement muet
    // ──────────────────────────────────────────────────────────────────────────────

    public function testLesOutilsPortentUnPointDeRuptureDeCache(): void
    {
        $bodies = [];
        $http = new MockHttpClient(function ($method, $url, $options) use (&$bodies) {
            $bodies[] = json_decode($options['body'], true);

            return self::reponseTexte('Bonjour.');
        });

        $this->makeEngine($http, [$this->makeTool(AiToolResult::ok(['total' => 1]))])
            ->reply($this->makeRequest('Bonjour'));

        $outils = $bodies[0]['tools'];
        $this->assertSame(
            ['type' => 'ephemeral'],
            $outils[array_key_last($outils)]['cache_control'] ?? null,
            'La marque va sur la DERNIÈRE déclaration : le cache est un préfixe, elle couvre tout le bloc.',
        );

        $marques = array_filter($outils, static fn (array $o) => isset($o['cache_control']));
        $this->assertCount(1, $marques,
            'Un seul point de rupture : en poser plusieurs ne cache rien de plus et multiplie les invalidations.');
    }

    public function testSansCacheAucunPointDeRuptureNEstPose(): void
    {
        $bodies = [];
        $http = new MockHttpClient(function ($method, $url, $options) use (&$bodies) {
            $bodies[] = json_decode($options['body'], true);

            return self::reponseTexte('Bonjour.');
        });

        $this->makeEngine($http, [$this->makeTool(AiToolResult::ok([]))], cacheActif: false)
            ->reply($this->makeRequest('Bonjour'));

        foreach ($bodies[0]['tools'] as $outil) {
            $this->assertArrayNotHasKey('cache_control', $outil,
                'ANTHROPIC_CACHE=0 doit vraiment tout renvoyer plein tarif, sinon comparer les deux régimes ne vaut rien.');
        }
    }

    /**
     * LE TEST QUI VAUT LE PLUS CHER DE CETTE SUITE.
     *
     * Le cache ne tient que si le bloc des outils est identique OCTET POUR OCTET
     * d'un appel à l'autre. Le jour où il cesse de l'être — une description() qui
     * daterait l'heure, un schéma dont les clés se sérialiseraient dans un ordre
     * instable — rien ne casse : les requêtes passent, aucune exception n'est
     * levée, aucune ligne de log n'apparaît. Seule la facture double.
     */
    public function testDeuxMessagesEnvoientDesDeclarationsOctetAOctetIdentiques(): void
    {
        $bodies = [];
        $http = new MockHttpClient(function ($method, $url, $options) use (&$bodies) {
            $bodies[] = json_decode($options['body'], true);

            return self::reponseTexte('Bonjour.');
        });

        $moteur = $this->makeEngine($http, [$this->makeTool(AiToolResult::ok(['total' => 1]))]);
        $moteur->reply($this->makeRequest('Combien de clients ?'));
        $moteur->reply($this->makeRequest('Et de polices ?'));

        $this->assertSame(
            json_encode($bodies[0]['tools'], JSON_UNESCAPED_UNICODE),
            json_encode($bodies[1]['tools'], JSON_UNESCAPED_UNICODE),
            'Le bloc des outils doit être stable octet à octet, sinon le cache ne prend jamais — en silence.',
        );
    }

    public function testLeBlocStableDuSystemePorteLePointDeRupture(): void
    {
        $bodies = [];
        $http = new MockHttpClient(function ($method, $url, $options) use (&$bodies) {
            $bodies[] = json_decode($options['body'], true);

            return self::reponseTexte('Bonjour.');
        });

        $this->makeEngine($http)->reply($this->makeRequest('Bonjour'));

        $systeme = $bodies[0]['system'];
        $this->assertSame(['type' => 'ephemeral'], $systeme[0]['cache_control'] ?? null,
            "L'invariant du prompt (~15 400 tokens) doit être caché : c'est la seconde moitié de l'économie.");
        $this->assertArrayNotHasKey('cache_control', $systeme[1],
            'Le bloc volatil ne doit JAMAIS porter de marque : on paierait une écriture par tour pour une relecture qui n’arrive jamais.');
    }

    public function testSansCacheLeSystemePartDUnSeulBloc(): void
    {
        $bodies = [];
        $http = new MockHttpClient(function ($method, $url, $options) use (&$bodies) {
            $bodies[] = json_decode($options['body'], true);

            return self::reponseTexte('Bonjour.');
        });

        $this->makeEngine($http, cacheActif: false)->reply($this->makeRequest('Bonjour'));

        $this->assertSame('STABLEVOLATIL', $bodies[0]['system'],
            'Cache coupé : le prompt repart d’un bloc, exactement comme avant le découpage.');
    }

    // ──────────────────────────────────────────────────────────────────────────────
    // La mesure : ce qui va au journal, et ce qui va au compteur de débit
    // ──────────────────────────────────────────────────────────────────────────────

    /**
     * « input_tokens » n'est PAS la taille du prompt : c'est le seul reliquat situé
     * après le dernier point de rupture. Le lire seul afficherait 100 là où le
     * prompt en fait 600, et rendrait la campagne incomparable avec celle de Gemini,
     * dont le promptTokenCount, lui, inclut les tokens cachés.
     */
    public function testLeTotalDEntreeAdditionneLesTroisCompteurs(): void
    {
        $http = new MockHttpClient([self::reponseTexte('Bonjour.', [
            'input_tokens'                => 100,
            'output_tokens'               => 50,
            'cache_creation_input_tokens' => 200,
            'cache_read_input_tokens'     => 300,
        ])]);

        $this->makeEngine($http)->reply($this->makeRequest('Bonjour'));

        $tour = $this->lignesDeTour()[0] ?? null;
        $this->assertNotNull($tour, 'Chaque aller-retour avec le fournisseur doit laisser une ligne de tour.');
        $this->assertSame(600, $tour['tokensEntree'], 'Le prompt entier : 100 + 200 + 300.');
        $this->assertSame(50, $tour['tokensSortie']);
        $this->assertSame(300, $tour['tokensCache'], 'Le sous-ensemble servi depuis le cache.');
    }

    /**
     * Chez Anthropic, les tokens LUS en cache sont exclus du plafond par minute :
     * n'entrent au compteur que le reliquat et ce qu'on vient d'écrire dans le
     * cache. Y déclarer le total ferait croire la fenêtre pleine six fois trop tôt,
     * et ferait patienter Ket devant une porte grande ouverte.
     */
    public function testLeDebitNEnregistrePasLesTokensLusEnCache(): void
    {
        $http = new MockHttpClient([self::reponseTexte('Bonjour.', [
            'input_tokens'                => 100,
            'output_tokens'               => 50,
            'cache_creation_input_tokens' => 200,
            'cache_read_input_tokens'     => 300,
        ])]);

        $this->makeEngine($http)->reply($this->makeRequest('Bonjour'));

        $this->assertSame(300, $this->debitDeclare(),
            'Seuls input_tokens + cache_creation comptent au plafond ; les 300 lus en cache, non.');
    }

    /**
     * Ce sont les sorties ANORMALES qui intéressent le plus la campagne : si l'une
     * d'elles oublie de conclure, c'est précisément la mesure qu'on cherchait qui
     * manque. D'où le point de sortie unique.
     */
    public function testChaqueCheminDeSortieProduitUnBilanDeMessage(): void
    {
        $refus = static fn (): MockResponse => new MockResponse(json_encode([
            'content'     => [],
            'stop_reason' => 'refusal',
            'usage'       => ['input_tokens' => 10, 'output_tokens' => 0],
        ]));

        $appelDOutil = static fn (): MockResponse => new MockResponse(json_encode([
            'content' => [[
                'type'  => 'tool_use',
                'id'    => 'tu_1',
                'name'  => 'compter_entites',
                'input' => ['entite' => 'Client'],
            ]],
            'stop_reason' => 'tool_use',
            'usage'       => ['input_tokens' => 10, 'output_tokens' => 5],
        ]));

        $outil = $this->makeTool(AiToolResult::ok(['total' => 34]));

        $cas = [
            'reponse'          => [[self::reponseTexte('34 clients.')], []],
            'blocage_securite' => [[$refus()], []],
            // Un outil tourne, puis le fournisseur refuse la rédaction faute de quota :
            // le message est conclu proprement, jamais jeté au contrôleur.
            'budget_atteint'   => [[$appelDOutil(), self::saturation(600)], [$outil]],
        ];

        foreach ($cas as $issueAttendue => [$reponses, $outils]) {
            $this->telemetrie = [];
            $this->makeEngine(new MockHttpClient($reponses), $outils)
                ->reply($this->makeRequest('Combien de clients ?'));

            $bilan = $this->bilanDuMessage();
            $this->assertNotNull($bilan, sprintf('Le chemin « %s » ne conclut pas.', $issueAttendue));
            $this->assertSame($issueAttendue, $bilan['issue']);
            $this->assertSame('anthropic', $bilan['moteur']);
            $this->assertSame(self::MODELE, $bilan['modele']);
        }
    }
}
