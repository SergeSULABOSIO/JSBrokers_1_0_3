<?php

namespace App\Tests\Ai;

use App\Ai\AiContextBuilder;
use App\Ai\AiRequest;
use App\Ai\Comprehension\AppelGemini;
use App\Ai\Debit\BudgetDebit;
use App\Ai\Engine\DialecteGemini;
use App\Ai\Fournisseur\MemoireDEpuisement;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\ExecuteurDOutils;
use App\Ai\Trousse\TrousseCatalogue;
use App\Entity\Entreprise;
use App\Entity\Invite;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * LA CHAÎNE DE SECOURS DE LA PHASE DE COMPRÉHENSION — la cause des quatre replis
 * sur dix, et elle n'était pas dans la phase : elle en était ABSENTE.
 *
 * Le moteur basculait déjà sur un modèle de secours au premier 503 et continuait de
 * répondre. Cette phase-ci, non : elle échouait, et le message partait sans que
 * personne ait compris la demande. Mesuré sur les journaux au 2026-09-25 : 41 % de
 * replis sur 111 compréhensions, montant à 88 % les jours où le moteur basculait —
 * la corrélation ne laissait guère de doute, puisque c'est le même modèle qui tombe.
 *
 * Ce que ces tests verrouillent : la bascule a lieu, elle reste sur le modèle qui a
 * répondu pour la suite du même message, le débit est compté au bon compteur, et le
 * fail-open survit intact quand TOUTE la chaîne tombe.
 */
class ComprehensionChaineDeSecoursTest extends TestCase
{
    /** @var list<string> les modèles réellement interrogés, dans l'ordre */
    private array $modelesInterroges = [];

    private function requete(string $message): AiRequest
    {
        return new AiRequest(
            systemContext: [
                'assistantNom'  => 'Ket',
                'entrepriseNom' => 'Courtage Test',
                'perimetre'     => [],
                'date'          => '2026-09-25',
            ],
            messages: [['role' => 'user', 'content' => $message]],
            scope: new AiScope(new Entreprise(), new Invite()),
        );
    }

    /** La conclusion telle que Gemini la rend : du JSON dans une part de texte. */
    private static function json(array $sortie, int $entree = 400): MockResponse
    {
        return new MockResponse(json_encode([
            'candidates'    => [['content' => ['parts' => [['text' => json_encode($sortie, JSON_THROW_ON_ERROR)]]]]],
            'usageMetadata' => ['promptTokenCount' => $entree],
        ], JSON_THROW_ON_ERROR));
    }

    /** Le modèle est débordé chez Google : c'est le cas qui faisait tomber la phase. */
    private static function surcharge(): MockResponse
    {
        return new MockResponse('{"error":{"code":503,"message":"The model is overloaded."}}', ['http_code' => 503]);
    }

    /** @param list<MockResponse> $reponses */
    private function appel(array $reponses, string $replis = '', ?BudgetDebit $budget = null): AppelGemini
    {
        $i = 0;
        $http = new MockHttpClient(function (string $methode, string $url) use ($reponses, &$i): MockResponse {
            // Le modèle interrogé se lit dans l'URL : c'est là, et nulle part ailleurs,
            // que la bascule se constate.
            if (preg_match('#/models/([^:]+):#', $url, $m) === 1) {
                $this->modelesInterroges[] = $m[1];
            }

            return $reponses[$i++] ?? throw new \RuntimeException('Appel HTTP non prévu par le test.');
        });

        $contextBuilder = $this->createMock(AiContextBuilder::class);
        $contextBuilder->method('toSystemPrompt')->willReturn('PROMPT DE COMPRÉHENSION');

        return new AppelGemini(
            $http,
            $contextBuilder,
            new DialecteGemini(new TrousseCatalogue([])),
            new ExecuteurDOutils([]),
            $budget ?? new BudgetDebit(new ArrayAdapter()),
            new MemoireDEpuisement(new ArrayAdapter()),
            'gm-test',
            'modele-principal',
            $replis,
        );
    }

    /**
     * SANS SECOURS, RIEN NE CHANGE : un 503 remonte, et Comprehenseur en fait un
     * repli comme il l'a toujours fait. Le fail-open n'est pas négociable.
     */
    public function testSansChaineUnModeleDebordeRemonteLErreur(): void
    {
        $this->expectException(\Throwable::class);

        $this->appel([self::surcharge()])->conclure($this->requete('combien de clients ?'));
    }

    /** LE CAS QUI MANQUAIT : le principal est débordé, le secours répond. */
    public function testUnModeleDebordeBasculeSurLeSecours(): void
    {
        $resultat = $this->appel(
            [self::surcharge(), self::json(['claire' => true, 'intention' => 'Compter les clients.'])],
            'modele-secours',
        )->conclure($this->requete('combien de clients ?'));

        self::assertSame(['modele-principal', 'modele-secours'], $this->modelesInterroges);
        self::assertSame(
            ['claire' => true, 'intention' => 'Compter les clients.'],
            json_decode($resultat['texte'], true),
        );
    }

    /**
     * ET LE JOURNAL NOMME CELUI QUI A PARLÉ, jamais celui qu'on a demandé. Nommer un
     * modèle qui n'a rien dit, c'est faire chercher la cause d'une lenteur du mauvais
     * côté — le défaut corrigé le 2026-09-24 sur le bandeau des coulisses.
     */
    public function testLeModeleAyantReponduEstCeluiDuSecours(): void
    {
        $appel = $this->appel(
            [self::surcharge(), self::json(['claire' => true, 'intention' => 'X'])],
            'modele-secours',
        );

        self::assertSame('modele-principal', $appel->modeleAyantRepondu(), 'Avant tout appel : le modèle demandé.');

        $appel->conclure($this->requete('combien de clients ?'));

        self::assertSame('modele-secours', $appel->modeleAyantRepondu());
        self::assertSame('modele-principal', $appel->modele(), 'Le modèle DEMANDÉ ne bouge pas.');
    }

    /**
     * LE DÉBIT EST COMPTÉ AU MODÈLE QUI A CONSOMMÉ. Google tient sa fenêtre PAR
     * MODÈLE : facturer au principal ce qu'un secours a dépensé fermerait la mauvaise
     * fenêtre, et laisserait l'autre s'épuiser sans qu'on le voie venir.
     */
    public function testLeDebitEstComptePourLeModeleQuiARepondu(): void
    {
        $budget = new BudgetDebit(new ArrayAdapter());

        $this->appel(
            [self::surcharge(), self::json(['claire' => true, 'intention' => 'X'], 1234)],
            'modele-secours',
            $budget,
        )->conclure($this->requete('combien de clients ?'));

        $consomme = fn (string $modele): int => $budget->plafondUtile($modele) - $budget->restant($modele);
        self::assertSame(1234, $consomme('modele-secours'));
        self::assertSame(0, $consomme('modele-principal'), 'Le principal n\'a rien consommé : il n\'a pas répondu.');
    }

    /**
     * TOUTE LA CHAÎNE TOMBE : on relance la dernière erreur, et le fail-open de
     * Comprehenseur reprend la main. Une phase d'amélioration ne doit JAMAIS
     * empêcher qu'il y ait une réponse.
     */
    public function testQuandToutLaChaineTombeLErreurRemonte(): void
    {
        $this->expectException(\Throwable::class);

        $this->appel(
            [self::surcharge(), self::surcharge(), self::surcharge()],
            'secours-un,secours-deux',
        )->conclure($this->requete('combien de clients ?'));
    }

    /** Un doublon dans la liste de secours ne fait pas payer deux fois le même modèle. */
    public function testLeModeleDemandeNEstPasRejoueCommeSecours(): void
    {
        try {
            $this->appel([self::surcharge(), self::surcharge()], 'modele-principal,modele-secours')
                ->conclure($this->requete('combien de clients ?'));
        } catch (\Throwable) {
            // L'échec est attendu : ce qui compte est la liste des modèles interrogés.
        }

        self::assertSame(['modele-principal', 'modele-secours'], $this->modelesInterroges);
    }
}
