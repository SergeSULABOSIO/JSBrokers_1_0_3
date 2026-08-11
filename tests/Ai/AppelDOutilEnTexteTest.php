<?php

namespace App\Tests\Ai;

use App\Ai\Engine\AppelDOutilEnTexte;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolInterface;
use App\Ai\Tool\AiToolResult;
use PHPUnit\Framework\TestCase;

/**
 * UN APPEL D'OUTIL ÉCRIT EN TEXTE doit être exécuté, pas affiché.
 *
 * L'incident (2026-08-11) : à « J'ai une dépense à enregistrer, que faire ? », Ket
 * a répondu la ligne `consulter_guide(parcours-de-saisie)` — le journal confirme
 * zéro appel de fonction émis. L'outil existait, l'argument était juste ; seul le
 * canal était faux. L'utilisateur, lui, a lu nos rouages et répondu « Je ne
 * comprends rien !! ».
 *
 * La contrepartie de ce rattrapage est la PRÉCISION : une phrase qui se contente de
 * mentionner un outil ne doit rien déclencher.
 */
class AppelDOutilEnTexteTest extends TestCase
{
    private AppelDOutilEnTexte $extracteur;

    /** @var list<AiToolInterface> */
    private array $outils;

    protected function setUp(): void
    {
        $this->extracteur = new AppelDOutilEnTexte();
        $this->outils = [
            $this->outil('consulter_guide', ['sujet' => ['type' => 'string']], ['sujet']),
            $this->outil('lire_fiche', ['entite' => ['type' => 'string'], 'id' => ['type' => 'integer']], ['entite', 'id']),
            $this->outil('solde_tokens', [], []),
        ];
    }

    /** LE CAS DE L'INCIDENT, mot pour mot. */
    public function testLAppelPositionnelDeLIncidentEstRattrape(): void
    {
        $appels = $this->extracteur->extraire('consulter_guide(parcours-de-saisie)', $this->outils);

        $this->assertSame([['name' => 'consulter_guide', 'args' => ['sujet' => 'parcours-de-saisie']]], $appels);
    }

    /**
     * @dataProvider formesConnues
     */
    public function testLesFormesConnuesSontComprises(string $texte, array $attendu): void
    {
        $this->assertSame($attendu, $this->extracteur->extraire($texte, $this->outils));
    }

    public static function formesConnues(): iterable
    {
        $guide = static fn (string $sujet) => [['name' => 'consulter_guide', 'args' => ['sujet' => $sujet]]];

        yield 'argument nommé' => ['consulter_guide(sujet="parcours-de-saisie")', $guide('parcours-de-saisie')];
        yield 'apostrophes' => ["consulter_guide(sujet='paiement-prime')", $guide('paiement-prime')];
        yield 'deux-points' => ['consulter_guide(sujet: "bordereau")', $guide('bordereau')];
        yield 'bloc de code' => ["```tool_code\nconsulter_guide(sujet=\"bordereau\")\n```", $guide('bordereau')];
        yield 'enveloppe print' => ['print(consulter_guide(sujet="bordereau"))', $guide('bordereau')];
        yield 'point-virgule' => ['consulter_guide(sujet="bordereau");', $guide('bordereau')];
        yield 'sans paramètre' => ['solde_tokens()', [['name' => 'solde_tokens', 'args' => []]]];
        yield 'plusieurs arguments nommés, retypés' => [
            'lire_fiche(entite="Client", id=42)',
            [['name' => 'lire_fiche', 'args' => ['entite' => 'Client', 'id' => 42]]],
        ];
    }

    /**
     * @dataProvider casQuiNeDoiventRienDeclencher
     */
    public function testRienNEstDeclencheHorsDUnAppelPur(string $texte): void
    {
        $this->assertSame([], $this->extracteur->extraire($texte, $this->outils));
    }

    public static function casQuiNeDoiventRienDeclencher(): iterable
    {
        // Nommer un outil pour EXPLIQUER un manque est un comportement légitime :
        // c'est même ce que le prompt demande. Il ne doit jamais rien exécuter.
        yield 'outil cité dans une phrase' => [
            'Il me faudrait consulter_guide(parcours-de-saisie) mais la fiche manque.',
        ];
        yield 'réponse ordinaire' => ['Voici les trois clients de votre portefeuille.'];
        // Un outil NON DÉCLARÉ ce tour-ci : le rattraper contournerait la trousse.
        yield 'outil non déclaré' => ['preparer_operations(entite="Client")'];
        yield 'outil inexistant' => ['chercher_client(nom="Marlette")'];
        // Un paramètre que l'outil ne connaît pas : on n'arrange rien au jugé.
        yield 'paramètre inconnu' => ['consulter_guide(fiche="bordereau")'];
        // Deux paramètres requis : impossible de deviner à qui va la valeur.
        yield 'positionnel ambigu' => ['lire_fiche(Client)'];
        yield 'vide' => [''];
    }

    /**
     * FILET DE SÛRETÉ D'AFFICHAGE : même non rattrapable, un appel écrit en texte ne
     * doit pas être servi à un courtier.
     */
    public function testUnAppelNonRattrapableEstMalgreToutReconnu(): void
    {
        $this->assertTrue($this->extracteur->ressembleAUnAppel('chercher_client(nom="Marlette")'));
        $this->assertTrue($this->extracteur->ressembleAUnAppel("```python\nprint(solde_tokens())\n```"));
        $this->assertFalse($this->extracteur->ressembleAUnAppel('Voici les trois clients de votre portefeuille.'));
        $this->assertFalse($this->extracteur->ressembleAUnAppel('Le solde (disponible) est de 115 321 unités.'));
    }

    /**
     * @param array<string, array> $proprietes
     * @param list<string>         $requis
     */
    private function outil(string $nom, array $proprietes, array $requis): AiToolInterface
    {
        return new class($nom, $proprietes, $requis) implements AiToolInterface {
            public function __construct(
                private readonly string $nom,
                private readonly array $proprietes,
                private readonly array $requis,
            ) {
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
                return '';
            }

            public function schema(): array
            {
                return ['type' => 'object', 'properties' => $this->proprietes, 'required' => $this->requis];
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
}
