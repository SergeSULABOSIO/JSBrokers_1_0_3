<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\MutationAllowlist;
use App\Ai\Mutation\MutationOperation;
use App\Ai\Mutation\MutationPlan;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\PreparerOperationsTool;
use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Service\Workspace\CascadeImpact;
use App\Service\Workspace\CascadeImpactAnalyzer;
use App\Service\Workspace\ChampsObligatoiresInspector;
use App\Service\Workspace\MutationException;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Service\Workspace\WorkspaceMutationService;
use App\Services\JSBDynamicSearchService;
use App\Token\ParametresTokenService;
use App\Token\TokenAccountService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Capacité d'écriture/suppression de l'assistant IA (Ket), cœur DÉTERMINISTE et
 * fail-closed. Tests purs (mocks) : DTO d'intention, périmètre/allowlist,
 * scoping entreprise, budget en tokens, dry-run vs exécution, autorisation.
 */
class KetMutationTest extends TestCase
{
    // ───────────────────────── Allowlist & DTO ────────────────────────────────

    public function testAllowlistNAutoriseQueLesDonneesMetier(): void
    {
        foreach (['Client', 'Tache', 'Note', 'Piste', 'Avenant'] as $membre) {
            $this->assertTrue(MutationAllowlist::autorise($membre));
        }
        // Paramétrage / rôles / hors liste : jamais mutable par Ket.
        foreach (['Monnaie', 'Taxe', 'Invite', 'RolesEnProduction', 'AssistantParametres', 'Entreprise'] as $exclu) {
            $this->assertFalse(MutationAllowlist::autorise($exclu), $exclu . ' ne doit pas être mutable');
        }
    }

    public function testNiveauxRequisSelonOperation(): void
    {
        $this->assertSame(Invite::ACCESS_ECRITURE, (new MutationOperation('create', 'Client'))->requiredLevel());
        $this->assertSame(Invite::ACCESS_MODIFICATION, (new MutationOperation('edit', 'Client', 5))->requiredLevel());
        $this->assertSame(Invite::ACCESS_SUPPRESSION, (new MutationOperation('delete', 'Client', 5))->requiredLevel());
    }

    public function testValiditeOperation(): void
    {
        $this->assertTrue((new MutationOperation('create', 'Client'))->estValide());
        $this->assertTrue((new MutationOperation('edit', 'Client', 5))->estValide());
        $this->assertFalse((new MutationOperation('edit', 'Client', null))->estValide(), 'edit exige un id');
        $this->assertFalse((new MutationOperation('delete', 'Client', 0))->estValide(), 'delete exige un id > 0');
        $this->assertFalse((new MutationOperation('bidon', 'Client'))->estValide());
    }

    public function testPlanOrdonneCreationsAvantSuppressions(): void
    {
        $plan = new MutationPlan([
            new MutationOperation('delete', 'Tache', 3),
            new MutationOperation('create', 'Client', null, ['nom' => 'X']),
            new MutationOperation('edit', 'Note', 7),
        ]);

        $ops = $plan->operationsOrdonnees();
        $this->assertSame(['create', 'edit', 'delete'], array_map(static fn ($o) => $o->op, $ops));
        $this->assertTrue($plan->contientSuppression());
    }

    public function testOperationLitLesChampsEnvoyesParLeLLM(): void
    {
        // Le schéma de l'outil expose la clé « champs » : elle doit être lue
        // (sinon les valeurs dictées sont perdues → rien n'est modifié en base).
        $op = MutationOperation::fromArray([
            'op' => 'edit', 'entite' => 'Client', 'id' => 63,
            'champs' => ['telephone' => '+243999888777'],
        ]);

        $this->assertSame(63, $op->targetId);
        $this->assertSame('+243999888777', $op->fields['telephone']);
    }

    public function testPlanSerialisationAllerRetour(): void
    {
        $plan = new MutationPlan([
            new MutationOperation('create', 'Client', null, ['nom' => 'Kabila']),
            new MutationOperation('delete', 'Tache', 9),
        ]);
        $reconstruit = MutationPlan::fromArray($plan->toArray());

        $this->assertCount(2, $reconstruit->operations);
        $this->assertSame('Kabila', $reconstruit->operations[0]->fields['nom']);
        $this->assertSame(9, $reconstruit->operations[1]->targetId);
    }

    // ───────────────────────── Outil preparer_operations ──────────────────────

    private function makeScope(): AiScope
    {
        return new AiScope(new Entreprise(), new Invite());
    }

    /**
     * @param array<string, array> $analyses  résultats indexés par nom d'entité
     */
    private function makeTool(array $analysesParEntite, int $cout = 10, int $solde = 1000): PreparerOperationsTool
    {
        $mutation = $this->createMock(WorkspaceMutationService::class);
        $mutation->method('analyserOperation')->willReturnCallback(
            static fn (MutationOperation $op) => $analysesParEntite[$op->entityShortName] ?? ['ok' => true, 'statut' => 'ok', 'entite' => $op->entityShortName, 'libelle' => $op->entityShortName, 'cible' => null, 'manquants' => [], 'impacts' => []],
        );

        $tokens = $this->createMock(TokenAccountService::class);
        $tokens->method('estimateWriteCost')->willReturn($cout);
        $tokens->method('availableFor')->willReturn($solde);

        return new PreparerOperationsTool($mutation, $tokens);
    }

    public function testOutilRefuseToutHorsPerimetre(): void
    {
        $tool = $this->makeTool([
            'Client' => ['ok' => false, 'statut' => 'hors_perimetre', 'entite' => 'Client', 'libelle' => 'Clients', 'cible' => null, 'manquants' => [], 'impacts' => []],
        ]);

        $result = $tool->execute(['operations' => [['op' => 'delete', 'entite' => 'Client', 'id' => 5]]], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_HORS_PERIMETRE, $result->status);
        $this->assertNull($result->uiAction);
    }

    public function testOutilSignaleLesChampsManquants(): void
    {
        $tool = $this->makeTool([
            'Client' => ['ok' => false, 'statut' => 'invalide', 'entite' => 'Client', 'libelle' => 'Clients', 'cible' => null, 'manquants' => ['nom' => ['Cette valeur ne doit pas être vide.']], 'impacts' => []],
        ]);

        $result = $tool->execute(['operations' => [['op' => 'create', 'entite' => 'Client', 'champs' => []]]], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertFalse($result->data['pret']);
        $this->assertNotEmpty($result->data['manquants']);
        $this->assertNull($result->uiAction, 'Aucun plan présenté tant que des champs manquent');
    }

    public function testOutilSignaleUnBlocageDeSuppression(): void
    {
        $tool = $this->makeTool([
            'Piste' => ['ok' => false, 'statut' => 'bloque', 'entite' => 'Piste', 'libelle' => 'Pistes', 'cible' => 'Piste A', 'manquants' => [], 'impacts' => ['Suppression impossible : 2 Avenant rattachés.']],
        ]);

        $result = $tool->execute(['operations' => [['op' => 'delete', 'entite' => 'Piste', 'id' => 1]]], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertFalse($result->data['pret']);
        $this->assertNotEmpty($result->data['blocages']);
        $this->assertNull($result->uiAction);
    }

    public function testOutilPresenteLePlanEtLeBudgetQuandToutEstPret(): void
    {
        $tool = $this->makeTool([], cout: 40, solde: 1000);

        $result = $tool->execute([
            'operations' => [
                ['op' => 'edit', 'entite' => 'Client', 'id' => 5, 'champs' => ['telephone' => '+243900000000']],
            ],
        ], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertTrue($result->data['pret']);
        $this->assertSame(40, $result->data['budget']['coutEstime']);
        $this->assertSame(1000, $result->data['budget']['soldeDisponible']);
        $this->assertTrue($result->data['budget']['suffisant']);
        $this->assertIsArray($result->uiAction);
        $this->assertSame('ket-mutation.review', $result->uiAction['type']);
        $this->assertFalse($result->uiAction['requiresPassword'], 'Pas de suppression → pas de mot de passe');
    }

    public function testLePlanStockeConserveLesChamps(): void
    {
        // Régression : les valeurs voyagent jusqu'au plan (uiAction) stocké côté
        // serveur — sinon l'exécution ne modifie rien.
        $tool = $this->makeTool([]);

        $result = $tool->execute([
            'operations' => [
                ['op' => 'edit', 'entite' => 'Client', 'id' => 63, 'champs' => ['telephone' => '+243900112233']],
            ],
        ], $this->makeScope());

        $this->assertTrue($result->data['pret']);
        $this->assertSame('+243900112233', $result->uiAction['plan'][0]['fields']['telephone']);
        $this->assertSame(63, $result->uiAction['plan'][0]['targetId']);
    }

    public function testBudgetInsuffisantProposeAchatOuAbandon(): void
    {
        $tool = $this->makeTool([], cout: 500, solde: 100);

        $result = $tool->execute([
            'operations' => [['op' => 'create', 'entite' => 'Client', 'champs' => ['nom' => 'Kabila']]],
        ], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertFalse($result->data['budget']['suffisant']);
        $this->assertStringContainsString('INSUFFISANT', $result->data['note']);
        // Le plan reste présenté (uiAction) pour que l'utilisateur décide (acheter/abandonner).
        $this->assertSame('ket-mutation.review', $result->uiAction['type']);
    }

    public function testSuppressionExigeUnMotDePasse(): void
    {
        $tool = $this->makeTool([
            'Tache' => ['ok' => true, 'statut' => 'ok', 'entite' => 'Tache', 'libelle' => 'Tâches', 'cible' => 'Relancer', 'manquants' => [], 'impacts' => []],
        ]);

        $result = $tool->execute(['operations' => [['op' => 'delete', 'entite' => 'Tache', 'id' => 3]]], $this->makeScope());

        $this->assertTrue($result->data['pret']);
        $this->assertTrue($result->data['requiresPassword']);
        $this->assertTrue($result->uiAction['requiresPassword']);
    }

    // ─────────────────── Cœur de mutation : fail-closed & scope ────────────────

    private function makeMutationService(
        WorkspaceAccessResolver $resolver,
        ?JSBDynamicSearchService $search = null,
        ?CascadeImpactAnalyzer $cascade = null,
        ?EntityManagerInterface $em = null,
        ?FormFactoryInterface $forms = null,
    ): WorkspaceMutationService {
        $emResolved = $em ?? $this->createMock(EntityManagerInterface::class);
        $formsResolved = $forms ?? $this->formFactoryJamaisAppele();

        return new WorkspaceMutationService(
            $emResolved,
            $formsResolved,
            $resolver,
            $this->createMock(TokenAccountService::class),
            $search ?? $this->createMock(JSBDynamicSearchService::class),
            $cascade ?? $this->createMock(CascadeImpactAnalyzer::class),
            new ChampsObligatoiresInspector($emResolved, $formsResolved),
        );
    }

    private function formFactoryJamaisAppele(): FormFactoryInterface
    {
        $forms = $this->createMock(FormFactoryInterface::class);
        $forms->expects($this->never())->method('create');

        return $forms;
    }

    private function resolver(bool $can): WorkspaceAccessResolver
    {
        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('libellesEntites')->willReturn(['Client' => 'Clients', 'Tache' => 'Tâches', 'Piste' => 'Pistes']);
        $resolver->method('isRoleManagementEntity')->willReturn(false);
        $resolver->method('can')->willReturn($can);

        return $resolver;
    }

    private function searchRetournant(?object $entity): JSBDynamicSearchService
    {
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturn([
            'status' => ['code' => 200], 'data' => $entity ? [$entity] : [], 'totalItems' => $entity ? 1 : 0,
        ]);

        return $search;
    }

    public function testDryRunRefuseEntiteHorsAllowlist(): void
    {
        // Monnaie n'est pas dans l'allowlist : refus AVANT toute recherche/formulaire.
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->expects($this->never())->method('search');

        $service = $this->makeMutationService($this->resolver(true), $search);
        $analyse = $service->analyserOperation(new MutationOperation('edit', 'Monnaie', 5), $this->makeScope());

        $this->assertSame('hors_perimetre', $analyse['statut']);
    }

    public function testDryRunFailClosedSansDroit(): void
    {
        $service = $this->makeMutationService($this->resolver(false));
        $analyse = $service->analyserOperation(new MutationOperation('delete', 'Client', 5), $this->makeScope());

        $this->assertSame('hors_perimetre', $analyse['statut']);
    }

    public function testDryRunCibleHorsEntrepriseIntrouvable(): void
    {
        $service = $this->makeMutationService($this->resolver(true), $this->searchRetournant(null));
        $analyse = $service->analyserOperation(new MutationOperation('edit', 'Client', 999), $this->makeScope());

        $this->assertSame('introuvable', $analyse['statut']);
    }

    public function testDryRunSuppressionBloqueeParCascade(): void
    {
        $cascade = $this->createMock(CascadeImpactAnalyzer::class);
        $cascade->method('analyserSuppression')->willReturn(new CascadeImpact([], ['Suppression impossible : 2 Avenant rattachés.']));

        $service = $this->makeMutationService(
            $this->resolver(true),
            $this->searchRetournant((new Client())->setNom('Piste A')),
            $cascade,
        );
        $analyse = $service->analyserOperation(new MutationOperation('delete', 'Piste', 5), $this->makeScope());

        $this->assertSame('bloque', $analyse['statut']);
        $this->assertNotEmpty($analyse['impacts']);
        $this->assertFalse($analyse['ok']);
    }

    public function testExecuterRefuseHorsPerimetre(): void
    {
        $this->expectException(MutationException::class);

        $service = $this->makeMutationService($this->resolver(false));
        $service->executer(new MutationOperation('delete', 'Client', 5), $this->makeScope(), null);
    }

    public function testExecuterSuppressionOkSupprimeEtFlush(): void
    {
        $cible = (new Client())->setNom('Client à supprimer');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('remove')->with($cible);
        $em->expects($this->once())->method('flush');

        $cascade = $this->createMock(CascadeImpactAnalyzer::class);
        $cascade->method('analyserSuppression')->willReturn(new CascadeImpact());

        $service = $this->makeMutationService(
            $this->resolver(true),
            $this->searchRetournant($cible),
            $cascade,
            $em,
        );

        $step = $service->executer(new MutationOperation('delete', 'Client', 5), $this->makeScope(), null);

        $this->assertSame('delete', $step['op']);
        $this->assertSame('Client à supprimer', $step['cible']);
    }

    // ───────────────────────── Budget : estimation ────────────────────────────

    public function testEstimationCoutUtiliseLeBaremeUnique(): void
    {
        $parametres = $this->createMock(ParametresTokenService::class);
        $parametres->method('weightFor')->willReturnCallback(
            static fn (string $fqcn) => $fqcn === Client::class ? 30 : 10,
        );
        $tokens = new TokenAccountService($this->createMock(EntityManagerInterface::class), $parametres);

        // 2 écritures Client (30) + 1 autre (10) = 70. Les suppressions ne sont pas comptées par l'appelant.
        $this->assertSame(70, $tokens->estimateWriteCost([Client::class, Client::class, 'App\\Entity\\Tache']));
        $this->assertSame(0, $tokens->estimateWriteCost([]));
    }
}
