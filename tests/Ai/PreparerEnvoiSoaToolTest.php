<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\EntiteLibelle;
use App\Ai\Tool\PreparerEnvoiSoaTool;
use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;

/**
 * Préparation d'envoi du SOA : gating fail-closed (lecture Clients — même
 * garde que le picker), résolution scopée entreprise (id prioritaire, sinon
 * nom), candidats sur ambiguïté, directive open-soa-envoi (jamais d'envoi).
 */
class PreparerEnvoiSoaToolTest extends TestCase
{
    private function makeTool(bool $canReadClient, ?JSBDynamicSearchService $search = null): PreparerEnvoiSoaTool
    {
        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('libellesEntites')->willReturn(['Client' => 'Clients']);
        $resolver->method('canRead')->willReturn($canReadClient);

        $metadata = new ClassMetadata(Client::class);
        $metadata->fieldMappings['nom'] = ['fieldName' => 'nom'];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($metadata);

        return new PreparerEnvoiSoaTool(
            $resolver,
            $search ?? $this->createMock(JSBDynamicSearchService::class),
            new EntiteLibelle($em),
        );
    }

    private function makeClient(int $id, string $nom): Client
    {
        $client = (new Client())->setNom($nom);
        $ref = new \ReflectionProperty(Client::class, 'id');
        $ref->setValue($client, $id);

        return $client;
    }

    private function makeScope(): AiScope
    {
        return new AiScope(new Entreprise(), new Invite());
    }

    public function testClientUniqueOuvreLaBoiteDEnvoi(): void
    {
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturn([
            'status' => ['code' => 200],
            'data'   => [$this->makeClient(11, 'Kabila Corp')],
        ]);

        $tool = $this->makeTool(true, $search);
        $result = $tool->execute(['nom' => 'Kabila'], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame(['type' => 'open-soa-envoi', 'clientId' => 11], $result->uiAction);
        $this->assertSame('Kabila Corp', $result->data['client']);
    }

    public function testIdPrioritaireSurNom(): void
    {
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->expects($this->once())
            ->method('search')
            ->with(Client::class, ['id' => 11], $this->anything(), null, 1, $this->anything())
            ->willReturn(['status' => ['code' => 200], 'data' => [$this->makeClient(11, 'Kabila Corp')]]);

        $tool = $this->makeTool(true, $search);
        $result = $tool->execute(['id' => 11, 'nom' => 'autre chose'], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
    }

    public function testAmbiguiteRestitueLesCandidats(): void
    {
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturn([
            'status' => ['code' => 200],
            'data'   => [$this->makeClient(1, 'Alpha SA'), $this->makeClient(2, 'Alpha Sarl')],
        ]);

        $tool = $this->makeTool(true, $search);
        $result = $tool->execute(['nom' => 'Alpha'], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertTrue($result->data['ambigu']);
        $this->assertCount(2, $result->data['candidats']);
        $this->assertNull($result->uiAction);
    }

    public function testHorsPerimetreClients(): void
    {
        $tool = $this->makeTool(false);
        $result = $tool->execute(['nom' => 'Kabila'], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_HORS_PERIMETRE, $result->status);
    }

    public function testClientHorsEntrepriseIntrouvable(): void
    {
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturn(['status' => ['code' => 200], 'data' => []]);

        $tool = $this->makeTool(true, $search);
        $result = $tool->execute(['id' => 999], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_INTROUVABLE, $result->status);
    }

    public function testSansCibleIntrouvable(): void
    {
        $tool = $this->makeTool(true);
        $result = $tool->execute([], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_INTROUVABLE, $result->status);
    }

    public function testMatchEnvoiSoa(): void
    {
        $tool = $this->makeTool(true);
        $scope = $this->makeScope();

        $this->assertSame(['nom' => 'kabila'], $tool->match('Envoie le SOA du client Kabila', $scope));
        $this->assertSame(['nom' => 'kabila'], $tool->match('Envoyer le relevé de compte de Kabila ?', $scope));
        // Sans nom résoluble ni verbe d'envoi : réservé au LLM réel / autres outils.
        $this->assertNull($tool->match('Envoie le SOA', $scope));
        $this->assertNull($tool->match('Montre-moi le SOA de Kabila', $scope));
    }
}
