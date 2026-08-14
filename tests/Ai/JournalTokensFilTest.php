<?php

namespace App\Tests\Ai;

use App\Ai\AiRequest;
use App\Ai\Mutation\OutilsDePlan;
use App\Ai\Scope\AiScope;
use App\Ai\Telemetrie\JournalTokens;
use App\Ai\Trousse\Phase;
use App\Entity\Entreprise;
use App\Entity\Invite;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

/**
 * FIL D'ACTIVITÉ — ce que le navigateur apprend PENDANT que le message se déroule.
 *
 * CE QUI EST EN JEU. Le chat restait aveugle du clic à la réponse : un « Ket
 * réfléchit… » figé pendant vingt à quarante secondes, alors que trois appels au
 * modèle et une exécution d'outils s'enchaînaient derrière. Ces tests verrouillent
 * les deux propriétés sans lesquelles l'affichage mentirait :
 *
 *  - un coût annoncé UNE SEULE FOIS (sinon l'utilisateur croit payer deux fois la
 *    même chose quand une étape locale ne consomme rien) ;
 *  - un journal INERTE tant que personne n'écoute — c'est ce qui garantit que les
 *    huit tests d'envoi existants, et les moteurs sans télémétrie, ne changent pas
 *    de comportement d'une ligne.
 */
class JournalTokensFilTest extends TestCase
{
    /** @var list<array{cle: string, tokensEtape: int, tokensCumul: int}> */
    private array $etapes = [];

    private function journal(): JournalTokens
    {
        return new JournalTokens(new NullLogger(), new OutilsDePlan([]));
    }

    private function ecoute(JournalTokens $journal): void
    {
        $journal->ecouter(function (array $etape): void {
            $this->etapes[] = $etape;
        });
    }

    private function request(): AiRequest
    {
        return new AiRequest(
            systemContext: ['assistantNom' => 'Ket', 'entrepriseNom' => 'Courtage Test', 'perimetre' => [], 'date' => '2026-08-14'],
            messages: [['role' => 'user', 'content' => 'Combien de clients ?']],
            scope: new AiScope(new Entreprise(), new Invite()),
        );
    }

    /** @return list<string> */
    private function cles(): array
    {
        return array_map(static fn (array $e) => $e['cle'], $this->etapes);
    }

    /**
     * Le garde-fou le plus important du lot : sans abonné, RIEN ne change. C'est
     * lui qui protège les huit tests d'envoi qui lisent une réponse d'un seul bloc.
     */
    public function testSansAbonneLeJournalNEmetRien(): void
    {
        $enregistrements = [];
        $espion = new class($enregistrements) extends AbstractLogger {
            public function __construct(private array &$enregistrements)
            {
            }

            public function log($level, $message, array $context = []): void
            {
                $this->enregistrements[] = (string) $message;
            }
        };

        $journal = new JournalTokens($espion, new OutilsDePlan([]));
        $journal->nouveauMessage();
        $journal->debutDePhase(Phase::COMPREHENSION);
        $journal->tour($this->request(), 'gemini', 'flash', 1, ['entree' => 20000, 'sortie' => 1200], [], ['analyse_portefeuille']);

        self::assertSame([], $this->etapes, 'Aucun abonné : rien ne doit être poussé.');
        // Et le journal Monolog, lui, continue exactement comme avant.
        self::assertSame(['tour'], $enregistrements);
    }

    public function testLaSequenceNominaleDecritLesTroisAppelsEtLExecutionDesOutils(): void
    {
        $journal = $this->journal();
        $this->ecoute($journal);
        $requete = $this->request();

        $journal->nouveauMessage();

        // 1. Compréhension : annoncée AVANT l'appel, donc à zéro jeton.
        $journal->debutDePhase(Phase::COMPREHENSION);
        $journal->comprehension($requete, 'flash-lite', 'claire', 'modele', 480, 900);

        // 2. Planification : porte enfin le coût de la compréhension qui vient de finir.
        $journal->debutDePhase(Phase::PLANIFICATION);
        $journal->tour($requete, 'gemini', 'flash', 1, ['entree' => 20000, 'sortie' => 1200], [], ['analyse_portefeuille']);

        // 3. Rédaction, après l'exécution locale des outils.
        $journal->debutDePhase(Phase::REDACTION);
        $journal->tour($requete, 'gemini', 'flash', 2, ['entree' => 8000, 'sortie' => 600], [], []);

        self::assertSame(
            ['comprehension', 'planification', 'outils', 'redaction'],
            $this->cles(),
            'L’étape « outils » naît du tour qui a émis des appels, pas d’une quatrième phase.',
        );

        // Le coût de chaque étape est celui du DERNIER appel terminé.
        self::assertSame(0, $this->etapes[0]['tokensEtape'], 'Rien n’a encore été appelé.');
        self::assertSame(480, $this->etapes[1]['tokensEtape'], 'Coût de la compréhension.');
        self::assertSame(21200, $this->etapes[2]['tokensEtape'], 'Entrée + sortie de la planification.');
        self::assertSame(0, $this->etapes[3]['tokensEtape'], 'Les outils tournent en local : ils ne coûtent aucun jeton.');

        self::assertSame([0, 480, 21680, 21680], array_column($this->etapes, 'tokensCumul'));
    }

    /**
     * Le point qui trompait l'œil : une étape qui ne consomme rien ne doit pas
     * réafficher le montant de la précédente.
     */
    public function testUnCoutNEstAnnonceQuUneSeuleFois(): void
    {
        $journal = $this->journal();
        $this->ecoute($journal);
        $requete = $this->request();

        $journal->nouveauMessage();
        $journal->tour($requete, 'gemini', 'flash', 1, ['entree' => 20000, 'sortie' => 1200], [], ['analyse_portefeuille']);
        $journal->debutDePhase(Phase::REDACTION);

        self::assertSame(['outils', 'redaction'], $this->cles());
        self::assertSame(21200, $this->etapes[0]['tokensEtape']);
        self::assertSame(0, $this->etapes[1]['tokensEtape'], 'Le même coût réapparaîtrait sinon sur l’étape suivante.');
    }

    public function testUnTourSansOutilNAnnonceAucuneExecution(): void
    {
        $journal = $this->journal();
        $this->ecoute($journal);

        $journal->nouveauMessage();
        $journal->tour($this->request(), 'gemini', 'flash', 1, ['entree' => 9000, 'sortie' => 400], [], []);

        self::assertSame([], $this->cles(), 'Une question de pure conversation n’exécute rien.');
    }

    public function testUneDemandeAmbigueSAnnonceCommeTelle(): void
    {
        $journal = $this->journal();
        $this->ecoute($journal);

        $journal->nouveauMessage();
        $journal->debutDePhase(Phase::COMPREHENSION);
        $journal->comprehension($this->request(), 'flash-lite', 'a_clarifier', 'modele', 512, 800);

        self::assertSame(['comprehension', 'clarification'], $this->cles());
        self::assertSame(512, $this->etapes[1]['tokensEtape']);
    }

    /**
     * Un court-circuit (le serveur savait déjà) ne coûte rien : pas de quoi
     * inventer un appel dans le récapitulatif.
     */
    public function testUneComprehensionGratuiteNeCompteAucunAppel(): void
    {
        $journal = $this->journal();
        $this->ecoute($journal);

        $journal->nouveauMessage();
        $journal->debutDePhase(Phase::COMPREHENSION);
        $journal->comprehension($this->request(), 'flash-lite', 'claire', 'court_circuit', 0, 2);
        $journal->debutDePhase(Phase::PLANIFICATION);

        self::assertSame(0, $this->etapes[1]['tokensEtape']);
        self::assertSame(0, $journal->recapitulatif()['appels']);
    }

    public function testLeRecapitulatifResumeLeMessageEntier(): void
    {
        $journal = $this->journal();
        $requete = $this->request();

        $journal->nouveauMessage();
        $journal->debutDePhase(Phase::COMPREHENSION);
        $journal->comprehension($requete, 'flash-lite', 'claire', 'modele', 480, 900);
        $journal->debutDePhase(Phase::PLANIFICATION);
        $journal->tour($requete, 'gemini', 'flash', 1, ['entree' => 20000, 'sortie' => 1200], [], ['analyse_portefeuille']);
        $journal->debutDePhase(Phase::REDACTION);
        $journal->tour($requete, 'gemini', 'flash', 2, ['entree' => 8000, 'sortie' => 600], [], []);

        $recap = $journal->recapitulatif();

        // Le récapitulatif existe SANS abonné : il est aussi rendu sur le chemin
        // non-streamé, et surtout il doit survivre au rechargement de la page.
        self::assertSame(3, $recap['appels']);
        self::assertSame(30280, $recap['jetonsIa']);
        self::assertSame(
            ['comprehension', 'planification', 'outils', 'redaction'],
            array_column($recap['etapes'], 'cle'),
        );

        // CHAQUE COÛT SOUS LA BONNE ÉTAPE. Une phase s'annonce avant de partir : son
        // prix n'est connu qu'au retour, donc l'attribution recule d'un cran. Sans
        // cela, le détail montrait « rédige la réponse — 0 jeton » pour l'appel qui
        // venait de coûter 8 600 jetons.
        self::assertSame(
            ['comprehension' => 480, 'planification' => 21200, 'outils' => 0, 'redaction' => 8600],
            array_combine(array_column($recap['etapes'], 'cle'), array_column($recap['etapes'], 'jetons')),
        );
    }

    /**
     * Le détail doit TOTALISER le montant affiché. C'est ce qu'un utilisateur
     * vérifie en dépliant le récapitulatif — et l'incohérence constatée en
     * conditions réelles (46 220 annoncés, 39 752 répartis) venait de là.
     */
    public function testLeDetailTotaliseLeMontantAffiche(): void
    {
        $journal = $this->journal();
        $requete = $this->request();

        $journal->nouveauMessage();
        $journal->debutDePhase(Phase::COMPREHENSION);
        $journal->comprehension($requete, 'flash-lite', 'claire', 'modele', 11501, 900);
        $journal->debutDePhase(Phase::PLANIFICATION);
        $journal->tour($requete, 'gemini', 'flash', 1, ['entree' => 27000, 'sortie' => 1251], [], ['compter_entites']);
        $journal->debutDePhase(Phase::REDACTION);
        $journal->tour($requete, 'gemini', 'flash', 2, ['entree' => 6000, 'sortie' => 468], [], []);

        $recap = $journal->recapitulatif();

        self::assertSame($recap['jetonsIa'], array_sum(array_column($recap['etapes'], 'jetons')));
    }

    public function testUnMoteurSansTelemetrieNeProduitAucunRecapitulatif(): void
    {
        $journal = $this->journal();
        $journal->nouveauMessage();

        self::assertNull(
            $journal->recapitulatif(),
            'Mieux vaut ne rien afficher que d’afficher des zéros (moteur simulé, Anthropic).',
        );
    }

    public function testUnNouveauMessageRepartDeZero(): void
    {
        $journal = $this->journal();
        $requete = $this->request();

        $journal->nouveauMessage();
        $journal->tour($requete, 'gemini', 'flash', 1, ['entree' => 20000, 'sortie' => 1200], [], ['x']);

        $journal->nouveauMessage();
        $this->ecoute($journal);
        $journal->debutDePhase(Phase::PLANIFICATION);

        self::assertSame(0, $this->etapes[0]['tokensCumul'], 'Le message précédent ne doit rien laisser derrière lui.');
        self::assertSame(0, $this->etapes[0]['tokensEtape']);
        self::assertSame(0, $journal->recapitulatif()['jetonsIa']);
        self::assertSame(['planification'], array_column($journal->recapitulatif()['etapes'], 'cle'));
    }
}
