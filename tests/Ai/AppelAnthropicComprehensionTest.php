<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Ai\AiRequest;
use App\Ai\Comprehension\AppelAnthropic;
use App\Ai\Debit\BudgetDebit;
use App\Ai\Fournisseur\MemoireDEpuisement;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolInterface;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\ExecuteurDOutils;
use App\Ai\Trousse\AiToolDeComprehension;
use App\Ai\Trousse\TrousseCatalogue;
use App\Entity\Entreprise;
use App\Entity\Invite;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * LA PHASE DE COMPRÉHENSION CHEZ ANTHROPIC.
 *
 * Ce que ce fichier vérifie, et que la version Google ne peut pas vérifier : la
 * conclusion arrive par un OUTIL, donc en JSON valide par construction, et elle
 * coexiste dans la même requête avec les outils de levée d'ambiguïté. Chez
 * Google, le proto refuse les deux ensemble et impose un second aller-retour dès
 * que le comprenant veut vérifier un nom — c'est le seul endroit de ce chantier
 * où Anthropic fait structurellement mieux.
 *
 * Les invariants de la phase elle-même (fail-open, garde anti-chiffre inventé,
 * court-circuit quand le serveur sait déjà) vivent dans Comprehenseur et sont
 * couverts par ComprehenseurTest : ils ne dépendent d'aucun fournisseur.
 */
class AppelAnthropicComprehensionTest extends TestCase
{
    /** @var list<array> corps des requêtes réellement envoyées */
    private array $corps = [];

    /** @var list<array{nom: string, args: array}> outils réellement exécutés */
    private array $outilsAppeles = [];

    private function requete(string $message): AiRequest
    {
        return new AiRequest(
            systemContext: [
                'assistantNom'  => 'Ket',
                'entrepriseNom' => 'PHPUnit Compréhension SARL',
                'perimetre'     => [],
                'date'          => '2026-09-22',
            ],
            messages: [['role' => 'user', 'content' => $message]],
            scope: new AiScope(new Entreprise(), new Invite()),
        );
    }

    /** Un outil de levée d'ambiguïté, le seul genre déclaré à cette phase. */
    private function outilDAmbiguite(): AiToolInterface
    {
        return new class($this->outilsAppeles) implements AiToolInterface, AiToolDeComprehension {
            /** @param list<array{nom: string, args: array}> $journal */
            public function __construct(private array &$journal)
            {
            }

            public function name(): string
            {
                return 'rechercher_entites';
            }

            public function description(): string
            {
                return 'Cherche des enregistrements.';
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
                return null;
            }

            public function execute(array $args, AiScope $scope): AiToolResult
            {
                $this->journal[] = ['nom' => $this->name(), 'args' => $args];

                return AiToolResult::ok(['totalItems' => 1, 'items' => [['libelle' => 'Kibali SARL']]]);
            }
        };
    }

    /** La conclusion, telle que le modèle la rend : par appel d'outil, jamais en prose. */
    private static function conclusion(array $input, int $entree = 400): MockResponse
    {
        return new MockResponse(json_encode([
            'content' => [[
                'type'  => 'tool_use',
                'id'    => 'tu_conclusion',
                'name'  => 'conclure_comprehension',
                'input' => $input,
            ]],
            'stop_reason' => 'tool_use',
            'usage'       => ['input_tokens' => $entree, 'output_tokens' => 40],
        ], JSON_THROW_ON_ERROR));
    }

    private static function leveeDAmbiguite(): MockResponse
    {
        return new MockResponse(json_encode([
            'content' => [[
                'type'  => 'tool_use',
                'id'    => 'tu_recherche',
                'name'  => 'rechercher_entites',
                'input' => ['entite' => 'Client'],
            ]],
            'stop_reason' => 'tool_use',
            'usage'       => ['input_tokens' => 350, 'output_tokens' => 20],
        ], JSON_THROW_ON_ERROR));
    }

    private function appel(array $reponses, array $outils = [], ?BudgetDebit $budget = null): AppelAnthropic
    {
        $i = 0;
        $http = new MockHttpClient(function (string $methode, string $url, array $options) use ($reponses, &$i): MockResponse {
            $this->corps[] = json_decode((string) $options['body'], true);

            return $reponses[$i++] ?? throw new \RuntimeException('Appel HTTP non prévu par le test.');
        });

        $contextBuilder = $this->createMock(AiContextBuilder::class);
        $contextBuilder->method('toSystemPrompt')->willReturn('PROMPT DE COMPRÉHENSION');

        return new AppelAnthropic(
            $http,
            $contextBuilder,
            new TrousseCatalogue($outils),
            new ExecuteurDOutils($outils),
            $budget ?? new BudgetDebit(new ArrayAdapter()),
            new MemoireDEpuisement(new ArrayAdapter()),
            'sk-ant-test',
            'claude-haiku-4-5',
        );
    }

    /**
     * LE CAS COURANT, ET LE GAIN. Le modèle conclut du premier coup : un seul
     * aller-retour, là où le dialecte de Google en impose deux dès qu'un outil
     * entre en jeu.
     */
    public function testUneConclusionDirecteNeCouteQuUnAppel(): void
    {
        $resultat = $this->appel([
            self::conclusion(['claire' => true, 'intention' => 'Compter les clients du portefeuille.', 'questions' => []]),
        ])->conclure($this->requete('combien de clients ?'));

        $this->assertCount(1, $this->corps);
        $this->assertSame(
            ['claire' => true, 'intention' => 'Compter les clients du portefeuille.', 'questions' => []],
            json_decode($resultat['texte'], true),
        );
        $this->assertSame(400, $resultat['tokens']);
    }

    /**
     * LA SORTIE EST STRUCTURÉE PAR UN OUTIL, et le modèle n'a pas le droit de
     * répondre autrement : `tool_choice` le lui interdit. Sans cela on retomberait
     * sur l'épluchage de clôtures markdown que la version Google doit encore faire.
     */
    public function testLaSortieEstImposeeParUnOutilEtLeTexteLibreInterdit(): void
    {
        $this->appel([self::conclusion(['claire' => true, 'intention' => 'X', 'questions' => []])])
            ->conclure($this->requete('combien de clients ?'));

        $corps = $this->corps[0];
        $this->assertSame(['type' => 'any'], $corps['tool_choice']);

        $conclusion = null;
        foreach ($corps['tools'] as $outil) {
            if ($outil['name'] === 'conclure_comprehension') {
                $conclusion = $outil;
            }
        }
        $this->assertNotNull($conclusion, "L'outil de conclusion doit toujours être déclaré.");
        $this->assertTrue($conclusion['strict']);
        $this->assertFalse($conclusion['input_schema']['additionalProperties'],
            '« strict » exige additionalProperties: false — sans lui, le schéma n’est pas contraignant.');
    }

    /**
     * L'AVANTAGE STRUCTUREL : outils de recherche ET outil de conclusion dans la
     * MÊME requête. Chez Google, les deux s'excluent.
     */
    public function testOutilsDAmbiguiteEtConclusionCoexistentDansLaMemeRequete(): void
    {
        $this->appel(
            [self::conclusion(['claire' => true, 'intention' => 'X', 'questions' => []])],
            [$this->outilDAmbiguite()],
        )->conclure($this->requete('les polices de Kibali'));

        $noms = array_column($this->corps[0]['tools'], 'name');
        $this->assertContains('conclure_comprehension', $noms);
        $this->assertContains('rechercher_entites', $noms);
    }

    /**
     * UN TOUR D'OUTILS, PAS DEUX. Le comprenant peut vérifier en base — sans quoi
     * il poserait une question là où une recherche aurait tranché — mais il ne
     * CHAÎNE pas : c'est l'enchaînement, et lui seul, qui a saturé le quota le
     * 2026-08-10.
     */
    public function testLeComprenantPeutVerifierEnBasePuisConclut(): void
    {
        $resultat = $this->appel(
            [self::leveeDAmbiguite(), self::conclusion(['claire' => true, 'intention' => 'Lister les polices de Kibali SARL.', 'questions' => []], 500)],
            [$this->outilDAmbiguite()],
        )->conclure($this->requete('les polices de Kibali'));

        $this->assertCount(2, $this->corps, 'Une vérification, puis la conclusion. Jamais trois.');
        $this->assertSame([['nom' => 'rechercher_entites', 'args' => ['entite' => 'Client']]], $this->outilsAppeles);
        $this->assertStringContainsString('Kibali SARL', $resultat['texte']);
        $this->assertSame(850, $resultat['tokens'], 'Les deux tours sont facturés au message.');

        // Le résultat repart par le canal que l'API exige : un tool_result apparié
        // à l'identifiant du tool_use. Sans cet appariement, la requête est rejetée.
        $dernier = end($this->corps[1]['messages']);
        $this->assertSame('tool_result', $dernier['content'][0]['type']);
        $this->assertSame('tu_recherche', $dernier['content'][0]['tool_use_id']);
    }

    /**
     * LE DÉBIT VA SUR LE COMPTEUR DU MODÈLE DE COMPRÉHENSION, pas sur celui de la
     * planification. C'est toute la raison d'utiliser un modèle à part : l'oublier
     * ferait de cette phase une ponction sur la fenêtre qu'elle est censée épargner.
     */
    public function testLeDebitVaSurLeCompteurDeLaComprehension(): void
    {
        $budget = new BudgetDebit(new ArrayAdapter(), 2000000, 0.0, static fn (): int => 1_000_000, ['anthropic:in:' => 2000000]);

        $this->appel(
            [self::conclusion(['claire' => true, 'intention' => 'X', 'questions' => []], 400)],
            [],
            $budget,
        )->conclure($this->requete('combien de clients ?'));

        $this->assertSame(2000000 - 400, $budget->restant('anthropic:in:claude-haiku-4-5'));
        $this->assertSame(2000000, $budget->restant('anthropic:in:claude-opus-5'),
            'Le compteur du modèle de planification doit rester intact.');
    }

    public function testSansCleLAppelSeDeclareIndisponible(): void
    {
        $contextBuilder = $this->createMock(AiContextBuilder::class);
        $sansCle = new AppelAnthropic(
            new MockHttpClient([]),
            $contextBuilder,
            new TrousseCatalogue([]),
            new ExecuteurDOutils([]),
            new BudgetDebit(new ArrayAdapter()),
            new MemoireDEpuisement(new ArrayAdapter()),
            '',
            'claude-haiku-4-5',
        );

        $this->assertFalse($sansCle->estDisponible());
    }
}
