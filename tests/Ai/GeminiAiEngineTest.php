<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Ai\AiRequest;
use App\Ai\Debit\BudgetDebit;
use App\Ai\Engine\GeminiAiEngine;
use App\Ai\Mutation\OutilsDePlan;
use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Programme\ProgrammeEnCours;
use App\Ai\Scope\AiScope;
use App\Ai\Telemetrie\JournalTokens;
use App\Ai\Tool\AiToolInterface;
use App\Ai\Tool\AiToolResult;
use App\Ai\Trousse\AiToolEcriture;
use App\Ai\Trousse\SelecteurDeTrousse;
use App\Ai\Trousse\Trousse;
use App\Ai\Trousse\TrousseCatalogue;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Repository\AssistantProgrammeRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
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

    /**
     * SÉLECTEUR RÉEL, mais dans un contexte neutre : ces tests portent sur les DEUX
     * PHASES, pas sur le choix de la trousse. Sans conversation dans le scope et
     * sans verbe d'action dans la question, la sélection est déterministe.
     */
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
     * LA RÈGLE D'ARCHITECTURE, verrouillée : le modèle n'orchestre plus, PHP
     * orchestre. Un message coûte DEUX appels — les outils, puis la formulation —
     * quoi que le modèle réclame ensuite.
     *
     * Sans ce test, la boucle pourrait revenir d'un simple changement de constante,
     * et personne ne le verrait avant la prochaine saturation : le 2026-08-10, cinq
     * tours enchaînés ont consommé 188 000 tokens sur les 212 500 d'une minute, et
     * rendu la conversation entière inutilisable — « salut » compris.
     */
    public function testUnMessageNeCoutteJamaisPlusDeDeuxAppels(): void
    {
        $appels = 0;
        // Le modèle redemande un outil À CHAQUE tour : le pire cas, celui qui
        // faisait précédemment neuf appels.
        $http = new MockHttpClient(function () use (&$appels) {
            ++$appels;

            return new MockResponse(json_encode($this->tourAvecOutil(1000)));
        });

        $tool = $this->makeTool(AiToolResult::ok(['count' => 3]));
        $this->makeEngine($http, [$tool])->reply($this->makeRequest('Analyse tout mon portefeuille'));

        $this->assertSame(2, $appels, 'Un message ne doit jamais dépasser deux appels au moteur.');
    }

    /**
     * LA SECONDE MOITIÉ DE L'ÉCONOMIE : la phase de RÉDACTION ne déclare AUCUN outil.
     *
     * Les 72 Ko de déclarations ne servent qu'à CHOISIR un outil ; commenter un
     * résultat déjà obtenu n'en a aucun besoin. Les envoyer quand même, c'était
     * payer le catalogue deux fois par message — mesuré : 131 653 octets au premier
     * appel contre 15 432 au second, soit 88 % de moins.
     */
    public function testLaPhaseDeRedactionNeDeclareAucunOutil(): void
    {
        $declarations = [];
        $http = new MockHttpClient(function ($method, $url, $options) use (&$declarations) {
            $corps = json_decode($options['body'], true);
            $declarations[] = $corps['tools'] ?? null;

            // 1er appel : le modèle demande un outil. 2e : il rédige.
            return new MockResponse(json_encode(count($declarations) === 1
                ? $this->tourAvecOutil(1000)
                : self::texte('Vous avez 3 clients.')));
        });

        $tool = $this->makeTool(AiToolResult::ok(['count' => 3]));
        $reply = $this->makeEngine($http, [$tool])->reply($this->makeRequest('Combien de clients ?'));

        $this->assertCount(2, $declarations, 'Un message = deux appels, ni plus ni moins.');
        $this->assertNotNull($declarations[0], 'La PLANIFICATION doit déclarer les outils.');
        $this->assertNotEmpty($declarations[0][0]['functionDeclarations'] ?? []);
        $this->assertNull($declarations[1], 'La RÉDACTION ne doit porter aucune clé « tools ».');
        $this->assertSame('Vous avez 3 clients.', $reply->content);
    }

    /**
     * Un appel d'outil pendant la RÉDACTION ne peut être qu'une hallucination : rien
     * ne lui a été déclaré. On ne l'exécute pas, et surtout on ne relance pas — ce
     * serait le troisième appel. L'utilisateur repart avec ce qui a déjà été obtenu.
     */
    public function testUnOutilReclameEnRedactionNEstNiExecuteNiRelance(): void
    {
        $appels = 0;
        $http = new MockHttpClient(function () use (&$appels) {
            ++$appels;

            return new MockResponse(json_encode($this->tourAvecOutil(1000)));
        });

        $tool = $this->makeTool(AiToolResult::ok(['count' => 3]));
        $this->makeEngine($http, [$tool])->reply($this->makeRequest('Combien de clients ?'));

        $this->assertSame(2, $appels);
        // Un seul outil EXÉCUTÉ : celui de la planification. Le journal du message
        // fait foi — la ligne de tour, elle, enregistre aussi l'appel halluciné,
        // et c'est très bien : on veut savoir qu'il a eu lieu.
        $bilan = array_values(array_filter(
            $this->telemetrie,
            static fn (array $l) => ($l['context']['evenement'] ?? null) === 'message',
        ));
        $this->assertCount(1, $bilan[0]['context']['sequenceOutils']);
    }

    private function contextBuilderFige(): AiContextBuilder
    {
        $builder = $this->createMock(AiContextBuilder::class);
        $builder->method('toSystemPrompt')->willReturn('SYSTEM');

        return $builder;
    }

    /** Doublure d'un outil d'écriture : il porte le marqueur, donc la trousse le range. */
    private function makeOutilDEcriture(): AiToolInterface
    {
        return new class implements AiToolInterface, AiToolEcriture {
            public function name(): string
            {
                return 'preparer_operations';
            }

            public function description(): string
            {
                return 'Prépare un plan.';
            }

            public function aiguillage(): string
            {
                return '';
            }

            public function schema(): array
            {
                return ['type' => 'object', 'properties' => new \stdClass()];
            }

            public function match(string $question, AiScope $scope): ?array
            {
                return null;
            }

            public function execute(array $args, AiScope $scope): AiToolResult
            {
                return AiToolResult::ok(['pret' => true]);
            }
        };
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

    /** @var list<array{message: string, context: array}> lignes de télémétrie captées */
    private array $telemetrie = [];

    /** @var list<int> secondes que le moteur a DEMANDÉ d'attendre (jamais dormies) */
    private array $attentes = [];

    /**
     * Compteur de débit vierge, horloge figée. Une suite de tests ne doit ni
     * dormir ni dépendre du pool de cache réel : on vérifie la DÉCISION du
     * moteur, pas la capacité de PHP à attendre.
     */
    private function makeBudget(int $plafond = 250000, int $instant = 1_000_000): BudgetDebit
    {
        return new BudgetDebit(
            new ArrayAdapter(),
            $plafond,
            0.0, // marge neutralisée : les seuils du test doivent rester lisibles
            static fn (): int => $instant,
        );
    }

    private function makeEngine(
        MockHttpClient $http,
        array $tools = [],
        ?BudgetDebit $budget = null,
        ?\Closure $dormir = null,
    ): GeminiAiEngine {
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
            new TrousseCatalogue($tools),
            $this->selecteurFige(),
            $tools,
            'gm-test',
            'gemini-2.5-flash',
            new NullLogger(),
            new JournalTokens($espion, new OutilsDePlan([])),
            $budget ?? $this->makeBudget(),
            function (int $secondes) use ($dormir): void {
                $this->attentes[] = $secondes;
                if ($dormir !== null) {
                    $dormir($secondes);
                }
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

            public function aiguillage(): string
            {
                return '';
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

    /** Un tour de function calling qui redemande toujours le même outil. */
    private function tourAvecOutil(int $tokensEntree): array
    {
        return [
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [
                    ['functionCall' => ['name' => 'compter_entites', 'args' => ['entite' => 'Client']]],
                ]],
            ]],
            'usageMetadata' => ['promptTokenCount' => $tokensEntree],
        ];
    }

    /**
     * L'API étant sans mémoire, chaque tour réexpédie tout le contexte : un
     * enchaînement d'outils finit par épuiser le débit autorisé sur la minute
     * (429, tour perdu ET déjà débité au compte de l'utilisateur). Le moteur doit
     * s'arrêter avant le mur — mais d'après le débit RÉELLEMENT consommé sur la
     * fenêtre glissante, jamais d'après un cap par message : c'est ce cap-là qui,
     * le 2026-08-08, a refusé 10 messages sur 23 alors que le fournisseur aurait
     * laissé passer.
     */
    public function testLaBoucleSArreteQuandLeDebitDeLaMinuteEstEpuise(): void
    {
        // Le garde-fou reste actif, mais il faut désormais une VRAIE raison de
        // renoncer : la rédaction ne portant plus le catalogue, elle est bon marché.
        // Ici 200 000 tokens à la planification ET un résultat d'outil volumineux
        // (un export, une longue liste) qui rendrait la rédaction hors de portée.
        $appels = 0;
        $http = new MockHttpClient(function () use (&$appels) {
            ++$appels;

            return new MockResponse(json_encode($this->tourAvecOutil(200000)));
        });

        $tool = $this->makeTool(AiToolResult::ok(['lignes' => str_repeat('x', 300000)]));
        $reply = $this->makeEngine($http, [$tool])->reply($this->makeRequest('Analyse tout mon portefeuille'));

        $this->assertSame(1, $appels, 'La boucle doit cesser d\'appeler l\'API dès le débit épuisé.');
        $this->assertStringContainsString('limite de débit', $reply->content);
        // Le refus ne doit plus accuser l'utilisateur d'avoir mal posé sa question.
        $this->assertStringNotContainsString('Découpez', $reply->content);
        $this->assertSame([], $this->attentes, 'Une attente d\'une minute entière ne doit jamais être tentée.');
    }

    /**
     * La fenêtre est saturée mais se libère dans quelques secondes. Jeter là un
     * tour DÉJÀ facturé pour rendre une non-réponse est le pire des choix — Ket
     * patiente et termine. Le tour de formulation compte autant que les autres :
     * un plan préparé qu'on ne sait plus annoncer ne vaut rien pour l'utilisateur.
     */
    public function testLeMoteurPatienteBrievementPuisTermineLaChaine(): void
    {
        // Horloge pilotée : chaque aller-retour HTTP consomme 50 s, et l'attente
        // fait avancer le temps d'autant. Aucun sleep réel n'a lieu.
        $instant = 1_000_000;
        $budget = new BudgetDebit(
            new ArrayAdapter(),
            100000,
            0.0,
            static function () use (&$instant): int { return $instant; },
        );
        // Une consommation ANTÉRIEURE occupe déjà la fenêtre — c'est le cas réel :
        // le débit est partagé par tout le cabinet, pas propre à ce message. Elle
        // sortira de la minute dans 11 s, d'où une attente brève et non un abandon.
        $budget->enregistrer('gemini-2.5-flash', 50000);

        $appels = 0;
        $http = new MockHttpClient(function () use (&$appels, &$instant) {
            $instant += 50;
            ++$appels;

            // Le premier tour appelle l'outil, le second conclut : c'est tout ce
            // qu'un message peut désormais coûter.
            return new MockResponse(json_encode($appels < 2
                ? $this->tourAvecOutil(40000)
                : [
                    'candidates'    => [['content' => ['role' => 'model', 'parts' => [['text' => 'Voici le bilan.']]]]],
                    'usageMetadata' => ['promptTokenCount' => 40000],
                ]));
        });

        // Résultat volumineux : c'est lui qui rend la rédaction assez chère pour
        // qu'elle doive attendre. Sans cela elle passerait sans encombre — ce qui
        // est justement l'intérêt de ne plus lui envoyer le catalogue d'outils.
        $tool = $this->makeTool(AiToolResult::ok(['lignes' => str_repeat('x', 40000)]));
        $reply = $this->makeEngine(
            $http,
            [$tool],
            $budget,
            static function (int $secondes) use (&$instant): void { $instant += $secondes; },
        )->reply($this->makeRequest('Analyse tout mon portefeuille'));

        $this->assertSame(2, $appels, 'La chaîne doit reprendre après l\'attente, pas s\'interrompre.');
        $this->assertSame('Voici le bilan.', $reply->content);
        $this->assertCount(1, $this->attentes, 'Une seule attente était nécessaire.');
        $this->assertLessThanOrEqual(15, $this->attentes[0], 'L\'attente doit rester brève (un seul worker en dev).');

        $attentes = array_filter(
            $this->telemetrie,
            static fn (array $l) => ($l['context']['evenement'] ?? null) === 'attente',
        );
        $this->assertCount(1, $attentes, 'Toute attente doit être mesurable dans la campagne.');
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
        int $tailleDuResultat = 10,
    ): void {
        $http = new MockHttpClient($reponses);
        // La taille du résultat d'outil fait partie du scénario : la rédaction ne
        // portant plus le catalogue, seule une sortie volumineuse peut encore la
        // rendre inabordable.
        $tool = $this->makeTool(AiToolResult::ok(['lignes' => str_repeat('x', $tailleDuResultat)]));

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

        // 200 000 tokens à la planification ET un résultat d'outil volumineux : la
        // rédaction, pourtant bon marché, ne tient plus dans les 212 500 utilisables.
        // Le moteur s'arrête donc après le tour d'outils — un seul aura été payé.
        yield 'débit de la minute épuisé' => [
            static fn () => new MockResponse(json_encode($appelOutil(200000))),
            JournalTokens::ISSUE_BUDGET_ATTEINT,
            1,
            300000,
        ];

        // « Tours épuisés » n'a plus de jeu de données : la boucle ne pouvant plus
        // compter que deux phases, il n'y a plus de tours à épuiser. Le cas où le
        // modèle redemande un outil en RÉDACTION est couvert par
        // testUnOutilReclameEnRedactionNEstNiExecuteNiRelance — on ne relance pas,
        // on rend ce qui a été obtenu.
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
            // Un tour d'outils, puis la formulation : le maximum désormais possible.
            return ++$appels <= 1
                ? new MockResponse(json_encode($appelOutil))
                : new MockResponse(json_encode(self::texte('Vous avez 3 clients.')));
        });

        $tool = $this->makeTool(AiToolResult::ok(['count' => 3]));
        $reply = $this->makeEngine($http, [$tool])->reply($this->makeRequest('Combien de clients ?'));

        $this->assertSame('Vous avez 3 clients.', $reply->content);
        $this->assertSame(2, $appels);
    }
}
