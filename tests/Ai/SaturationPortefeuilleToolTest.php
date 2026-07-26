<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\EntiteLibelle;
use App\Ai\Tool\SaturationPortefeuilleTool;
use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Service\Saturation\SaturationService;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;

/**
 * Outil « saturation_portefeuille » : fail-closed (Client + Risque + Piste), niveau
 * portefeuille par défaut, niveau client sur nom, gestion de l'ambiguïté et de
 * l'absence. Tests purs (collaborateurs mockés).
 */
class SaturationPortefeuilleToolTest extends TestCase
{
    /**
     * @param array<string,bool> $droits canRead par entité (défaut : tout autorisé)
     */
    private function makeTool(
        SaturationService $saturation,
        ?JSBDynamicSearchService $search = null,
        array $droits = ['Client' => true, 'Risque' => true, 'Piste' => true],
    ): SaturationPortefeuilleTool {
        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('canRead')->willReturnCallback(
            static fn (Invite $invite, string $shortName): bool => $droits[$shortName] ?? false,
        );

        // EntiteLibelle est final : on l'instancie pour de vrai sur un EM muet dont les
        // métadonnées déclarent le champ « nom » (displayField => 'nom', libelle lu via PropertyAccess).
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('hasField')->willReturnCallback(static fn (string $field): bool => $field === 'nom');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($metadata);
        $libelleur = new EntiteLibelle($em);

        return new SaturationPortefeuilleTool(
            $resolver,
            $saturation,
            $search ?? $this->createMock(JSBDynamicSearchService::class),
            $libelleur,
        );
    }

    private function makeScope(): AiScope
    {
        return new AiScope(new Entreprise(), new Invite());
    }

    public function testFailClosedSansLecturePiste(): void
    {
        $saturation = $this->createMock(SaturationService::class);
        $saturation->expects($this->never())->method('couverturePortefeuille');

        $tool = $this->makeTool($saturation, null, ['Client' => true, 'Risque' => true, 'Piste' => false]);
        $result = $tool->execute([], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_HORS_PERIMETRE, $result->status);
    }

    public function testNiveauPortefeuilleParDefaut(): void
    {
        $saturation = $this->createMock(SaturationService::class);
        $saturation->expects($this->once())
            ->method('couverturePortefeuille')
            ->with($this->anything(), $this->anything(), false)
            ->willReturn([
                'perimetre'            => 'Portefeuille Nord',
                'nbClients'            => 10,
                'nbCatalogue'          => 5,
                'tauxMoyen'            => 62.0,
                'nbClientsSatures'     => 3,
                'nbClientsSousSatures' => 7,
                'topOpportunites'      => [['risque' => 'Santé', 'nbClients' => 6]],
            ]);

        $result = $this->makeTool($saturation)->execute([], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame('portefeuille', $result->data['niveau']);
        $this->assertSame('Portefeuille Nord', $result->data['perimetre']);
        $this->assertSame(7, $result->data['nbClientsSousSatures']);
        $this->assertSame('Santé', $result->data['topOpportunites'][0]['risque']);
    }

    public function testNiveauClientNomme(): void
    {
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturn([
            'status' => ['code' => 200],
            'data'   => [(new Client())->setNom('Client Alpha')],
            'totalItems' => 1,
        ]);

        $saturation = $this->createMock(SaturationService::class);
        $saturation->expects($this->once())
            ->method('couvertureClient')
            ->willReturn([
                'nbCatalogue'    => 5,
                'nbCouverts'     => 2,
                'tauxCouverture' => 40.0,
                'nouveaux'       => [],
                'aRelancer'      => [],
            ]);

        $result = $this->makeTool($saturation, $search)->execute(['client' => 'Alpha'], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame('client', $result->data['niveau']);
        $this->assertSame('Client Alpha', $result->data['client']);
        $this->assertSame(40.0, $result->data['tauxCouverture']);
    }

    public function testClientAmbigu(): void
    {
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturn([
            'status' => ['code' => 200],
            'data'   => [(new Client())->setNom('Alpha 1'), (new Client())->setNom('Alpha 2')],
            'totalItems' => 2,
        ]);

        $saturation = $this->createMock(SaturationService::class);
        $saturation->expects($this->never())->method('couvertureClient');

        $result = $this->makeTool($saturation, $search)->execute(['client' => 'Al'], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertTrue($result->data['ambigu']);
        $this->assertCount(2, $result->data['candidats']);
    }

    public function testClientIntrouvable(): void
    {
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturn([
            'status' => ['code' => 200],
            'data'   => [],
            'totalItems' => 0,
        ]);

        $result = $this->makeTool($this->createMock(SaturationService::class), $search)
            ->execute(['client' => 'Inconnu'], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_INTROUVABLE, $result->status);
    }

    public function testMatchDeclencheursEtNonCollisions(): void
    {
        $tool  = $this->makeTool($this->createMock(SaturationService::class));
        $scope = $this->makeScope();

        $this->assertIsArray($tool->match('Où en est la saturation de mon portefeuille ?', $scope));
        $this->assertIsArray($tool->match('Quels risques manquants pour le client Alpha ?', $scope));
        $this->assertIsArray($tool->match('Fais-moi le point cross-selling', $scope));

        $this->assertNull($tool->match('Combien de clients avons-nous ?', $scope));
        $this->assertNull($tool->match('Montre-moi les impayés', $scope));
    }
}
