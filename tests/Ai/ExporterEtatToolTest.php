<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\EntiteLibelle;
use App\Ai\Tool\ExporterEtatTool;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Note;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Export d'états : URL générée serveur sur liste fermée de routes (jamais une
 * URL du modèle), gating fail-closed (DocumentComptable / Note), scoping
 * strict de la note dans l'entreprise, paramètre CATCH_ALL des routes PDF.
 */
class ExporterEtatToolTest extends TestCase
{
    private function makeTool(
        array $canRead,
        ?JSBDynamicSearchService $search = null,
        ?UrlGeneratorInterface $urlGenerator = null,
    ): ExporterEtatTool {
        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('libellesEntites')->willReturn(['Note' => 'Notes', 'DocumentComptable' => 'Documents comptables']);
        $resolver->method('canRead')->willReturnCallback(
            static fn (Invite $invite, string $shortName) => $canRead[$shortName] ?? false,
        );

        if ($urlGenerator === null) {
            $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
            $urlGenerator->method('generate')->willReturn('/admin/export-genere');
        }

        return new ExporterEtatTool(
            $resolver,
            $search ?? $this->createMock(JSBDynamicSearchService::class),
            $this->makeLibelleur(),
            $urlGenerator,
        );
    }

    private function makeLibelleur(): EntiteLibelle
    {
        $metadata = new ClassMetadata(Note::class);
        $metadata->fieldMappings['nom'] = ['fieldName' => 'nom'];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($metadata);

        return new EntiteLibelle($em);
    }

    private function makeScope(): AiScope
    {
        $entreprise = new Entreprise();
        $ref = new \ReflectionProperty(Entreprise::class, 'id');
        $ref->setValue($entreprise, 42);

        return new AiScope($entreprise, new Invite());
    }

    public function testExportComptableEmetLUrlDeLaRouteExistante(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with('admin.documentcomptable.export', ['idEntreprise' => 42, 'doc' => 'all', 'exercice' => 2026])
            ->willReturn('/admin/document-comptable/export/42?doc=all&exercice=2026');

        $tool = $this->makeTool(['DocumentComptable' => true], urlGenerator: $urlGenerator);
        $result = $tool->execute(
            ['etat' => 'document_comptable', 'doc' => 'all', 'exercice' => 2026],
            $this->makeScope(),
        );

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame('open-url', $result->uiAction['type']);
        $this->assertSame('/admin/document-comptable/export/42?doc=all&exercice=2026', $result->uiAction['url']);
    }

    public function testExportComptableHorsPerimetre(): void
    {
        $tool = $this->makeTool(['DocumentComptable' => false]);
        $result = $tool->execute(['etat' => 'document_comptable'], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_HORS_PERIMETRE, $result->status);
    }

    public function testDocInvalideIntrouvable(): void
    {
        $tool = $this->makeTool(['DocumentComptable' => true]);
        $result = $tool->execute(['etat' => 'document_comptable', 'doc' => 'grand-nimporte'], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_INTROUVABLE, $result->status);
    }

    public function testNotePdfScopeeDansLEntreprise(): void
    {
        $note = new Note();
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->expects($this->once())
            ->method('search')
            ->with(Note::class, ['id' => 7], $this->anything(), null, 1, 1)
            ->willReturn(['status' => ['code' => 200], 'data' => [$note]]);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with('admin.etats.imprimer_note', ['idEntreprise' => 42, 'idNote' => 7, 'currentURL' => 'admin'])
            ->willReturn('/admin/etats/imprimerNote/42/7/admin');

        $tool = $this->makeTool(['Note' => true], $search, $urlGenerator);
        $result = $tool->execute(['etat' => 'note_pdf', 'idNote' => 7], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame('/admin/etats/imprimerNote/42/7/admin', $result->uiAction['url']);
    }

    public function testBordereauPdfUtiliseSaRoute(): void
    {
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturn(['status' => ['code' => 200], 'data' => [new Note()]]);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with('admin.etats.imprimer_bordereau_note', $this->anything())
            ->willReturn('/admin/etats/imprimerBordereauNote/42/7/admin');

        $tool = $this->makeTool(['Note' => true], $search, $urlGenerator);
        $result = $tool->execute(['etat' => 'bordereau_pdf', 'idNote' => 7], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
    }

    public function testNoteHorsEntrepriseIntrouvable(): void
    {
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturn(['status' => ['code' => 200], 'data' => []]);

        $tool = $this->makeTool(['Note' => true], $search);
        $result = $tool->execute(['etat' => 'note_pdf', 'idNote' => 999], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_INTROUVABLE, $result->status);
        $this->assertNull($result->uiAction);
    }

    public function testNotePdfSansIdIntrouvable(): void
    {
        $tool = $this->makeTool(['Note' => true]);
        $result = $tool->execute(['etat' => 'note_pdf'], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_INTROUVABLE, $result->status);
    }

    public function testNotePdfHorsPerimetre(): void
    {
        $tool = $this->makeTool(['Note' => false]);
        $result = $tool->execute(['etat' => 'note_pdf', 'idNote' => 7], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_HORS_PERIMETRE, $result->status);
    }

    public function testMatchExportExcelSeulement(): void
    {
        $tool = $this->makeTool([]);
        $scope = $this->makeScope();

        $this->assertSame(
            ['etat' => 'document_comptable', 'doc' => 'all'],
            $tool->match('Exporte le classeur comptable en Excel', $scope),
        );
        // Les PDF exigent un id de note : réservés au LLM réel.
        $this->assertNull($tool->match('Imprime la note 7 en PDF', $scope));
        $this->assertNull($tool->match('Exporte la liste des clients', $scope));
    }
}
