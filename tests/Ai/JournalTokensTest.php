<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\OutilsDePlan;
use App\Ai\AiRequest;
use App\Ai\Scope\AiScope;
use App\Ai\Telemetrie\JournalTokens;
use App\Entity\Entreprise;
use App\Entity\Invite;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Télémétrie des tokens de l'assistant. Ce que ces tests protègent, c'est la
 * capacité à DÉCIDER : sans corrélation entre les tours et leur message, sans
 * répartition des octets et sans issue nommée, la campagne produirait des
 * chiffres inexploitables — et l'arbitrage entre alléger le contexte et
 * relever le plafond resterait une intuition.
 */
class JournalTokensTest extends TestCase
{
    /** @var list<array{message: string, context: array}> */
    private array $enregistrements = [];

    private function journal(): JournalTokens
    {
        $espion = new class($this->enregistrements) extends AbstractLogger {
            public function __construct(private array &$enregistrements)
            {
            }

            public function log($level, $message, array $context = []): void
            {
                $this->enregistrements[] = ['message' => (string) $message, 'context' => $context];
            }
        };

        return new JournalTokens($espion, new OutilsDePlan([]));
    }

    private function request(): AiRequest
    {
        return new AiRequest(
            systemContext: ['assistantNom' => 'Ket', 'entrepriseNom' => 'Courtage Test', 'perimetre' => [], 'date' => '2026-08-08'],
            messages: [['role' => 'user', 'content' => 'Combien de clients ?']],
            scope: new AiScope(new Entreprise(), new Invite()),
        );
    }

    public function testLaLigneDeTourPorteTokensEtRepartitionDesOctets(): void
    {
        $journal = $this->journal();
        $journal->nouveauMessage();
        $journal->tour(
            $this->request(),
            'gemini',
            'gemini-flash-lite-latest',
            1,
            ['entree' => 36000, 'sortie' => 250, 'cache' => 12000],
            ['systeme' => 54649, 'outils' => 72407, 'historique' => 7326],
            ['rechercher_entites'],
        );

        $contexte = $this->enregistrements[0]['context'];

        $this->assertSame('tour', $contexte['evenement']);
        $this->assertSame('gemini', $contexte['moteur']);
        $this->assertSame('gemini-flash-lite-latest', $contexte['modele']);
        $this->assertSame(1, $contexte['tour']);
        $this->assertSame(36000, $contexte['tokensEntree']);
        $this->assertSame(250, $contexte['tokensSortie']);
        // Les tokens servis par le cache du fournisseur comptent MALGRÉ TOUT
        // dans le quota : la colonne mesure l'économie de facture, pas un
        // desserrement du plafond.
        $this->assertSame(12000, $contexte['tokensCache']);
        $this->assertSame(54649, $contexte['octetsSysteme']);
        $this->assertSame(72407, $contexte['octetsOutils']);
        $this->assertSame(7326, $contexte['octetsHistorique']);
        $this->assertSame(['rechercher_entites'], $contexte['outils']);
        $this->assertNotEmpty($contexte['horodatage']);
    }

    /**
     * Sans identifiant commun, deux utilisateurs écrivant en même temps
     * produiraient des tours entrelacés impossibles à rattacher à leur message.
     */
    public function testLesToursEtLeurMessagePartagentUnIdentifiant(): void
    {
        $journal = $this->journal();
        $journal->nouveauMessage();
        $journal->tour($this->request(), 'gemini', 'm', 1, ['entree' => 100], []);
        $journal->message($this->request(), 'gemini', 'm', JournalTokens::ISSUE_REPONSE, 1, 100);

        $idTour = $this->enregistrements[0]['context']['messageId'];
        $idMessage = $this->enregistrements[1]['context']['messageId'];

        $this->assertNotNull($idTour);
        $this->assertSame($idTour, $idMessage);
    }

    public function testUnNouveauMessageChangeDIdentifiantEtRemetLesCompteursAZero(): void
    {
        $journal = $this->journal();

        $journal->nouveauMessage();
        $journal->tour($this->request(), 'gemini', 'm', 1, ['entree' => 40000, 'sortie' => 10], []);
        $premier = $this->enregistrements[0]['context']['messageId'];

        $journal->nouveauMessage();
        $journal->tour($this->request(), 'gemini', 'm', 1, ['entree' => 5000, 'sortie' => 3], []);
        $journal->messageInterrompu($this->request(), 'gemini', 'm', JournalTokens::ISSUE_QUOTA_FOURNISSEUR);

        $second = $this->enregistrements[1]['context']['messageId'];
        $bilan = $this->enregistrements[2]['context'];

        $this->assertNotSame($premier, $second);
        $this->assertSame(1, $bilan['tours'], 'Les tours du message précédent ne doivent pas fuiter.');
        $this->assertSame(5000, $bilan['cumulEntree']);
        $this->assertSame(3, $bilan['cumulSortie']);
    }

    /**
     * Un 429 remonte jusqu'au contrôleur, qui ignore combien de tours ont déjà
     * été payés. Ce sont pourtant les messages les plus coûteux : s'ils
     * manquaient à la campagne, elle sous-estimerait exactement le problème
     * qu'elle cherche à mesurer.
     */
    public function testLeMessageInterrompuRestitueLesToursDejaPayes(): void
    {
        $journal = $this->journal();
        $journal->nouveauMessage();
        $journal->tour($this->request(), 'gemini', 'm', 1, ['entree' => 36000, 'sortie' => 100], []);
        $journal->tour($this->request(), 'gemini', 'm', 2, ['entree' => 38000, 'sortie' => 120], []);
        $journal->messageInterrompu(
            $this->request(),
            'gemini',
            'm',
            JournalTokens::ISSUE_QUOTA_FOURNISSEUR,
            ['quotaId' => 'GenerateContentInputTokensPerModelPerMinute-FreeTier', 'retryApres' => 47],
        );

        $bilan = $this->enregistrements[2]['context'];

        $this->assertSame('message', $bilan['evenement']);
        $this->assertSame(JournalTokens::ISSUE_QUOTA_FOURNISSEUR, $bilan['issue']);
        $this->assertSame(2, $bilan['tours']);
        $this->assertSame(74000, $bilan['cumulEntree']);
        $this->assertSame(220, $bilan['cumulSortie']);
        $this->assertSame(47, $bilan['complement']['retryApres']);
    }

    public function testLeComplementEstOmisQuandIlEstVide(): void
    {
        $journal = $this->journal();
        $journal->nouveauMessage();
        $journal->message($this->request(), 'gemini', 'm', JournalTokens::ISSUE_REPONSE, 1, 100);

        $this->assertArrayNotHasKey('complement', $this->enregistrements[0]['context']);
    }

    /*
     * ── LES COULISSES REMONTENT JUSQU'À L'ÉCRAN ─────────────────────────────
     *
     * Le moteur savait déjà tout : qui a répondu, avec quel modèle, quels outils ont
     * été appelés, comment les jetons se répartissent. Tout cela partait dans le
     * journal Monolog, que seul un exploitant lit. L'utilisateur, lui, voyait
     * « 35 714 jetons IA » sans savoir d'où ils venaient — un chiffre qu'on subit.
     *
     * Ces tests verrouillent le chemin qui va du moteur au récapitulatif affiché.
     */

    public function testLeRecapitulatifPorteLeModeleQuiARepondu(): void
    {
        $journal = $this->journal();
        $journal->nouveauMessage();
        $journal->debutDePhase(\App\Ai\Trousse\Phase::PLANIFICATION);
        $journal->tour(
            $this->request(),
            'gemini',
            // Un SECOURS, pas le modèle configuré : c'est le cas où l'écran mentait.
            'gemini-3.5-flash-lite',
            1,
            ['entree' => 35000, 'sortie' => 700, 'cache' => 26000],
            ['systeme' => 10, 'outils' => 20, 'historique' => 30],
            ['vigie_echeances', 'suivi_impayes'],
        );

        $etape = $journal->recapitulatif()['etapes'][0];

        self::assertSame('gemini', $etape['moteur']);
        self::assertSame(
            'gemini-3.5-flash-lite',
            $etape['modele'],
            'C’est le modèle qui A RÉPONDU qui doit remonter, jamais celui qui est configuré.'
        );
        self::assertSame(['vigie_echeances', 'suivi_impayes'], $etape['outils']);
        self::assertSame(35000, $etape['entree']);
        self::assertSame(700, $etape['sortie']);
        self::assertSame(26000, $etape['cache']);
    }

    /**
     * DEUX ALLERS-RETOURS SANS OUTIL RESTENT UNE SEULE ÉTAPE.
     *
     * ⚠ AVEC des outils, c'est autre chose : `tour()` ouvre alors l'étape « outils »
     * juste après, parce que Symfony va les exécuter localement — ce temps-là n'est
     * pas du temps de modèle et ne doit pas être compté comme tel. C'est pourquoi ce
     * test appelle sans outil : c'est le seul cas où deux tours partagent une étape.
     */
    public function testDeuxToursSansOutilSeCumulentDansLaMemeEtape(): void
    {
        $journal = $this->journal();
        $journal->nouveauMessage();
        $journal->debutDePhase(\App\Ai\Trousse\Phase::PLANIFICATION);

        foreach ([1, 2] as $tour) {
            $journal->tour(
                $this->request(),
                'gemini',
                'gemini-3.1-flash-lite',
                $tour,
                ['entree' => 1000, 'sortie' => 100, 'cache' => 0],
                ['systeme' => 1, 'outils' => 1, 'historique' => 1],
            );
        }

        $etapes = $journal->recapitulatif()['etapes'];

        self::assertCount(1, $etapes);
        self::assertSame(2, $etapes[0]['tours']);
        self::assertSame(2000, $etapes[0]['entree']);
        self::assertSame(200, $etapes[0]['sortie']);
    }

    /**
     * UN TOUR AVEC OUTILS OUVRE L'ÉTAPE « OUTILS ». L'exécution locale n'est pas du
     * temps de modèle : la confondre avec lui ferait croire que le fournisseur est
     * lent alors que c'est une requête SQL qui l'est.
     */
    public function testUnTourAvecOutilsOuvreLEtapeDExecution(): void
    {
        $journal = $this->journal();
        $journal->nouveauMessage();
        $journal->debutDePhase(\App\Ai\Trousse\Phase::PLANIFICATION);
        $journal->tour(
            $this->request(),
            'gemini',
            'gemini-3.1-flash-lite',
            1,
            ['entree' => 1000, 'sortie' => 100, 'cache' => 0],
            ['systeme' => 1, 'outils' => 1, 'historique' => 1],
            ['rechercher_entites'],
        );

        $etapes = $journal->recapitulatif()['etapes'];

        self::assertCount(2, $etapes);
        self::assertSame(['rechercher_entites'], $etapes[0]['outils'], 'Les outils sont nommés sur la phase qui les a demandés.');
        self::assertSame('outils', $etapes[1]['cle']);
    }

    /**
     * L'ÉTAPE « OUTILS » PORTE LE NOM DES OUTILS, et pas seulement la phase qui les
     * a demandés : c'est cette ligne-là qu'on regarde pour savoir ce qui a été lu.
     */
    public function testLEtapeDExecutionPorteLeNomDesOutils(): void
    {
        $journal = $this->journal();
        $journal->nouveauMessage();
        $journal->debutDePhase(\App\Ai\Trousse\Phase::PLANIFICATION);
        $journal->tour(
            $this->request(),
            'gemini',
            'gemini-3.1-flash-lite',
            1,
            ['entree' => 1000, 'sortie' => 100, 'cache' => 0],
            ['systeme' => 1, 'outils' => 1, 'historique' => 1],
            ['vigie_echeances', 'suivi_impayes'],
            2400,
        );

        $etapes = $journal->recapitulatif()['etapes'];

        self::assertSame('outils', $etapes[1]['cle']);
        self::assertSame(
            ['vigie_echeances', 'suivi_impayes'],
            $etapes[1]['outils'],
            'La ligne « consulte vos données… » doit nommer ce qui a été lu.'
        );
    }

    /** Le temps passé chez le fournisseur remonte, distinct de la durée de l'étape. */
    public function testLeTempsChezLeFournisseurRemonte(): void
    {
        $journal = $this->journal();
        $journal->nouveauMessage();
        $journal->debutDePhase(\App\Ai\Trousse\Phase::PLANIFICATION);
        $journal->tour(
            $this->request(),
            'gemini',
            'gemini-3.1-flash-lite',
            1,
            ['entree' => 1000, 'sortie' => 100, 'cache' => 0],
            ['systeme' => 1, 'outils' => 1, 'historique' => 1],
            [],
            7400,
        );

        self::assertSame(7400, $journal->recapitulatif()['etapes'][0]['msModele']);
    }

    /**
     * LA COMPRÉHENSION DIT SUR QUOI ELLE A RÉFLÉCHI. C'est une famille de
     * fournisseurs distincte de la planification, réglable à part : l'écran affichait
     * « réfléchit… » sans jamais dire qui avait réfléchi — or c'est la phase la plus
     * souvent mise en cause quand Ket comprend mal.
     */
    public function testLaComprehensionPorteSonModeleEtSonTemps(): void
    {
        $journal = $this->journal();
        $journal->nouveauMessage();
        $journal->debutDePhase(\App\Ai\Trousse\Phase::COMPREHENSION);
        $journal->comprehension($this->request(), 'claude-haiku-4-5', 'claire', 'modele', 11101, 900);

        $etape = $journal->recapitulatif()['etapes'][0];

        self::assertSame('claude-haiku-4-5', $etape['modele']);
        self::assertSame(900, $etape['msModele']);
        self::assertSame(11101, $etape['entree']);
    }

    /** Chaque étape porte sa durée, et la dernière n'est pas oubliée. */
    public function testChaqueEtapePorteSaDuree(): void
    {
        $journal = $this->journal();
        $journal->nouveauMessage();
        $journal->debutDePhase(\App\Ai\Trousse\Phase::COMPREHENSION);
        $journal->debutDePhase(\App\Ai\Trousse\Phase::REDACTION);

        $etapes = $journal->recapitulatif()['etapes'];

        self::assertCount(2, $etapes);
        foreach ($etapes as $rang => $etape) {
            self::assertArrayHasKey('ms', $etape, sprintf('L’étape %d ne porte pas sa durée.', $rang));
            self::assertIsInt($etape['ms']);
        }
    }

    /**
     * L'HORODATAGE BRUT NE SORT PAS. Il ne sert qu'au calcul de la durée ; le laisser
     * partirait un `microtime` jusque dans un JSON stocké en base, puis au navigateur.
     */
    public function testLHorodatageInterneNeSortPas(): void
    {
        $journal = $this->journal();
        $journal->nouveauMessage();
        $journal->debutDePhase(\App\Ai\Trousse\Phase::REDACTION);

        foreach ($journal->recapitulatif()['etapes'] as $etape) {
            self::assertArrayNotHasKey('debut', $etape);
        }
    }

    /**
     * UN REPLI QUI SE PRODUIT DANS UNE MÊME ÉTAPE NE S'EFFACE PAS.
     *
     * Le repli lui-même est réel : constaté le 25/09/2026 sur un échange réel, où
     * gemini-3.1-flash-lite a répondu 503 et gemini-flash-lite-latest a pris la
     * main. Ce jour-là il s'est lu sur deux lignes, l'orchestrateur rouvrant une
     * étape par passage de boucle. Ce test verrouille l'AUTRE chemin — deux appels
     * dans la même étape — où écraser `modele` tairait le repli.
     */
    public function testUnRepliEnCoursDePhaseResteLisible(): void
    {
        $journal = $this->journal();
        $journal->nouveauMessage();
        $journal->debutDePhase(\App\Ai\Trousse\Phase::PLANIFICATION);

        foreach ([['gemini-3.1-flash-lite', 1], ['gemini-flash-lite-latest', 2]] as [$modele, $tour]) {
            $journal->tour(
                $this->request(),
                'gemini',
                $modele,
                $tour,
                ['entree' => 34000, 'sortie' => 50, 'cache' => 0],
                ['systeme' => 1, 'outils' => 1, 'historique' => 1],
                [],
                3800,
            );
        }

        $etape = $journal->recapitulatif()['etapes'][0];

        self::assertSame(
            'gemini-flash-lite-latest',
            $etape['modele'],
            'Le modèle mis en avant reste celui qui A RÉPONDU.'
        );
        self::assertSame(
            ['gemini-3.1-flash-lite', 'gemini-flash-lite-latest'],
            $etape['modeles'],
            'Le modèle abandonné doit rester lisible : c’est lui qui explique le repli.'
        );
        self::assertSame(7600, $etape['msModele'], 'Les deux appels comptent, repli compris.');
    }

    /** Sans repli, la chaîne ne porte qu'un nom — pas de bruit sur le cas ordinaire. */
    public function testSansRepliLaChaineNePorteQuUnModele(): void
    {
        $journal = $this->journal();
        $journal->nouveauMessage();
        $journal->debutDePhase(\App\Ai\Trousse\Phase::REDACTION);
        $journal->tour(
            $this->request(),
            'gemini',
            'gemini-3.1-flash-lite',
            1,
            ['entree' => 100, 'sortie' => 10, 'cache' => 0],
            ['systeme' => 1, 'outils' => 1, 'historique' => 1],
        );

        self::assertSame(['gemini-3.1-flash-lite'], $journal->recapitulatif()['etapes'][0]['modeles']);
    }

    /*
     * ── UNE PHASE QUI N'A APPELÉ PERSONNE NE NOMME PERSONNE ─────────────────
     *
     * `Comprehenseur::journaliser()` transmet toujours le modèle CONFIGURÉ, même
     * quand la demande a été court-circuitée ou qu'une heuristique locale a pris le
     * relais. Recopier ce nom dans le bandeau afficherait un modèle qui n'a jamais
     * été interrogé. Constaté sur un échange réel le 25/09/2026.
     */

    public function testUneComprehensionLocaleNeNommeAucunModele(): void
    {
        foreach ([\App\Ai\Comprehension\DemandeComprise::ORIGINE_REPLI,
                  \App\Ai\Comprehension\DemandeComprise::ORIGINE_COURT_CIRCUIT] as $origine) {
            $journal = $this->journal();
            $journal->nouveauMessage();
            $journal->debutDePhase(\App\Ai\Trousse\Phase::COMPREHENSION);
            $journal->comprehension($this->request(), 'gemini-3.1-flash-lite', 'claire', $origine, 0, 8086);

            $etape = $journal->recapitulatif()['etapes'][0];

            self::assertArrayNotHasKey(
                'modele',
                $etape,
                sprintf('Origine « %s » : aucun modèle n’a répondu, aucun ne doit être nommé.', $origine)
            );
            self::assertSame($origine, $etape['origine'], 'L’écran doit pouvoir dire ce qui a eu lieu à la place.');
        }
    }

    public function testUneComprehensionParLeModeleLeNomme(): void
    {
        $journal = $this->journal();
        $journal->nouveauMessage();
        $journal->debutDePhase(\App\Ai\Trousse\Phase::COMPREHENSION);
        $journal->comprehension(
            $this->request(),
            'gemini-3.1-flash-lite',
            'claire',
            \App\Ai\Comprehension\DemandeComprise::ORIGINE_MODELE,
            11101,
            900,
        );

        $etape = $journal->recapitulatif()['etapes'][0];

        self::assertSame('gemini-3.1-flash-lite', $etape['modele']);
        self::assertSame(11101, $etape['entree']);
        self::assertSame(900, $etape['msModele']);
    }

    /**
     * LA VALEUR EST RECOPIÉE DANS LA TÉLÉMÉTRIE : ce test est le fil qui les relie.
     * Si `DemandeComprise` renomme une origine, il casse ici plutôt qu'en silence
     * dans le bandeau.
     */
    public function testLesOriginesDeLaComprehensionRestentAlignees(): void
    {
        self::assertSame(
            \App\Ai\Comprehension\DemandeComprise::ORIGINE_MODELE,
            \App\Ai\Telemetrie\JournalTokens::ORIGINE_MODELE
        );
    }
}
