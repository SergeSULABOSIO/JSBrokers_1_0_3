<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\EntiteLexique;
use App\Ai\Tool\EntiteLibelle;
use App\Ai\Tool\IndicateurCalculeTool;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\Canvas\CalculationProvider;
use App\Services\Canvas\CanvasHelper;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Indicateurs calculés au niveau ENTREPRISE : la période du/au doit être
 * réellement appliquée par le moteur (format d/m/Y + option reper, sans quoi
 * elle serait silencieusement ignorée), et le payload doit transmettre la
 * sémantique (description + base : generee/encaissee/solde/taux) pour éviter
 * la confusion CA / production / encaissé.
 */
class IndicateurCalculeToolTest extends TestCase
{
    /** Petit dictionnaire d'indicateurs (mêmes clés que CanvasHelper). */
    private const CANVAS = [
        ['code' => 'commission_totale_encaissee', 'intitule' => 'Commission Encaissée',
            'description' => 'Commission effectivement perçue par le courtier.', 'unite' => 'USD'],
        ['code' => 'commission_totale', 'intitule' => 'Commission Totale',
            'description' => 'Commission TTC due au courtier.', 'unite' => 'USD'],
        ['code' => 'commission_totale_solde', 'intitule' => 'Solde Commission',
            'description' => 'Commission restant à encaisser.', 'unite' => 'USD'],
        ['code' => 'taux_de_commission', 'intitule' => 'Taux de Commission',
            'description' => 'Commission nette / prime nette.', 'unite' => '%'],
    ];

    /** @var array<string,mixed>|null options captées lors du dernier appel moteur */
    private ?array $captured = null;

    private function makeTool(array $stats): IndicateurCalculeTool
    {
        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('canRead')->willReturn(true);

        $canvas = $this->createMock(CanvasHelper::class);
        $canvas->method('getGlobalIndicatorsCanvas')->willReturn(self::CANVAS);

        $provider = $this->createMock(CalculationProvider::class);
        $provider->method('getIndicateursGlobaux')->willReturnCallback(
            function (Entreprise $e, bool $b, array $options) use ($stats): array {
                $this->captured = $options;

                return $stats;
            },
        );

        return new IndicateurCalculeTool(
            $resolver,
            $canvas,
            $provider,
            $this->createMock(JSBDynamicSearchService::class),
            new EntiteLexique($resolver),
            new EntiteLibelle($this->createMock(EntityManagerInterface::class)),
        );
    }

    private function makeScope(): AiScope
    {
        return new AiScope(new Entreprise(), new Invite());
    }

    public function testPeriodeConvertieEnDmYAvecReper(): void
    {
        $tool = $this->makeTool(['commission_totale_encaissee' => 1234.0]);
        $result = $tool->execute([
            'code' => 'commission_totale_encaissee',
            'entite' => 'Entreprise',
            'du' => '2026-01-01',
            'au' => '2026-06-30',
        ], $this->makeScope());

        // Le moteur reçoit bien le format d/m/Y ET l'option reper (sinon période ignorée).
        $this->assertSame('01/01/2026', $this->captured['entre']);
        $this->assertSame('30/06/2026', $this->captured['et']);
        $this->assertSame('dateEffet', $this->captured['reper']);

        // La réponse ré-écho la période en AAAA-MM-JJ lisible.
        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame('2026-01-01', $result->data['du']);
        $this->assertSame('2026-06-30', $result->data['au']);
    }

    public function testReperEcheanceRespecte(): void
    {
        $tool = $this->makeTool(['commission_totale_encaissee' => 1.0]);
        $tool->execute([
            'code' => 'commission_totale_encaissee',
            'entite' => 'Entreprise',
            'du' => '2026-01-01',
            'au' => '2026-12-31',
            'reper' => 'dateEcheance',
        ], $this->makeScope());

        $this->assertSame('dateEcheance', $this->captured['reper']);
    }

    public function testUneSeuleBorneNActivePasLaPeriode(): void
    {
        $tool = $this->makeTool(['commission_totale_encaissee' => 1.0]);
        $result = $tool->execute([
            'code' => 'commission_totale_encaissee',
            'entite' => 'Entreprise',
            'du' => '2026-01-01', // pas de « au »
        ], $this->makeScope());

        // Sans les deux bornes, aucun reper => le moteur n'aurait pas filtré ;
        // la réponse ne doit pas prétendre à une période.
        $this->assertArrayNotHasKey('reper', $this->captured);
        $this->assertArrayNotHasKey('du', $result->data);
        $this->assertArrayNotHasKey('au', $result->data);
    }

    public function testPayloadPorteDescriptionEtBase(): void
    {
        $tool = $this->makeTool([
            'commission_totale_encaissee' => 900.0,
            'commission_totale' => 1000.0,
            'commission_totale_solde' => 100.0,
            'taux_de_commission' => 15.0,
        ]);
        $scope = $this->makeScope();

        $encaissee = $tool->execute(['code' => 'commission_totale_encaissee', 'entite' => 'Entreprise'], $scope);
        $this->assertSame('encaissee', $encaissee->data['base']);
        $this->assertStringContainsString('perçue', $encaissee->data['description']);

        $generee = $tool->execute(['code' => 'commission_totale', 'entite' => 'Entreprise'], $scope);
        $this->assertSame('generee', $generee->data['base']);

        $solde = $tool->execute(['code' => 'commission_totale_solde', 'entite' => 'Entreprise'], $scope);
        $this->assertSame('solde', $solde->data['base']);

        $taux = $tool->execute(['code' => 'taux_de_commission', 'entite' => 'Entreprise'], $scope);
        $this->assertSame('taux', $taux->data['base']);
    }

    /**
     * L'AGENT ÉTAIT UNE CIBLE DÉCLARÉE MAIS INATTEIGNABLE.
     *
     * CIBLES annonce « Invite => inviteCible » depuis toujours, et le moteur sait
     * parfaitement ventiler les chiffres d'un apporteur. Mais l'outil exigeait d'abord que
     * l'entité figure dans les libellés d'écran — or Invite n'y est pas, il relève du droit
     * de gestion des invités. La ligne était donc du code mort, et « la commission apportée
     * par tel agent » répondait INTROUVABLE sans que rien n'explique pourquoi.
     */
    public function testUnAgentEstUneCibleAtteignable(): void
    {
        $tool = $this->makeTool(['commission_totale' => 4200.0]);
        $result = $tool->execute([
            'code' => 'commission_totale',
            'entite' => 'Invite',
            'id' => 7,
        ], $this->makeScope());

        // Le refus par libellé manquant a disparu : ce qui échoue désormais, s'il échoue,
        // c'est la recherche de l'agent — pas la porte d'entrée.
        self::assertNotSame(
            'Invite',
            $result->data['precision'] ?? null,
            'La cible ne doit plus être rejetée au motif qu\'elle n\'a pas de rubrique.',
        );
    }

    public function testSansLePrivilegeDeGestionLAgentResteRefuseEtEstNomme(): void
    {
        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('canRead')->willReturn(false);

        $canvas = $this->createMock(CanvasHelper::class);
        $canvas->method('getGlobalIndicatorsCanvas')->willReturn(self::CANVAS);

        $tool = new IndicateurCalculeTool(
            $resolver,
            $canvas,
            $this->createMock(CalculationProvider::class),
            $this->createMock(JSBDynamicSearchService::class),
            new EntiteLexique($resolver),
            new EntiteLibelle($this->createMock(EntityManagerInterface::class)),
        );

        $result = $tool->execute([
            'code' => 'commission_totale',
            'entite' => 'Invite',
            'id' => 7,
        ], $this->makeScope());

        // La garde n'a pas bougé — seule la porte d'entrée a cessé de mentir. Et le refus
        // NOMME l'objet : « Invite » ne dit rien à personne.
        self::assertSame(AiToolResult::STATUS_HORS_PERIMETRE, $result->status);
        self::assertSame('Agents et invités du cabinet', $result->data['libelle']);
    }
}