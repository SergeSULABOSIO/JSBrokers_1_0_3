<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Ai\AiRequest;
use App\Ai\Comprehension\Comprehenseur;
use App\Ai\Comprehension\DemandeComprise;
use App\Ai\Debit\BudgetDebit;
use App\Ai\Engine\DialecteGemini;
use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Mutation\OutilsDePlan;
use App\Ai\Programme\ProgrammeEnCours;
use App\Ai\Scope\AiScope;
use App\Ai\Telemetrie\JournalTokens;
use App\Ai\Tool\AiToolInterface;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\ExecuteurDOutils;
use App\Ai\Trousse\AiToolDeComprehension;
use App\Ai\Trousse\TrousseCatalogue;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Repository\AssistantProgrammeRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * LA PHASE DE COMPRÉHENSION — le premier des trois appels.
 *
 * Ce qui est verrouillé ici tient en une phrase : elle doit AIDER ou DISPARAÎTRE.
 * Elle peut vérifier en base pour lever une ambiguïté, elle peut arrêter un message
 * qu'elle n'a pas compris — mais elle ne doit JAMAIS empêcher une réponse d'exister,
 * ni laisser passer une reformulation qui invente des chiffres.
 */
class ComprehenseurTest extends TestCase
{
    /** @var list<array{nom: string, args: array}> */
    private array $outilsAppeles = [];

    /** Appels HTTP réellement émis par la phase — la mesure qui compte ici. */
    private int $appelsHttp = 0;

    private function scope(?AssistantConversation $conversation = null): AiScope
    {
        return new AiScope(new Entreprise(), new Invite(), $conversation);
    }

    private function requete(string $message, ?AssistantConversation $conversation = null): AiRequest
    {
        return new AiRequest(
            systemContext: [
                'assistantNom'  => 'Ket',
                'entrepriseNom' => 'Courtage Test',
                'perimetre'     => ['owner' => true],
                'date'          => '2026-08-14',
                'monnaie'       => 'USD',
            ],
            messages: [['role' => 'user', 'content' => $message]],
            scope: $this->scope($conversation),
        );
    }

    /** Une réponse Gemini portant le JSON de compréhension. */
    private static function json(array $sortie): MockResponse
    {
        return new MockResponse(json_encode([
            'candidates'    => [['content' => ['parts' => [['text' => json_encode($sortie, JSON_THROW_ON_ERROR)]]]]],
            'usageMetadata' => ['promptTokenCount' => 400],
        ], JSON_THROW_ON_ERROR));
    }

    /** Une réponse Gemini portant un appel d'outil. */
    private static function appelDOutil(string $nom, array $args = []): MockResponse
    {
        return new MockResponse(json_encode([
            'candidates'    => [['content' => ['parts' => [['functionCall' => ['name' => $nom, 'args' => $args]]]]]],
            'usageMetadata' => ['promptTokenCount' => 500],
        ], JSON_THROW_ON_ERROR));
    }

    private function outil(AiToolResult $resultat): AiToolInterface
    {
        return new class($resultat, $this->outilsAppeles) implements AiToolInterface, AiToolDeComprehension {
            public function __construct(private AiToolResult $resultat, private array &$appels)
            {
            }

            public function name(): string
            {
                return 'rechercher_entites';
            }

            public function description(): string
            {
                return 'Recherche des enregistrements.';
            }

            public function aiguillage(): string
            {
                return 'Appelle-moi pour vérifier qu’un nom désigne bien un enregistrement.';
            }

            public function schema(): array
            {
                return ['type' => 'object', 'properties' => ['filtre' => ['type' => 'string']]];
            }

            public function match(string $question, AiScope $scope): ?array
            {
                return null;
            }

            public function execute(array $args, AiScope $scope): AiToolResult
            {
                $this->appels[] = ['nom' => $this->name(), 'args' => $args];

                return $this->resultat;
            }
        };
    }

    /**
     * @param list<MockResponse> $reponses
     */
    private function comprehenseur(array $reponses, array $outils = []): Comprehenseur
    {
        $this->appelsHttp = 0;
        $http = new MockHttpClient(function () use ($reponses): MockResponse {
            $reponse = $reponses[$this->appelsHttp] ?? throw new \RuntimeException('Appel HTTP non prévu par le test.');
            ++$this->appelsHttp;

            return $reponse;
        });

        $contextBuilder = $this->createMock(AiContextBuilder::class);
        $contextBuilder->method('toSystemPrompt')->willReturn('SYSTEM');

        return new Comprehenseur(
            $http,
            $contextBuilder,
            new ProgrammeEnCours(
                $this->createMock(AssistantProgrammeRepository::class),
                $this->createMock(EntityManagerInterface::class),
            ),
            new DialecteGemini(new TrousseCatalogue($outils)),
            new ExecuteurDOutils($outils),
            new BudgetDebit(new ArrayAdapter()),
            new JournalTokens(new NullLogger(), new OutilsDePlan([])),
            new NullLogger(),
            'gm-test',
            'gemini-flash-lite-test',
        );
    }

    public function testUneDemandeClaireTransmetSonIntention(): void
    {
        $comprise = $this->comprehenseur([
            self::json(['claire' => true, 'intention' => 'Compter les clients du portefeuille']),
        ])->comprendre($this->requete('combien de clients ?'));

        self::assertTrue($comprise->claire);
        self::assertSame('Compter les clients du portefeuille', $comprise->intention);
        self::assertSame(DemandeComprise::ORIGINE_MODELE, $comprise->origine);
    }

    public function testUneDemandeAmbigueRendSesPointsATrancher(): void
    {
        $comprise = $this->comprehenseur([
            self::json([
                'claire'    => false,
                'intention' => 'Renouveler la police de Kibali',
                'questions' => ['Laquelle : la police auto ou la police incendie ?'],
            ]),
        ])->comprendre($this->requete('renouvelle sa police'));

        self::assertFalse($comprise->claire);
        self::assertSame(['Laquelle : la police auto ou la police incendie ?'], $comprise->questions);
        // La bulle est composée par le SERVEUR, pas par le modèle : une seule plume.
        // Et elle PARLE — pas d'accusé de réception (« Ce que je comprends : »), qui
        // est précisément le tic banni du prompt le 2026-08-14.
        $bulle = $comprise->texteDeClarification();
        self::assertStringContainsString('Renouveler la police de Kibali', $bulle);
        self::assertStringContainsString('Laquelle : la police auto', $bulle);
        self::assertStringNotContainsString('Ce que je comprends', $bulle);
    }

    /**
     * UN SEUL TOUR D'OUTILS, et le résultat sert bien à conclure.
     *
     * C'est ce qui permet de ne PAS poser une question qu'une recherche tranche :
     * le comprenant vérifie que le nom dicté désigne un enregistrement, puis conclut.
     */
    public function testLeComprenantPeutVerifierEnBaseAvantDeConclure(): void
    {
        $outil = $this->outil(AiToolResult::ok(['items' => [['id' => 7, 'libelle' => 'Kibali Gold']]]));

        $comprehenseur = $this->comprehenseur([
            self::appelDOutil('rechercher_entites', ['filtre' => 'Kibali']),
            self::json(['claire' => true, 'intention' => 'Lister les polices du client Kibali Gold']),
        ], [$outil]);

        $comprise = $comprehenseur->comprendre($this->requete('les polices de Kibali'));

        self::assertSame(2, $this->appelsHttp, 'Un tour d’outils = deux appels, jamais davantage.');
        self::assertSame([['nom' => 'rechercher_entites', 'args' => ['filtre' => 'Kibali']]], $this->outilsAppeles);
        self::assertTrue($comprise->claire);
        self::assertSame('Lister les polices du client Kibali Gold', $comprise->intention);
    }

    /**
     * LE GARDE ANTI-DÉRIVE. Un modèle qui reformule invente des chiffres — c'est la
     * faute du « budget fabriqué », sous une autre forme. Une intention qui porte un
     * montant absent du fil est ÉCARTÉE en entier : mieux vaut la demande brute, que
     * personne n'a réécrite.
     */
    public function testUneReformulationQuiInventeUnChiffreEstEcartee(): void
    {
        $comprise = $this->comprehenseur([
            self::json(['claire' => true, 'intention' => 'Enregistrer une prime de 12 000 USD']),
        ])->comprendre($this->requete('enregistre la prime de cette police'));

        self::assertTrue($comprise->claire);
        self::assertSame('enregistre la prime de cette police', $comprise->intention);
        self::assertSame(DemandeComprise::ORIGINE_REPLI, $comprise->origine);
    }

    /** Un montant RÉELLEMENT dicté n'est évidemment pas une invention. */
    public function testUnMontantDicteTraverseLaReformulation(): void
    {
        $comprise = $this->comprehenseur([
            self::json(['claire' => true, 'intention' => 'Enregistrer une prime de 12000 USD sur la police']),
        ])->comprendre($this->requete('la prime passe à 12 000, enregistre-la'));

        self::assertSame('Enregistrer une prime de 12000 USD sur la police', $comprise->intention);
        self::assertSame(DemandeComprise::ORIGINE_MODELE, $comprise->origine);
    }

    /**
     * @dataProvider sortiesInexploitables
     */
    public function testUneSortieInexploitableLaisseLaDemandePasser(MockResponse $reponse): void
    {
        $comprise = $this->comprehenseur([$reponse])->comprendre($this->requete('combien de clients ?'));

        self::assertTrue($comprise->claire, 'Le comprenant ne doit JAMAIS empêcher une réponse d’exister.');
        self::assertSame('combien de clients ?', $comprise->intention);
        self::assertSame(DemandeComprise::ORIGINE_REPLI, $comprise->origine);
    }

    public static function sortiesInexploitables(): iterable
    {
        yield 'JSON illisible' => [new MockResponse(json_encode([
            'candidates' => [['content' => ['parts' => [['text' => 'pas du JSON du tout']]]]],
        ], JSON_THROW_ON_ERROR))];

        yield 'intention vide' => [self::json(['claire' => true, 'intention' => '   '])];

        yield 'panne du fournisseur' => [new MockResponse('', ['http_code' => 500])];
    }

    /**
     * Le tour qui porte les outils ne peut pas imposer de schéma de sortie (Gemini
     * refuse les deux ensemble) : son JSON arrive parfois enveloppé de markdown.
     * Refuser une réponse juste pour trois caractères de décoration serait absurde.
     */
    public function testUnJsonEnveloppeDeMarkdownResteLisible(): void
    {
        $reponse = new MockResponse(json_encode([
            'candidates' => [['content' => ['parts' => [[
                'text' => "```json\n{\"claire\":true,\"intention\":\"Compter les clients\"}\n```",
            ]]]]],
        ], JSON_THROW_ON_ERROR));

        $comprise = $this->comprehenseur([$reponse])->comprendre($this->requete('combien de clients ?'));

        self::assertSame('Compter les clients', $comprise->intention);
    }

    /**
     * LES COURT-CIRCUITS : le serveur sait déjà, aucun appel n'est émis.
     *
     * @dataProvider filsQueLeServeurSaitDejaLire
     */
    public function testLeServeurNInterrogePasLeModeleQuandIlSaitDeja(AssistantConversation $conversation, string $message): void
    {
        $comprise = $this->comprehenseur([])->comprendre($this->requete($message, $conversation));

        self::assertSame(0, $this->appelsHttp, 'Aucun appel ne doit partir quand l’état du fil suffit.');
        self::assertTrue($comprise->claire);
        self::assertSame(DemandeComprise::ORIGINE_COURT_CIRCUIT, $comprise->origine);
    }

    public static function filsQueLeServeurSaitDejaLire(): iterable
    {
        $avecPlan = new AssistantConversation();
        $avecPlan->addMessage((new AssistantMessage())
            ->setRole(AssistantMessage::ROLE_ASSISTANT)
            ->setContenu('Voici le plan.')
            ->setMeta(['mutationPlan' => ['plan' => []]]));
        yield 'un plan attend une décision' => [$avecPlan, 'je confirme'];

        // GARDE ANTI-BOUCLE : on ne clarifie jamais deux fois de suite.
        $avecClarification = new AssistantConversation();
        $avecClarification->addMessage((new AssistantMessage())
            ->setRole(AssistantMessage::ROLE_ASSISTANT)
            ->setContenu('Ce que je comprends : …')
            ->setMeta(['clarification' => ['intention' => 'Renouveler la police']]));
        yield 'le tour précédent était déjà une clarification' => [$avecClarification, 'plutôt la police auto'];

        $avecReponse = new AssistantConversation();
        $avecReponse->addMessage((new AssistantMessage())
            ->setRole(AssistantMessage::ROLE_ASSISTANT)
            ->setContenu('Souhaitez-vous que je l’enregistre ?'));
        yield 'un simple acquiescement' => [$avecReponse, 'oui'];
        yield 'un « vas-y »' => [$avecReponse, 'vas-y'];
    }

    /**
     * Un plan en attente doit bien être vu comme tel — sinon le court-circuit
     * ci-dessus ne prouverait rien. On vérifie la règle canonique elle-même.
     */
    public function testLaConversationDuJeuDEssaiPorteBienUnPlanEnAttente(): void
    {
        [$conversation] = iterator_to_array(self::filsQueLeServeurSaitDejaLire())['un plan attend une décision'];

        self::assertTrue(PlanEnAttente::aUnPlanEnAttente($conversation));
    }
}
