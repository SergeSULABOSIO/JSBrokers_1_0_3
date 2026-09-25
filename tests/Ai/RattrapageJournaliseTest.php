<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\OutilsDePlan;
use App\Ai\Reglage\ReglagesDeKet;
use App\Ai\Scope\AiScope;
use App\Ai\Telemetrie\JournalTokens;
use App\Ai\Telemetrie\RapportTokens;
use App\Ai\Tool\AiToolInterface;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\ExecuteurDOutils;
use App\Ai\Trousse\Trousse;
use App\Ai\Trousse\TrousseCatalogue;
use App\Entity\Entreprise;
use App\Entity\PlateformeParametres;
use App\Entity\Invite;
use App\Repository\PlateformeParametresRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * CE QUE KET FAIT DE SES OUTILS DOIT ÊTRE VÉRIFIABLE — EN PRODUCTION COMME AILLEURS.
 *
 * ── L'INCIDENT ───────────────────────────────────────────────────────────────────
 *
 * Le rattrapage d'un nom écorché prend une décision À LA PLACE du modèle : il exécute
 * un outil que le modèle n'a pas nommé. Il laissait bien une trace — mais sur le canal
 * « app », par un logger autowiré. Or en production ce canal est en `fingers_crossed`
 * sur « error » : la ligne n'était JAMAIS écrite. Et en développement elle se noyait
 * dans un `dev.log` de près de quatre gigaoctets, que la configuration elle-même
 * déclare inagrégeable.
 *
 * Autrement dit : la seule mesure du rattrapage était indisponible partout où le
 * rattrapage sert. Symétriquement, `AiToolResult::introuvable()` n'était journalisé
 * nulle part — un appel qui n'exécutait rien était indistinguable d'un succès, puisque
 * l'orchestrateur pousse le nom DEMANDÉ dans `tour.outils` quel que soit le résultat.
 *
 * ── CE QUE CES TESTS VERROUILLENT ────────────────────────────────────────────────
 *
 * Trois sorties, trois lignes distinctes, sur le canal que le rapport relit :
 *  · un nom rattrapé  → `rattrapage`, avec ce qui a été demandé ET ce qui a été exécuté ;
 *  · un nom inconnu   → `introuvable`, motif « inconnu » — le chiffre à ramener à zéro ;
 *  · un outil coupé   → `introuvable`, motif « coupe » — une décision de la plateforme,
 *    jamais comptée avec les fautes de frappe, sous peine de diagnostiquer un problème
 *    de nommage qui n'existe pas.
 *
 * Et une propriété de sécurité : un outil coupé en console reste introuvable, sans
 * qu'aucun rattrapage ne soit même tenté sur son nom.
 */
class RattrapageJournaliseTest extends TestCase
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    private array $enregistrements = [];

    protected function setUp(): void
    {
        $this->enregistrements = [];
    }

    public function testUnNomEcorcheLaisseUneLigneDeRattrapage(): void
    {
        $executeur = $this->executeur(['rechercher_entites']);

        $executeur->executer('rechercher_entite', [], $this->scope(), Trousse::LECTURE);

        $ligne = $this->ligne('rattrapage');
        self::assertNotNull($ligne, 'Un rattrapage doit laisser une trace sur le canal des jetons.');
        self::assertSame('rechercher_entite', $ligne['demande']);
        self::assertSame('rechercher_entites', $ligne['execute']);
        self::assertSame('lecture', $ligne['trousse']);
        // L'ORIGINE DÉCIDE DU RETRAIT. Une ressemblance est un filet dont on espère
        // qu'il se videra ; un alias est un choix qu'on retirera à la revue. Le
        // rapport doit pouvoir les distinguer sans relire le code.
        self::assertSame(JournalTokens::ORIGINE_DISTANCE, $ligne['origine']);
    }

    public function testUnNomInconnuLaisseUneLigneIntrouvable(): void
    {
        $executeur = $this->executeur(['rechercher_entites']);

        // « lecture_donnees » ne ressemble à rien du catalogue : c'est l'un des deux
        // noms réellement inventés que le rattrapage ne sauve pas.
        $resultat = $executeur->executer('lecture_donnees', [], $this->scope(), Trousse::LECTURE);

        self::assertNull($this->ligne('rattrapage'), 'Rien ne doit être rattrapé sur un nom trop lointain.');
        $ligne = $this->ligne('introuvable');
        self::assertNotNull($ligne);
        self::assertSame('lecture_donnees', $ligne['nom']);
        self::assertSame(JournalTokens::MOTIF_INCONNU, $ligne['motif']);
        self::assertSame('lecture', $ligne['trousse']);
        self::assertSame(AiToolResult::STATUS_INTROUVABLE, $resultat->status);
    }

    public function testUnOutilCoupeEnConsoleEstIntrouvableEtJamaisRattrape(): void
    {
        $executeur = $this->executeur(['rechercher_entites'], coupes: ['rechercher_entites']);

        $executeur->executer('rechercher_entites', [], $this->scope(), Trousse::LECTURE);

        $ligne = $this->ligne('introuvable');
        self::assertNotNull($ligne);
        self::assertSame(JournalTokens::MOTIF_COUPE, $ligne['motif']);
        // ⚠ LA PROPRIÉTÉ DE SÉCURITÉ : un outil coupé n'est atteignable ni par son nom
        // exact, ni par un voisin. Le contrôle passe AVANT toute résolution.
        self::assertNull($this->ligne('rattrapage'));
    }

    /**
     * UN NOM ÉCORCHÉ VISANT UN OUTIL COUPÉ NE LE RÉVEILLE PAS.
     *
     * Le cas est plus retors que le précédent : ici le nom demandé n'est pas celui de
     * l'outil coupé, mais son voisin à une lettre près. Le contrôle de coupure porte
     * sur le nom DEMANDÉ ; c'est le catalogue, qui n'énumère plus l'outil coupé, qui
     * ferme la porte au rattrapage.
     */
    public function testUneEcorchureNeReveillePasUnOutilCoupe(): void
    {
        $executeur = $this->executeur(['rechercher_entites'], coupes: ['rechercher_entites']);

        $executeur->executer('rechercher_entite', [], $this->scope(), Trousse::LECTURE);

        self::assertNull($this->ligne('rattrapage'), 'Un outil coupé ne se rattrape pas par un voisin.');
        $ligne = $this->ligne('introuvable');
        self::assertNotNull($ligne);
        self::assertSame(JournalTokens::MOTIF_INCONNU, $ligne['motif']);
    }

    /**
     * LE RAPPORT SÉPARE LES TROIS CHIFFRES — c'est tout l'objet du lot.
     *
     * Le rattrapage fait baisser les appels INTROUVABLES ; il ne fait pas baisser les
     * noms INVENTÉS. Un agrégat qui les confondrait laisserait croire le problème de
     * nommage résolu parce qu'on en a masqué les effets.
     */
    public function testLeRapportSepareRattrapesIntrouvablesEtCoupes(): void
    {
        $executeur = $this->executeur(['rechercher_entites', 'analyse_portefeuille'], coupes: ['statistiques']);
        $scope = $this->scope();

        $executeur->executer('rechercher_entite', [], $scope, Trousse::LECTURE);        // rattrapé
        $executeur->executer('analyser_portefeuille', [], $scope, Trousse::LECTURE);    // rattrapé
        $executeur->executer('lecture_donnees', [], $scope, Trousse::LECTURE);          // introuvable
        $executeur->executer('statistiques', [], $scope, Trousse::LECTURE);             // coupé

        $rapport = new RapportTokens(array_map(
            static fn (array $e): array => $e['context'],
            $this->enregistrements,
        ));

        $ecorches = $rapport->nomsEcorches();
        self::assertSame(2, $ecorches['rattrapes']);
        self::assertSame(1, $ecorches['introuvables']);
        self::assertSame(1, $ecorches['coupes']);

        // L'outil coupé ne figure PAS dans le détail par nom : ce n'est pas une
        // écorchure, et l'y faire apparaître inviterait à le renommer pour rien.
        self::assertArrayNotHasKey('statistiques', $ecorches['parNom']);
        self::assertSame('rechercher_entites', $ecorches['parNom']['rechercher_entite']['vise']);
        self::assertSame(1, $ecorches['parNom']['lecture_donnees']['introuvable']);
        self::assertSame(0, $ecorches['parNom']['lecture_donnees']['rattrape']);
    }

    /**
     * SANS TROUSSE, AUCUN RATTRAPAGE — mais la trace reste.
     *
     * Un appelant qui ne connaît pas la trousse ne sait pas ce qui était déclaré : on
     * ne devine rien. Le journal doit tout de même dire que l'appel a échoué, et que
     * la trousse était inconnue — sans quoi le rapport compterait cet échec comme s'il
     * avait eu sa chance.
     */
    public function testSansTrousseLeJournalDitQueLaTrousseEtaitInconnue(): void
    {
        $executeur = $this->executeur(['rechercher_entites']);

        $executeur->executer('rechercher_entite', [], $this->scope());

        self::assertNull($this->ligne('rattrapage'));
        $ligne = $this->ligne('introuvable');
        self::assertNotNull($ligne);
        self::assertNull($ligne['trousse']);
    }

    // ── Échafaudage ─────────────────────────────────────────────────────────────

    /**
     * @param list<string> $noms
     * @param list<string> $coupes
     */
    private function executeur(array $noms, array $coupes = []): ExecuteurDOutils
    {
        $outils = array_map(fn (string $nom): AiToolInterface => $this->outil($nom), $noms);

        // LE VRAI SERVICE DE RÉGLAGES, sur un dépôt bouchonné. `ReglagesDeKet` est
        // final — on ne le double pas — et c'est tant mieux : le test éprouve ainsi
        // la règle réelle (« absent de la carte = actif »), pas une reformulation.
        $reglages = null;
        if ($coupes !== []) {
            $parametres = (new PlateformeParametres())
                ->setKetReglages(['outils' => array_fill_keys($coupes, false)]);
            $depot = $this->createMock(PlateformeParametresRepository::class);
            $depot->method('getSingleton')->willReturn($parametres);
            $reglages = new ReglagesDeKet($depot);
        }

        return new ExecuteurDOutils($outils, $reglages, new TrousseCatalogue($outils, $reglages), $this->journal());
    }

    private function outil(string $nom): AiToolInterface
    {
        return new class($nom) implements AiToolInterface {
            public function __construct(private readonly string $nom)
            {
            }

            public function name(): string
            {
                return $this->nom;
            }

            public function description(): string
            {
                return 'Outil de test.';
            }

            public function aiguillage(): string
            {
                return 'jamais, c\'est un test.';
            }

            public function schema(): array
            {
                return ['type' => 'object', 'properties' => []];
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
    }

    private function journal(): JournalTokens
    {
        $espion = new class($this->enregistrements) extends AbstractLogger {
            /** @param list<array{message: string, context: array<string, mixed>}> $enregistrements */
            public function __construct(private array &$enregistrements)
            {
            }

            public function log($level, $message, array $context = []): void
            {
                $this->enregistrements[] = ['message' => (string) $message, 'context' => $context];
            }
        };

        $journal = new JournalTokens($espion, new OutilsDePlan([]));
        $journal->nouveauMessage();

        return $journal;
    }

    private function scope(): AiScope
    {
        return new AiScope(new Entreprise(), new Invite());
    }

    /** @return array<string, mixed>|null le contexte de la PREMIÈRE ligne de cet événement */
    private function ligne(string $evenement): ?array
    {
        foreach ($this->enregistrements as $enregistrement) {
            if ($enregistrement['message'] === $evenement) {
                return $enregistrement['context'];
            }
        }

        return null;
    }
}
