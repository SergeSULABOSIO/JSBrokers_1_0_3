<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Ai\AiRequest;
use App\Ai\Comprehension\AppelGemini;
use App\Ai\Comprehension\Comprehenseur;
use App\Ai\Debit\BudgetDebit;
use App\Ai\Engine\AiEngineInterface;
use App\Ai\Engine\AnthropicAiEngine;
use App\Ai\Engine\AppelDOutilEnTexte;
use App\Ai\Engine\DialecteGemini;
use App\Ai\Engine\GeminiAiEngine;
use App\Ai\Fournisseur\MemoireDEpuisement;
use App\Ai\Mutation\OutilsDePlan;
use App\Ai\Presentation\TableauMarkdown;
use App\Ai\Programme\ProgrammeEnCours;
use App\Ai\Redaction\RepliPrecis;
use App\Ai\Scope\AiScope;
use App\Ai\Telemetrie\JournalTokens;
use App\Ai\Tool\AiToolInterface;
use App\Ai\Tool\AiToolProduisantUnPlan;
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
 * LE CONTRAT QUE LES DEUX MOTEURS DOIVENT HONORER À L'IDENTIQUE.
 *
 * POURQUOI CE FICHIER EXISTE. L'adaptateur Claude a divergé de celui de Gemini
 * pendant des mois sans que personne ne le voie : il lui manquait les phases, la
 * télémétrie, le compteur de débit, les chiffres des outils, le rattrapage de
 * l'appel écrit en prose — onze écarts. Aucun n'était visible, parce que ce moteur
 * ne tournait pas. Mais ANTHROPIC_API_KEY est PRIORITAIRE dans le résolveur : une
 * simple clé posée les ouvrait tous d'un coup.
 *
 * L'extraction d'un socle commun a fermé ces écarts. Ce fichier empêche qu'ils se
 * rouvrent. Il n'assertionne QUE sur ce que l'utilisateur constate — l'AiReply
 * rendue — et sur les lignes de journal ; ce qui est propre au format d'un
 * fournisseur reste testé dans son propre fichier.
 *
 * Même patron que ContratDesActionsTest et ContratDePresentationTest : un
 * invariant exécutable, pas une économie de lignes. Le jour où un garde-fou n'est
 * ajouté qu'à un seul moteur, c'est ici que ça rougit.
 */
class ContratDesMoteursTest extends TestCase
{
    private const MODELE = ['gemini' => 'gemini-3.1-flash-lite', 'anthropic' => 'claude-haiku-4-5'];

    /** @var list<array{message: string, context: array}> lignes de télémétrie captées */
    private array $telemetrie = [];

    /** @var list<array> corps des requêtes envoyées au moteur (jamais au comprenant) */
    private array $corps = [];

    /** @return iterable<string, array{0: string}> */
    public static function moteurs(): iterable
    {
        yield 'gemini' => ['gemini'];
        yield 'anthropic' => ['anthropic'];
    }

    // ──────────────────────────────────────────────────────────────────────────────
    // Le nombre d'appels : la règle d'architecture, et le risque de panne
    // ──────────────────────────────────────────────────────────────────────────────

    /**
     * Le modèle n'orchestre pas, PHP orchestre. Quand le premier regard aboutit, un
     * message coûte DEUX appels — les outils, puis la formulation — quoi que le
     * modèle réclame ensuite.
     *
     * Sans ce verrou, la boucle revient d'un simple changement de constante et
     * personne ne le voit avant la prochaine saturation : le 2026-08-10, cinq tours
     * enchaînés ont consommé 188 000 tokens sur les 212 500 d'une minute et rendu la
     * conversation entière inutilisable — « salut » compris.
     *
     * @dataProvider moteurs
     */
    public function testUnMessageAboutiNeCouteQueDeuxAppels(string $quel): void
    {
        $http = $this->http($quel, [
            $this->appelDOutil($quel, 'compter_entites', ['entite' => 'Client']),
            $this->texte($quel, '34 clients.'),
            // Un troisième appel ferait échouer le harnais : c'est le verrou.
        ]);

        $reply = $this->moteur($quel, $http, [$this->outil(AiToolResult::ok(['total' => 34]))])
            ->reply($this->requete('combien de clients ?'));

        $this->assertSame('34 clients.', $reply->content);
        $this->assertSame(2, $http->getRequestsCount());
    }

    /**
     * LE TROISIÈME APPEL SE MÉRITE. « Quelle police porte la plus grosse prime ? »
     * se répond en deux temps : chercher, lire, chercher à nouveau. Sans ce second
     * regard, Ket répondait « je n'ai pas trouvé » et l'utilisateur relançait à la
     * main, au prix d'un message entier.
     *
     * Mais il ne se paie QUE là où le premier a buté — ici, une recherche vide.
     * Sans cette porte, chaque message réexpédierait les 72 Ko de déclarations
     * d'outils une fois de plus, pour un tour que le modèle n'a pas demandé.
     *
     * @dataProvider moteurs
     */
    public function testUnTroisiemeAppelNArriveQueQuandLePremierRegardABute(string $quel): void
    {
        $http = $this->http($quel, [
            $this->appelDOutil($quel, 'compter_entites', ['entite' => 'Client']),
            $this->appelDOutil($quel, 'compter_entites', ['entite' => 'Prospect']),
            $this->texte($quel, 'Rien sous ce nom, mais 3 prospects.'),
        ]);

        // Une recherche vide : exactement le cas où un second regard vaut son prix.
        $reply = $this->moteur($quel, $http, [$this->outil(AiToolResult::ok(['totalItems' => 0]))])
            ->reply($this->requete('les polices de Kibali'));

        $this->assertSame(3, $http->getRequestsCount(), 'Trois appels au maximum, jamais quatre.');
        $this->assertSame('Rien sous ce nom, mais 3 prospects.', $reply->content);
    }

    /**
     * LA PIÈCE MAÎTRESSE DE L'ÉCONOMIE : la rédaction ne reçoit AUCUNE déclaration
     * d'outil. Les 72 Ko du catalogue ne servent qu'à CHOISIR un outil ; commenter un
     * résultat déjà obtenu n'en a aucun besoin. Les envoyer quand même, c'était payer
     * le catalogue deux fois par message.
     *
     * Les deux fournisseurs nomment ce bloc « tools » : l'assertion est donc la même
     * des deux côtés sans rien savoir de leur dialecte.
     *
     * @dataProvider moteurs
     */
    public function testLaRedactionNeDeclareAucunOutil(string $quel): void
    {
        $http = $this->http($quel, [
            $this->appelDOutil($quel, 'compter_entites', ['entite' => 'Client']),
            $this->texte($quel, '34 clients.'),
        ]);

        $this->moteur($quel, $http, [$this->outil(AiToolResult::ok(['total' => 34]))])
            ->reply($this->requete('combien de clients ?'));

        $this->assertArrayHasKey('tools', $this->corps[0], 'La planification, elle, en a besoin.');
        $this->assertArrayNotHasKey('tools', $this->corps[1]);
    }

    // ──────────────────────────────────────────────────────────────────────────────
    // Ce qu'on rend à l'utilisateur quand le modèle ne rend rien
    // ──────────────────────────────────────────────────────────────────────────────

    /**
     * UN TOUR QUI NE REND RIEN est rejoué UNE fois, et une seule.
     *
     * Le mur du 2026-09-08 : sur « Invente pour moi des numéros. », le modèle a
     * dépensé 807 jetons en raisonnement interne sans émettre un mot, et
     * l'utilisateur a reçu « redites-la-moi en nommant le point précis ». Il venait
     * de le nommer trois fois.
     *
     * @dataProvider moteurs
     */
    public function testUnTourMuetEstRejoueUneFois(string $quel): void
    {
        $http = $this->http($quel, [$this->tourVide($quel), $this->texte($quel, 'Bonjour !')]);

        $reply = $this->moteur($quel, $http)->reply($this->requete('bonjour'));

        $this->assertSame(2, $http->getRequestsCount(), 'Une reprise, jamais deux.');
        $this->assertSame('Bonjour !', $reply->content);
    }

    /**
     * ON NE RENVOIE JAMAIS À L'UTILISATEUR UN TRAVAIL QUI EST LE NÔTRE. Quand le
     * modèle se tait alors que les outils ont rapporté quelque chose, on restitue ce
     * qu'ils ont trouvé — en PHP, à coût nul — au lieu de lui demander de préciser
     * une question qui était déjà précise.
     *
     * @dataProvider moteurs
     */
    public function testJamaisDePrecisezVotreQuestionQuandLesOutilsOntTravaille(string $quel): void
    {
        $http = $this->http($quel, [
            $this->appelDOutil($quel, 'compter_entites', ['entite' => 'Client']),
            // « aDemander » MÉRITE un second regard : ce tour-là est donc dû.
            $this->appelDOutil($quel, 'compter_entites', ['entite' => 'Client']),
            // Puis la rédaction se tait : c'est exactement le cas qui, autrefois,
            // renvoyait l'utilisateur à sa question.
            $this->tourVide($quel),
        ]);

        $reply = $this->moteur($quel, $http, [
            $this->outil(AiToolResult::ok(['aDemander' => 'Quelle échéance ?'])),
        ])->reply($this->requete('renouvelle la police de Kibali'));

        $this->assertStringNotContainsString('préciser votre question', $reply->content);
        $this->assertNotSame('', trim($reply->content));
    }

    // ──────────────────────────────────────────────────────────────────────────────
    // Les signaux que le contrôleur attend de la réponse
    // ──────────────────────────────────────────────────────────────────────────────

    /**
     * Un outil qui refuse pour cause de périmètre marque la réponse : c'est ce qui
     * distingue « il n'y a rien » de « vous n'y avez pas accès ».
     *
     * @dataProvider moteurs
     */
    public function testHorsPerimetreEstPropageEnRefus(string $quel): void
    {
        $http = $this->http($quel, [
            $this->appelDOutil($quel, 'compter_entites', ['entite' => 'Client']),
            $this->texte($quel, 'Cette donnée ne vous est pas accessible.'),
        ]);

        $reply = $this->moteur($quel, $http, [
            $this->outil(AiToolResult::horsPerimetre('Client')),
        ])->reply($this->requete('combien de clients ?'));

        $this->assertTrue($reply->refused);
    }

    /**
     * LE GARDE-FOU ANTI-PLAN FANTÔME. Un outil de plan qui tourne SANS produire de
     * plan doit être signalé : sans ce signal, rien ne distingue « le modèle a décrit
     * un plan inexistant » de « le modèle a répondu à une question ». C'est ainsi que
     * Ket a annoncé un enregistrement sous un bouton que personne n'avait touché.
     *
     * @dataProvider moteurs
     */
    public function testUnPlanRefuseEstSignaleAuControleur(string $quel): void
    {
        $http = $this->http($quel, [
            $this->appelDOutil($quel, 'preparer_operations', ['entite' => 'Client']),
            $this->texte($quel, 'Il me manque la date d’effet.'),
        ]);

        $reply = $this->moteur($quel, $http, [
            $this->outilDePlan(AiToolResult::ok(['pret' => false, 'aDemander' => 'la date d’effet'])),
        ])->reply($this->requete('crée le client Test SARL'));

        $this->assertCount(1, $reply->plansRefuses);
        $this->assertSame('preparer_operations', $reply->plansRefuses[0]['outil']);
    }

    /**
     * LES CHIFFRES DES OUTILS REMONTENT, et c'est ce qui permet au contrôleur de
     * repérer un montant que le modèle aurait inventé. Un garde-fou qui ne
     * s'appliquerait qu'à un moteur sur deux serait une divergence silencieuse —
     * exactement ce que ce fichier existe pour empêcher.
     *
     * @dataProvider moteurs
     */
    public function testLesChiffresDesOutilsRemontent(string $quel): void
    {
        $http = $this->http($quel, [
            $this->appelDOutil($quel, 'compter_entites', ['entite' => 'Client']),
            $this->texte($quel, '34 clients.'),
        ]);

        $reply = $this->moteur($quel, $http, [$this->outil(AiToolResult::ok(['total' => 34]))])
            ->reply($this->requete('combien de clients ?'));

        $this->assertContains(34.0, $reply->chiffresDesOutils);
    }

    // ──────────────────────────────────────────────────────────────────────────────
    // La mesure : aucune sortie ne doit échapper à la campagne
    // ──────────────────────────────────────────────────────────────────────────────

    /**
     * Une demande déclinée par les garde-fous du fournisseur conclut proprement, et
     * sous sa propre issue : la confondre avec une réponse ordinaire rendrait la
     * campagne aveugle au seul cas où l'utilisateur n'obtient rien.
     *
     * @dataProvider moteurs
     */
    public function testUnBlocageDuFournisseurConclutEnBlocageSecurite(string $quel): void
    {
        $reply = $this->moteur($quel, $this->http($quel, [$this->blocage($quel)]))
            ->reply($this->requete('donne-moi des numéros inventés'));

        $this->assertTrue($reply->refused);
        $this->assertSame('blocage_securite', $this->bilan()['issue'] ?? null);
    }

    /**
     * CHAQUE CHEMIN DE SORTIE PRODUIT UN BILAN. Ce sont justement les sorties
     * anormales qui intéressent le plus la mesure : si l'une d'elles oublie de
     * conclure, c'est précisément le chiffre qu'on cherchait qui manque.
     *
     * @dataProvider moteurs
     */
    public function testChaqueCheminDeSortieProduitUnBilanDeMessage(string $quel): void
    {
        foreach (['reponse' => [$this->texte($quel, 'Bonjour !')], 'blocage_securite' => [$this->blocage($quel)]] as $issue => $reponses) {
            $this->telemetrie = [];
            $this->moteur($quel, $this->http($quel, $reponses))->reply($this->requete('bonjour'));

            $bilan = $this->bilan();
            $this->assertNotNull($bilan, sprintf('Le chemin « %s » ne conclut pas.', $issue));
            $this->assertSame($issue, $bilan['issue']);
            $this->assertSame($quel, $bilan['moteur']);
            $this->assertSame(self::MODELE[$quel], $bilan['modele']);
        }
    }

    /**
     * LA LIGNE DE TOUR DIT OÙ PARTENT LES TOKENS. C'est la seule façon de le savoir
     * sans payer un aller-retour de comptage : le fournisseur, lui, ne renvoie qu'un
     * total. Sans cette répartition, on ne peut pas décider quoi alléger.
     *
     * @dataProvider moteurs
     */
    public function testLaLigneDeTourPorteLaRepartitionDesOctets(string $quel): void
    {
        $this->moteur($quel, $this->http($quel, [$this->texte($quel, 'Bonjour !')]))
            ->reply($this->requete('bonjour'));

        $tour = $this->lignesDeTour()[0] ?? null;
        $this->assertNotNull($tour);
        foreach (['tokensEntree', 'tokensSortie', 'tokensCache', 'octetsSysteme', 'octetsOutils', 'octetsHistorique'] as $colonne) {
            $this->assertArrayHasKey($colonne, $tour);
        }
        $this->assertGreaterThan(0, $tour['octetsSysteme']);
    }

    // ──────────────────────────────────────────────────────────────────────────────
    // Harnais : deux dialectes, un seul jeu d'assertions
    // ──────────────────────────────────────────────────────────────────────────────

    private function requete(string $question): AiRequest
    {
        return new AiRequest(
            systemContext: [
                'assistantNom'  => 'Ket',
                'entrepriseNom' => 'PHPUnit Contrat SARL',
                'perimetre'     => ['owner' => true],
                'date'          => '2026-09-22',
            ],
            messages: [['role' => 'user', 'content' => $question]],
            scope: new AiScope(new Entreprise(), new Invite()),
        );
    }

    /** Client HTTP du MOTEUR : le comprenant a le sien, ses appels ne comptent pas ici. */
    private function http(string $quel, array $reponses): MockHttpClient
    {
        $this->corps = [];
        $i = 0;

        return new MockHttpClient(function (string $methode, string $url, array $options) use ($reponses, &$i): MockResponse {
            $this->corps[] = json_decode((string) $options['body'], true);

            return $reponses[$i++] ?? throw new \RuntimeException('Appel au moteur non prévu : le plafond d’appels est franchi.');
        });
    }

    private function moteur(string $quel, MockHttpClient $http, array $outils = []): AiEngineInterface
    {
        $contextBuilder = $this->createMock(AiContextBuilder::class);
        $contextBuilder->method('toSystemPrompt')->willReturn('PROMPT SYSTÈME');
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

        // ⚠ LES OUTILS DU TEST, et pas une liste vide : OutilsDePlan DÉRIVE sa liste
        // du marqueur AiToolProduisantUnPlan. Vide, il ne reconnaît aucun outil de
        // plan et le garde-fou anti-plan fantôme ne se déclenche jamais.
        $outilsDePlan = new OutilsDePlan($outils);
        $journal = new JournalTokens($espion, $outilsDePlan);
        $budget = new BudgetDebit(new ArrayAdapter(), 2_000_000, 0.0, static fn (): int => 1_000_000);
        $catalogue = new TrousseCatalogue($outils);
        $executeur = new ExecuteurDOutils($outils);
        $repli = new RepliPrecis(new TableauMarkdown(new ServiceNombres(new LocaleSwitcher('fr', []))));
        $comprenant = $this->comprenantNeutre($contextBuilder, $journal, $budget);
        $dormir = static function (int $s): void {};

        if ($quel === 'gemini') {
            return new GeminiAiEngine(
                $http,
                $contextBuilder,
                $catalogue,
                new DialecteGemini($catalogue),
                $this->selecteurFige(),
                $executeur,
                'gm-test',
                self::MODELE['gemini'],
                '', // aucun modèle de secours : ce contrat ne porte pas sur les replis
                new NullLogger(),
                $journal,
                $budget,
                $repli,
                new AppelDOutilEnTexte(),
                $outilsDePlan,
                $comprenant,
                $dormir,
            );
        }

        return new AnthropicAiEngine(
            $http,
            $contextBuilder,
            $catalogue,
            $this->selecteurFige(),
            $executeur,
            'sk-ant-test',
            self::MODELE['anthropic'],
            new NullLogger(),
            $journal,
            $budget,
            $repli,
            new AppelDOutilEnTexte(),
            $outilsDePlan,
            $comprenant,
            true,
            $dormir,
        );
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
     * COMPRENANT NEUTRE, sur son PROPRE client : il conclut toujours « claire ».
     * Ses appels ne doivent jamais entrer dans le compte du moteur, sans quoi le
     * verrou des deux appels ne mesurerait plus rien.
     */
    private function comprenantNeutre(AiContextBuilder $contextBuilder, JournalTokens $journal, BudgetDebit $budget): Comprehenseur
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode([
            'candidates'    => [['content' => ['parts' => [['text' => json_encode(
                ['claire' => true, 'intention' => 'Question de contrat'],
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

    // ── Réponses canned, un dialecte par fournisseur ─────────────────────────────

    private function texte(string $quel, string $texte): MockResponse
    {
        return $quel === 'gemini'
            ? self::reponseGemini([['text' => $texte]])
            : self::reponseAnthropic([['type' => 'text', 'text' => $texte]], 'end_turn');
    }

    private function appelDOutil(string $quel, string $nom, array $args): MockResponse
    {
        return $quel === 'gemini'
            ? self::reponseGemini([['functionCall' => ['name' => $nom, 'args' => $args]]])
            : self::reponseAnthropic([['type' => 'tool_use', 'id' => 'tu_1', 'name' => $nom, 'input' => $args]], 'tool_use');
    }

    private function tourVide(string $quel): MockResponse
    {
        return $quel === 'gemini'
            ? self::reponseGemini([])
            : self::reponseAnthropic([], 'end_turn');
    }

    private function blocage(string $quel): MockResponse
    {
        if ($quel === 'gemini') {
            return new MockResponse(json_encode([
                'promptFeedback' => ['blockReason' => 'SAFETY'],
                'usageMetadata'  => ['promptTokenCount' => 120, 'candidatesTokenCount' => 0],
            ], JSON_THROW_ON_ERROR));
        }

        return self::reponseAnthropic([], 'refusal');
    }

    private static function reponseGemini(array $parts): MockResponse
    {
        return new MockResponse(json_encode([
            'candidates'    => [['content' => ['parts' => $parts]]],
            'usageMetadata' => ['promptTokenCount' => 120, 'candidatesTokenCount' => 30, 'cachedContentTokenCount' => 0],
        ], JSON_THROW_ON_ERROR));
    }

    private static function reponseAnthropic(array $content, string $stopReason): MockResponse
    {
        return new MockResponse(json_encode([
            'content'     => $content,
            'stop_reason' => $stopReason,
            'usage'       => [
                'input_tokens'                => 120,
                'output_tokens'               => 30,
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens'     => 0,
            ],
        ], JSON_THROW_ON_ERROR));
    }

    // ── Outils factices ──────────────────────────────────────────────────────────

    private function outil(AiToolResult $resultat, string $nom = 'compter_entites'): AiToolInterface
    {
        return new class($resultat, $nom) implements AiToolInterface {
            public function __construct(private AiToolResult $resultat, private string $nom)
            {
            }

            public function name(): string
            {
                return $this->nom;
            }

            public function description(): string
            {
                return 'Outil de contrat.';
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
                return $this->resultat;
            }
        };
    }

    /** Le marqueur AiToolProduisantUnPlan est ce qui déclenche le garde-fou anti-plan fantôme. */
    private function outilDePlan(AiToolResult $resultat): AiToolInterface
    {
        return new class($resultat) implements AiToolInterface, AiToolProduisantUnPlan {
            public function __construct(private AiToolResult $resultat)
            {
            }

            public function name(): string
            {
                return 'preparer_operations';
            }

            public function description(): string
            {
                return 'Prépare un plan d’écriture.';
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
                return $this->resultat;
            }
        };
    }

    // ── Lecture du journal ───────────────────────────────────────────────────────

    private function bilan(): ?array
    {
        foreach ($this->telemetrie as $ligne) {
            if (($ligne['context']['evenement'] ?? null) === 'message') {
                return $ligne['context'];
            }
        }

        return null;
    }

    /** @return list<array> */
    private function lignesDeTour(): array
    {
        return array_values(array_filter(
            array_column($this->telemetrie, 'context'),
            static fn (array $c) => ($c['evenement'] ?? null) === 'tour',
        ));
    }
}
