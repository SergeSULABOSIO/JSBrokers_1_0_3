<?php

namespace App\Tests\Ai;

use App\Ai\AiRequest;
use App\Ai\Engine\DialecteGemini;
use App\Ai\Engine\Socle\DialecteGeminiDuFil;
use App\Ai\Scope\AiScope;
use App\Ai\Trousse\Phase;
use App\Ai\Trousse\Trousse;
use App\Ai\Trousse\TrousseCatalogue;
use App\Entity\Entreprise;
use App\Entity\Invite;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * UN MODÈLE PAR PHASE — et surtout, une FENÊTRE DE QUOTA PAR PHASE.
 *
 * Mesuré sur la campagne au 2026-09-25 : la planification pèse 83,9 % des jetons
 * d'entrée, la rédaction 9,8 %, la compréhension 6,3 %. Les trois partageaient une
 * seule fenêtre de 250 000 jetons par minute — trois messages suffisaient à la
 * saturer, et c'est la cause directe des « moteur saturé ».
 *
 * Or c'est la rédaction, et elle seule, qui écrit le texte que l'utilisateur lit.
 * Lui donner un modèle plus capable améliore la totalité de ce qui est lu pour un
 * dixième du volume, et la sort du compteur de la planification par la même occasion.
 *
 * Ce que ces tests verrouillent : le défaut ne change rien, le modèle dédié n'est
 * employé qu'en rédaction, le débit est compté sur SA fenêtre, et un modèle dédié qui
 * tombe rend la main au modèle du moteur sans faire perdre la réponse.
 */
class ModeleParPhaseTest extends TestCase
{
    /** @var list<string> les modèles réellement interrogés, dans l'ordre */
    private array $interroges = [];

    private function requete(): AiRequest
    {
        return new AiRequest(
            systemContext: [
                'assistantNom'  => 'Ket',
                'entrepriseNom' => 'Courtage Test',
                'perimetre'     => [],
                'date'          => '2026-09-25',
            ],
            messages: [['role' => 'user', 'content' => 'combien de clients ?']],
            scope: new AiScope(new Entreprise(), new Invite()),
        );
    }

    private static function reponse(string $texte = 'Voici.'): MockResponse
    {
        return new MockResponse(json_encode([
            'candidates'    => [['content' => ['parts' => [['text' => $texte]]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 10],
        ], JSON_THROW_ON_ERROR));
    }

    private static function surcharge(): MockResponse
    {
        return new MockResponse('{"error":{"code":503,"message":"The model is overloaded."}}', ['http_code' => 503]);
    }

    /**
     * @param list<MockResponse> $reponses
     */
    private function dialecte(array $reponses, string $redaction = '', string $replis = ''): DialecteGeminiDuFil
    {
        $i = 0;
        $http = new MockHttpClient(function (string $methode, string $url) use ($reponses, &$i): MockResponse {
            if (preg_match('#/models/([^:]+):#', $url, $m) === 1) {
                $this->interroges[] = $m[1];
            }

            return $reponses[$i++] ?? throw new \RuntimeException('Appel HTTP non prévu par le test.');
        });

        return new DialecteGeminiDuFil(
            $http,
            static fn (): string => 'PROMPT',
            new DialecteGemini(new TrousseCatalogue([])),
            'gm-test',
            static fn (): string => 'modele-moteur',
            static fn (): string => $replis,
            new NullLogger(),
            4096,
            15,
            modeleDeRedaction: static fn (): string => $redaction,
        );
    }

    /**
     * LE DÉFAUT NE CHANGE RIEN. Sans modèle dédié, les deux phases partent sur le
     * modèle du moteur, exactement comme avant ce réglage.
     */
    public function testSansModeleDedieLesDeuxPhasesPartentSurLeMoteur(): void
    {
        $dialecte = $this->dialecte([self::reponse(), self::reponse()]);
        $requete = $this->requete();

        $dialecte->appeler($requete, [], Trousse::LECTURE, Phase::PLANIFICATION);
        $dialecte->appeler($requete, [], Trousse::LECTURE, Phase::REDACTION);

        self::assertSame(['modele-moteur', 'modele-moteur'], $this->interroges);
    }

    /** LE CAS VISÉ : la planification sur le moteur, la rédaction sur le sien. */
    public function testLaRedactionSeuleEmploieLeModeleDedie(): void
    {
        $dialecte = $this->dialecte([self::reponse(), self::reponse()], 'modele-redaction');
        $requete = $this->requete();

        $dialecte->appeler($requete, [], Trousse::LECTURE, Phase::PLANIFICATION);
        $dialecte->appeler($requete, [], Trousse::LECTURE, Phase::REDACTION);

        self::assertSame(['modele-moteur', 'modele-redaction'], $this->interroges);
    }

    /**
     * LE DÉBIT EST COMPTÉ SUR LA FENÊTRE DE LA PHASE. C'est tout l'objet du chantier :
     * sans cette clé, la rédaction fermerait la fenêtre de la planification, et la
     * sienne s'épuiserait sans qu'on la voie venir.
     */
    public function testLaCleDeDebitSuitLaPhase(): void
    {
        $dialecte = $this->dialecte([], 'modele-redaction');

        self::assertSame('modele-redaction', $dialecte->cleDeDebit(Phase::REDACTION));
        self::assertSame('modele-moteur', $dialecte->cleDeDebit(Phase::PLANIFICATION));
        self::assertSame('modele-moteur', $dialecte->cleDeDebit(), 'Sans phase : le modèle courant, comme avant.');
    }

    /**
     * ⚠ UN MODÈLE DÉDIÉ QUI TOMBE NE FAIT PAS PERDRE LA RÉPONSE. Il rend la main au
     * modèle du moteur — celui qui vient de faire toute la planification, et qui
     * répond donc certainement. Un modèle plus capable est un BONUS : il ne doit
     * jamais devenir un point de rupture.
     */
    public function testUnModeleDedieQuiTombeRendLaMainAuMoteur(): void
    {
        $dialecte = $this->dialecte([self::surcharge(), self::reponse('Voici votre portefeuille.')], 'modele-redaction');

        $resultat = $dialecte->appeler($this->requete(), [], Trousse::LECTURE, Phase::REDACTION);

        self::assertSame(['modele-redaction', 'modele-moteur'], $this->interroges);
        self::assertSame('Voici votre portefeuille.', $dialecte->texte($resultat['reponse']));
    }

    /**
     * ET IL EST ABANDONNÉ POUR LE RESTE DU MESSAGE. Sans cette mémoire, chaque tour
     * suivant repaierait le même échec certain — le défaut que la bascule de secours
     * du moteur évite déjà, pour la même raison.
     */
    public function testLeModeleDedieAbandonneNEstPlusRappele(): void
    {
        $dialecte = $this->dialecte(
            [self::surcharge(), self::reponse(), self::reponse()],
            'modele-redaction',
        );
        $requete = $this->requete();

        $dialecte->appeler($requete, [], Trousse::LECTURE, Phase::REDACTION);
        $dialecte->appeler($requete, [], Trousse::LECTURE, Phase::REDACTION);

        self::assertSame(['modele-redaction', 'modele-moteur', 'modele-moteur'], $this->interroges);
    }
}
