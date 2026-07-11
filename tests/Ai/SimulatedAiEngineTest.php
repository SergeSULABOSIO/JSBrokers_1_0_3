<?php

namespace App\Tests\Ai;

use App\Ai\AiRequest;
use App\Ai\Engine\SimulatedAiEngine;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolInterface;
use App\Ai\Tool\AiToolResult;
use App\Entity\Entreprise;
use App\Entity\Invite;
use PHPUnit\Framework\TestCase;

/**
 * Déterminisme des branches du moteur simulé : identité, périmètre, outil de
 * données (succès + refus hors périmètre) et repli guidé. Tests purs (aucune
 * base) : les outils sont des doublures en mémoire.
 */
class SimulatedAiEngineTest extends TestCase
{
    private function makeRequest(string $question, array $perimetre = ['owner' => true, 'gestionnaire' => true, 'modules' => []]): AiRequest
    {
        return new AiRequest(
            systemContext: [
                'assistantNom'  => 'Aristote',
                'entrepriseNom' => 'Courtage Test',
                'perimetre'     => $perimetre,
                'date'          => '2026-07-10',
            ],
            messages: [['role' => 'user', 'content' => $question]],
            scope: new AiScope(new Entreprise(), new Invite()),
        );
    }

    /** Outil factice qui matche toute question contenant $declencheur. */
    private function makeTool(string $declencheur, AiToolResult $result, string $name = 'outil_test'): AiToolInterface
    {
        return new class($declencheur, $result, $name) implements AiToolInterface {
            public function __construct(
                private string $declencheur,
                private AiToolResult $result,
                private string $toolName,
            ) {
            }

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return 'Outil de test.';
            }

            public function schema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function match(string $question, AiScope $scope): ?array
            {
                return str_contains(mb_strtolower($question), $this->declencheur) ? [] : null;
            }

            public function execute(array $args, AiScope $scope): AiToolResult
            {
                return $this->result;
            }
        };
    }

    public function testIdentiteSePresenteAvecSonNom(): void
    {
        $engine = new SimulatedAiEngine([]);
        $reply = $engine->reply($this->makeRequest('Bonjour, qui es-tu ?'));

        $this->assertStringContainsString('Aristote', $reply->content);
        $this->assertStringContainsString('Courtage Test', $reply->content);
        $this->assertFalse($reply->refused);
        $this->assertNull($reply->toolUsed);
    }

    public function testPerimetreProprietaire(): void
    {
        $engine = new SimulatedAiEngine([]);
        $reply = $engine->reply($this->makeRequest('Quel est mon périmètre d\'accès ?'));

        $this->assertStringContainsString('propriétaire', $reply->content);
        $this->assertStringContainsString('accès complet', $reply->content);
    }

    public function testPerimetreInviteAvecModules(): void
    {
        $perimetre = [
            'owner' => false,
            'gestionnaire' => false,
            'modules' => [
                ['nom' => 'Production', 'entites' => [['nom' => 'Clients', 'niveaux' => ['Lecture']]]],
            ],
        ];
        $engine = new SimulatedAiEngine([]);
        $reply = $engine->reply($this->makeRequest('Quels sont mes droits ?', $perimetre));

        $this->assertStringContainsString('Production', $reply->content);
        $this->assertStringContainsString('Clients (Lecture)', $reply->content);
    }

    public function testPerimetreInviteSansAucunDroit(): void
    {
        $perimetre = ['owner' => false, 'gestionnaire' => false, 'modules' => []];
        $engine = new SimulatedAiEngine([]);
        $reply = $engine->reply($this->makeRequest('Quels sont mes accès ?', $perimetre));

        $this->assertStringContainsString('Aucun droit', $reply->content);
    }

    public function testOutilDeDonneesRepondAvecLaValeur(): void
    {
        $tool = $this->makeTool(
            'combien de clients',
            AiToolResult::ok(['entite' => 'Client', 'libelle' => 'Clients', 'count' => 3]),
            'compter_entites',
        );
        $engine = new SimulatedAiEngine([$tool]);
        $reply = $engine->reply($this->makeRequest('Combien de clients avons-nous ?'));

        $this->assertStringContainsString('3', $reply->content);
        $this->assertStringContainsString('Clients', $reply->content);
        $this->assertFalse($reply->refused);
        $this->assertSame('compter_entites', $reply->toolUsed);
    }

    public function testListeDEnregistrementsAvecPagination(): void
    {
        $tool = $this->makeTool(
            'liste',
            AiToolResult::ok([
                'entite'     => 'Client',
                'libelle'    => 'Clients',
                'page'       => 1,
                'totalPages' => 2,
                'totalItems' => 22,
                'items'      => [
                    ['id' => 1, 'libelle' => 'Client Alpha'],
                    ['id' => 2, 'libelle' => 'Client Beta'],
                ],
            ]),
            'rechercher_entites',
        );
        $engine = new SimulatedAiEngine([$tool]);
        $reply = $engine->reply($this->makeRequest('Liste nos clients'));

        $this->assertStringContainsString('22 enregistrements', $reply->content);
        $this->assertStringContainsString('- Client Alpha', $reply->content);
        $this->assertStringContainsString('- Client Beta', $reply->content);
        $this->assertStringContainsString('page 1/2', $reply->content);
        $this->assertStringContainsString('page suivante', $reply->content);
        $this->assertSame('rechercher_entites', $reply->toolUsed);
    }

    public function testListeVideResteExplicite(): void
    {
        $tool = $this->makeTool(
            'liste',
            AiToolResult::ok([
                'entite'     => 'Client',
                'libelle'    => 'Clients',
                'filtre'     => 'zzz',
                'page'       => 1,
                'totalPages' => 1,
                'totalItems' => 0,
                'items'      => [],
            ]),
            'rechercher_entites',
        );
        $engine = new SimulatedAiEngine([$tool]);
        $reply = $engine->reply($this->makeRequest('Liste les clients zzz'));

        $this->assertStringContainsString('aucun enregistrement', $reply->content);
        $this->assertStringContainsString('zzz', $reply->content);
    }

    public function testActionOuvertureDialogueRemonteDansLaReponse(): void
    {
        $uiAction = ['type' => 'open-dialog', 'entite' => 'Client', 'mode' => 'creation'];
        $tool = $this->makeTool(
            'crée',
            AiToolResult::ok(
                ['entite' => 'Client', 'libelle' => 'Clients', 'mode' => 'creation'],
                $uiAction,
            ),
            'ouvrir_dialogue',
        );
        $engine = new SimulatedAiEngine([$tool]);
        $reply = $engine->reply($this->makeRequest('Crée un nouveau client'));

        $this->assertStringContainsString('formulaire', $reply->content);
        $this->assertStringContainsString('Clients', $reply->content);
        $this->assertSame([$uiAction], $reply->actions);
        $this->assertSame('ouvrir_dialogue', $reply->toolUsed);
    }

    public function testReponseSansActionResteVide(): void
    {
        $tool = $this->makeTool(
            'combien de clients',
            AiToolResult::ok(['entite' => 'Client', 'libelle' => 'Clients', 'count' => 3]),
            'compter_entites',
        );
        $engine = new SimulatedAiEngine([$tool]);
        $reply = $engine->reply($this->makeRequest('Combien de clients avons-nous ?'));

        $this->assertSame([], $reply->actions);
    }

    public function testRefusPoliHorsPerimetre(): void
    {
        $tool = $this->makeTool('combien de clients', AiToolResult::horsPerimetre('Clients'), 'compter_entites');
        $engine = new SimulatedAiEngine([$tool]);
        $reply = $engine->reply($this->makeRequest('Combien de clients avons-nous ?'));

        $this->assertTrue($reply->refused);
        $this->assertStringContainsString('périmètre', $reply->content);
        $this->assertStringContainsString('Clients', $reply->content);
        $this->assertStringNotContainsString('3', $reply->content);
    }

    public function testRepliGuideProposeDesExemplesDuPerimetre(): void
    {
        $perimetre = [
            'owner' => false,
            'gestionnaire' => false,
            'modules' => [
                ['nom' => 'Marketing', 'entites' => [['nom' => 'Pistes', 'niveaux' => ['Lecture']]]],
            ],
        ];
        $engine = new SimulatedAiEngine([]);
        $reply = $engine->reply($this->makeRequest('Blabla incompréhensible', $perimetre));

        $this->assertStringContainsString('Aristote', $reply->content);
        $this->assertStringContainsString('Combien de pistes', $reply->content);
    }
}
