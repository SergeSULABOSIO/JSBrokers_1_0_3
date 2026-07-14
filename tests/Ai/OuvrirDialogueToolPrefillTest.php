<?php

namespace App\Tests\Ai;

use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\EntiteLexique;
use App\Ai\Tool\EntiteLibelle;
use App\Ai\Tool\OuvrirDialogueTool;
use App\Ai\Tool\PrefillWhitelist;
use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use PHPUnit\Framework\TestCase;

/**
 * Pré-remplissage d'ouvrir_dialogue : les valeurs dictées passent la whitelist
 * (défense en profondeur — dialogContext re-filtrera) et voyagent dans la
 * uiAction open-dialog ; rien ne passe en mode édition ; l'outil n'écrit rien.
 */
class OuvrirDialogueToolPrefillTest extends TestCase
{
    private function makeTool(): OuvrirDialogueTool
    {
        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('libellesEntites')->willReturn(['Client' => 'Clients']);
        $resolver->method('can')->willReturn(true);

        $metadata = new ClassMetadata(Client::class);
        foreach (['nom' => 'string', 'telephone' => 'string'] as $champ => $type) {
            $metadata->fieldMappings[$champ] = FieldMapping::fromMappingArray(
                ['fieldName' => $champ, 'type' => $type, 'columnName' => $champ],
            );
        }
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($metadata);

        return new OuvrirDialogueTool(
            $resolver,
            $this->createMock(JSBDynamicSearchService::class),
            new EntiteLexique($resolver),
            new EntiteLibelle($em),
            new PrefillWhitelist($em),
        );
    }

    private function makeScope(): AiScope
    {
        return new AiScope(new Entreprise(), new Invite());
    }

    public function testValeursWhitelisteesVoyagentDansLaUiAction(): void
    {
        $result = $this->makeTool()->execute([
            'entite'  => 'Client',
            'mode'    => 'creation',
            'valeurs' => ['nom' => 'Kabila Corp', 'id' => 999, 'inconnu' => 'x'],
        ], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame(['nom' => 'Kabila Corp'], $result->uiAction['valeurs']);
        $this->assertSame(['nom'], $result->data['precharge']);
        $this->assertStringContainsString('pré-rempli', $result->data['note']);
    }

    public function testSansValeursLaUiActionResteInchangee(): void
    {
        $result = $this->makeTool()->execute(
            ['entite' => 'Client', 'mode' => 'creation'],
            $this->makeScope(),
        );

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertArrayNotHasKey('valeurs', $result->uiAction);
        $this->assertArrayNotHasKey('precharge', $result->data);
    }
}
