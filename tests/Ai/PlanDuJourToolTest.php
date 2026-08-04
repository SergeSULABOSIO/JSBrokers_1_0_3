<?php

namespace App\Tests\Ai;

use App\Ai\Boussole\PlanDuJourService;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\PlanDuJourTool;
use App\Entity\Avenant;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Tache;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use App\Services\Note\NoteRecouvrementService;
use App\Services\Search\ChargeInviteCritereFactory;
use App\Services\Search\PortefeuilleCritereFactory;
use App\Services\Tranche\TranchePaiementService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Outil plan_du_jour : passe-plat vers PlanDuJourService (qui porte le gating
 * fail-closed et le barème d'urgence). L'outil ne fait qu'une chose de plus —
 * restreindre à une section — et cette bascule doit rester exacte, sans quoi Ket
 * annoncerait un « tout est au vert » démenti par la bulle d'ouverture.
 *
 * Le service étant final, il est construit pour de vrai avec ses collaborateurs
 * doublés, comme dans BoussoleServiceTest.
 */
class PlanDuJourToolTest extends TestCase
{
    /** @param array<string,bool> $droits */
    private function makeTool(array $droits, array $parEntite = []): PlanDuJourTool
    {
        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('canRead')->willReturnCallback(
            static fn (Invite $invite, string $shortName): bool => $droits[$shortName] ?? false,
        );

        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturnCallback(
            static function (string $entityClass) use ($parEntite): array {
                $data = $parEntite[$entityClass] ?? [];

                return ['status' => ['code' => 200], 'data' => $data, 'totalItems' => count($data)];
            }
        );

        $tranches = $this->createMock(TranchePaiementService::class);
        $tranches->method('lister')->willReturn([
            'items' => [],
            'totaux' => ['nb' => 0, 'totalSoldePrime' => 0.0, 'totalSoldeCommission' => 0.0],
            'totalItems' => 0, 'currentPage' => 1, 'totalPages' => 1,
        ]);

        $notes = $this->createMock(NoteRecouvrementService::class);
        $notes->method('lister')->willReturn([
            'items' => [], 'totaux' => ['nb' => 0, 'totalFacture' => 0.0, 'totalEncaisse' => 0.0, 'totalSolde' => 0.0],
            'totalItems' => 0, 'currentPage' => 1, 'totalPages' => 1,
        ]);

        $portefeuille = new PortefeuilleCritereFactory($this->createMock(EntityManagerInterface::class));

        return new PlanDuJourTool(new PlanDuJourService(
            $resolver,
            $search,
            new ChargeInviteCritereFactory($portefeuille),
            $tranches,
            $notes,
        ));
    }

    private function scope(): AiScope
    {
        return new AiScope(new Entreprise(), new Invite());
    }

    /** Jeu de données produisant deux sections : renouvellements (80) puis tâches (40). */
    private function deuxSections(): PlanDuJourTool
    {
        return $this->makeTool(
            ['Avenant' => true, 'Tache' => true],
            [
                Avenant::class => [(new Avenant())->setReferencePolice('POL-1')->setEndingAt(new \DateTimeImmutable('-2 days'))],
                Tache::class => [(new Tache())->setDescription('Relancer')->setToBeEndedAt(new \DateTimeImmutable('+1 day'))->setClosed(false)],
            ],
        );
    }

    public function testNomEtSchemaExposentLesSections(): void
    {
        $tool = $this->makeTool([]);

        $this->assertSame('plan_du_jour', $tool->name());
        $enum = $tool->schema()['properties']['section']['enum'];
        $this->assertContains('tout', $enum);
        $this->assertContains('commissions_a_recouvrer', $enum);
        // Aucun argument requis : « mon plan du jour ? » doit suffire.
        $this->assertSame([], $tool->schema()['required']);
    }

    public function testPlanCompletRestitueToutesLesSectionsEtLaPriorite(): void
    {
        $resultat = $this->deuxSections()->execute([], $this->scope());

        $this->assertSame(AiToolResult::STATUS_OK, $resultat->status);
        $this->assertSame(
            ['renouvellements', 'taches_assignees'],
            array_column($resultat->data['sections'], 'cle'),
        );
        $this->assertSame('renouvellements', $resultat->data['priorite']['cle']);
        $this->assertFalse($resultat->data['toutAuVert']);
    }

    public function testSectionDemandeeRestreintEtRecalculeLaPriorite(): void
    {
        $resultat = $this->deuxSections()->execute(['section' => 'taches_assignees'], $this->scope());

        $this->assertCount(1, $resultat->data['sections']);
        $this->assertSame('taches_assignees', $resultat->data['sections'][0]['cle']);
        // La priorité suit la restriction : sinon Ket citerait une section absente.
        $this->assertSame('taches_assignees', $resultat->data['priorite']['cle']);
    }

    public function testSectionSansContenuEstAuVertPasIntrouvable(): void
    {
        // Une section absente du plan signifie « rien à traiter », pas « erreur » :
        // l'outil ne doit pas refuser, il doit laisser Ket féliciter.
        $resultat = $this->deuxSections()->execute(['section' => 'primes_dues'], $this->scope());

        $this->assertSame(AiToolResult::STATUS_OK, $resultat->status);
        $this->assertSame([], $resultat->data['sections']);
        $this->assertTrue($resultat->data['toutAuVert']);
        $this->assertNull($resultat->data['priorite']);
    }

    public function testAucunDroitDonneUnPlanAuVertSansEchec(): void
    {
        $resultat = $this->makeTool([])->execute([], $this->scope());

        $this->assertSame(AiToolResult::STATUS_OK, $resultat->status);
        $this->assertTrue($resultat->data['toutAuVert']);
        $this->assertNull($resultat->data['priorite']);
    }

    public function testMatchDeclencheursEtNonCollisions(): void
    {
        $tool = $this->makeTool([]);
        $scope = $this->scope();

        foreach (['Mon plan du jour ?', 'donne-moi mon programme du jour', 'par quoi je commence', 'ma journee'] as $question) {
            $this->assertNotNull($tool->match($question, $scope), $question);
        }

        // Ne doit pas capter les questions qui relèvent d'outils dédiés.
        foreach (['combien de clients avons-nous', 'mes renouvellements sous 60 jours'] as $question) {
            $this->assertNull($tool->match($question, $scope), $question);
        }
    }
}
