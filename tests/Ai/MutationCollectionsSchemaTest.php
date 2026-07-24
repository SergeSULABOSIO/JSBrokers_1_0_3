<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\MutationOperation;
use App\Ai\Mutation\PlanEnAttente;
use App\Ai\Tool\PreparerOperationsTool;
use App\Service\Workspace\WorkspaceMutationService;
use App\Token\TokenAccountService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Le champ « collections » exposé au LLM doit être exprimable SANS
 * additionalProperties (que certains dialectes de schéma — Gemini — élaguent),
 * sinon le modèle ne voit plus la structure et remet à tort les composantes de
 * prime dans « champs » (silencieusement ignorées → prime à 0). On vérifie donc
 * la forme ARRAY du schéma ET son décodage.
 */
class MutationCollectionsSchemaTest extends TestCase
{
    public function testSchemaCollectionsEstUnArraySansAdditionalProperties(): void
    {
        $tool = new PreparerOperationsTool(
            $this->createMock(WorkspaceMutationService::class),
            $this->createMock(TokenAccountService::class),
            new PlanEnAttente($this->createMock(EntityManagerInterface::class)),
        );
        $col = $tool->schema()['properties']['operations']['items']['properties']['collections'];

        $this->assertSame('array', $col['type'], 'collections doit être un array (nom de collection = valeur, pas clé dynamique).');
        $this->assertArrayNotHasKey('additionalProperties', $col, 'collections ne doit pas dépendre d\'additionalProperties (élagué par Gemini).');
        $item = $col['items']['properties'];
        $this->assertArrayHasKey('collection', $item);
        $this->assertArrayHasKey('elements', $item);
        // La structure survit à un élagage type-Gemini d'additionalProperties.
        $sanitized = $this->elaguerAdditionalProperties($col);
        $this->assertArrayHasKey('collection', $sanitized['items']['properties']);
        $this->assertArrayHasKey('elements', $sanitized['items']['properties']);
    }

    public function testDecodeLaFormeArrayDialecteModele(): void
    {
        $op = MutationOperation::fromArray([
            'op' => 'edit', 'entite' => 'Cotation', 'id' => 110,
            'collections' => [[
                'collection' => 'chargements',
                'elements' => [
                    ['op' => 'create', 'champs' => ['nom' => 'Prime nette', 'montantFlatExceptionel' => 9000, 'type' => 5]],
                    ['op' => 'create', 'champs' => ['nom' => 'TVA', 'montantFlatExceptionel' => 1600, 'type' => 7]],
                ],
            ]],
        ]);

        $this->assertArrayHasKey('chargements', $op->collections);
        $this->assertCount(2, $op->collections['chargements']);
        $this->assertSame('create', $op->collections['chargements'][0]->op);
        $this->assertSame(9000, $op->collections['chargements'][0]->fields['montantFlatExceptionel']);
    }

    public function testFormeMapRoundTrip(): void
    {
        $op = new MutationOperation('edit', 'Cotation', 110, [], [
            'chargements' => [new MutationOperation('create', 'ChargementPourPrime', null, ['nom' => 'X', 'type' => 5])],
        ]);

        $reparse = MutationOperation::fromArray($op->toArray());
        $this->assertArrayHasKey('chargements', $reparse->collections);
        $this->assertCount(1, $reparse->collections['chargements']);
        $this->assertSame('X', $reparse->collections['chargements'][0]->fields['nom']);
    }

    /** Réplique l'élagage d'additionalProperties fait par le dialecte Gemini. */
    private function elaguerAdditionalProperties(array $schema): array
    {
        unset($schema['additionalProperties']);
        foreach ($schema as $k => $v) {
            if (is_array($v)) {
                $schema[$k] = $this->elaguerAdditionalProperties($v);
            }
        }

        return $schema;
    }
}
