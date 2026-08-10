<?php

namespace App\Tests\Ai;

use App\Ai\Resolution\ResolveurDeReferences;
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
 *
 * ouvrir_dialogue reste disponible pour TOUTES les entités (y compris Client) :
 * c'est la procédure « l'utilisateur remplit et enregistre lui-même », au choix
 * face à preparer_operations (« Ket enregistre elle-même »).
 */
class OuvrirDialogueToolPrefillTest extends TestCase
{
    /**
     * @param array<int, Client> $trouves enregistrements que la recherche ramènera
     */
    private function makeTool(array $trouves = []): OuvrirDialogueTool
    {
        $resolver = $this->createMock(WorkspaceAccessResolver::class);
        $resolver->method('libellesEntites')->willReturn(['Client' => 'Clients']);
        $resolver->method('can')->willReturn(true);
        $resolver->method('canRead')->willReturn(true);

        $metadata = new ClassMetadata(Client::class);
        foreach (['nom' => 'string', 'telephone' => 'string'] as $champ => $type) {
            $metadata->fieldMappings[$champ] = FieldMapping::fromMappingArray(
                ['fieldName' => $champ, 'type' => $type, 'columnName' => $champ],
            );
        }
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($metadata);

        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturn([
            'status' => ['code' => 200], 'data' => $trouves, 'totalItems' => count($trouves),
        ]);

        $libelleur = new EntiteLibelle($em);

        return new OuvrirDialogueTool(
            $resolver,
            $search,
            new EntiteLexique($resolver),
            $libelleur,
            new PrefillWhitelist($em),
            new ResolveurDeReferences($search, $resolver, $libelleur),
        );
    }

    /** Un client identifié, tel que la recherche le rendrait. */
    private function client(int $id, string $nom): Client
    {
        $client = new Client();
        $client->setNom($nom);
        $reflexion = new \ReflectionProperty(Client::class, 'id');
        $reflexion->setAccessible(true);
        $reflexion->setValue($client, $id);

        return $client;
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

    /**
     * L'INCIDENT DU 2026-08-10. « Ouvre-moi le formulaire d'édition pour Olea » exigeait
     * un identifiant que le modèle n'a pas. Il dépensait donc son UNIQUE tour d'outils à
     * chercher Olea ; au message suivant, faute de pouvoir enchaîner, il ouvrait la
     * RUBRIQUE des partenaires. L'utilisateur demandait un formulaire et recevait une
     * liste. Le nom suffit désormais : le serveur le résout lui-même.
     */
    public function testLeNomSuffitPourOuvrirLeFormulaireDEdition(): void
    {
        $result = $this->makeTool([$this->client(1, 'OLEA')])->execute([
            'entite' => 'Client',
            'mode'   => 'edition',
            'nom'    => 'Olea',
        ], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame('open-dialog', $result->uiAction['type']);
        $this->assertSame('edition', $result->uiAction['mode']);
        $this->assertSame(1, $result->uiAction['id'], 'Le nom dicté est devenu l’identifiant.');
        $this->assertSame('OLEA', $result->data['cible']);
    }

    /**
     * ON NE DEVINE JAMAIS. Plusieurs correspondances = une QUESTION, et surtout AUCUN
     * formulaire ouvert au hasard : ouvrir la fiche du mauvais partenaire serait pire
     * que ne rien ouvrir.
     */
    public function testUnNomAmbiguPoseUneQuestionSansRienOuvrir(): void
    {
        $result = $this->makeTool([
            $this->client(1, 'OLEA RDC'),
            $this->client(2, 'OLEA Congo'),
        ])->execute([
            'entite' => 'Client',
            'mode'   => 'edition',
            'nom'    => 'Olea',
        ], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertNull($result->uiAction, 'Aucun formulaire ne s’ouvre sur une ambiguïté.');
        $this->assertFalse($result->data['pret']);
        $this->assertSame('ambigu', $result->data['aDemander'][0]['probleme']);
        $this->assertSame([1 => 'OLEA RDC', 2 => 'OLEA Congo'], $result->data['aDemander'][0]['valeurs']);
        $this->assertStringContainsString('n’ouvre aucune rubrique en remplacement', $result->data['note']);
    }

    /** Un nom qui ne correspond à rien : une question, jamais un identifiant inventé. */
    public function testUnNomIntrouvablePoseUneQuestion(): void
    {
        $result = $this->makeTool()->execute([
            'entite' => 'Client',
            'mode'   => 'edition',
            'nom'    => 'Zzzz',
        ], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertNull($result->uiAction);
        $this->assertSame('introuvable', $result->data['aDemander'][0]['probleme']);
        $this->assertSame('Zzzz', $result->data['aDemander'][0]['terme']);
    }

    /** L'identifiant explicite reste prioritaire : le nom n'est qu'un chemin de plus. */
    public function testLIdentifiantExpliciteResteHonore(): void
    {
        $result = $this->makeTool([$this->client(7, 'OLEA')])->execute([
            'entite' => 'Client',
            'mode'   => 'edition',
            'id'     => 7,
            'nom'    => 'Peu importe',
        ], $this->makeScope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame(7, $result->uiAction['id']);
    }
}
