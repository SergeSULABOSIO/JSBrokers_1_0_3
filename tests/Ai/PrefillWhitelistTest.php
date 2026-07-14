<?php

namespace App\Tests\Ai;

use App\Ai\Tool\PrefillWhitelist;
use App\Entity\Client;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use PHPUnit\Framework\TestCase;

/**
 * Whitelist de pré-remplissage : seuls les champs SCALAIRES mappés Doctrine
 * passent — relations, id/audit, champs inconnus, types non scalaires et
 * valeurs hors plafonds sont écartés silencieusement (fail-closed).
 */
class PrefillWhitelistTest extends TestCase
{
    private function makeWhitelist(): PrefillWhitelist
    {
        $metadata = new ClassMetadata(Client::class);
        foreach (['nom' => 'string', 'telephone' => 'string', 'exonere' => 'boolean',
                  'effectif' => 'integer', 'id' => 'integer', 'createdAt' => 'datetime_immutable',
                  'photo' => 'blob'] as $champ => $type) {
            $metadata->fieldMappings[$champ] = FieldMapping::fromMappingArray(
                ['fieldName' => $champ, 'type' => $type, 'columnName' => $champ],
            );
        }

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturnCallback(static function (string $fqcn) use ($metadata) {
            if ($fqcn !== Client::class) {
                throw new \InvalidArgumentException('Entité inconnue.');
            }

            return $metadata;
        });

        return new PrefillWhitelist($em);
    }

    public function testChampsScalairesRetenus(): void
    {
        $retenus = $this->makeWhitelist()->filtrer(Client::class, [
            'nom'       => '  Kabila Corp ',
            'telephone' => '+243812345678',
            'exonere'   => true,
            'effectif'  => 12,
        ]);

        $this->assertSame(
            ['nom' => 'Kabila Corp', 'telephone' => '+243812345678', 'exonere' => true, 'effectif' => 12],
            $retenus,
        );
    }

    public function testChampsInterditsEtInconnusEcartes(): void
    {
        $retenus = $this->makeWhitelist()->filtrer(Client::class, [
            'id'         => 999,               // interdit
            'createdAt'  => '2026-01-01',      // interdit (audit)
            'entreprise' => 5,                 // relation : pas un field
            'inexistant' => 'x',               // inconnu
            'photo'      => 'AAAA',            // type non autorisé (blob)
            'nom'        => 'Valide',
        ]);

        $this->assertSame(['nom' => 'Valide'], $retenus);
    }

    public function testValeursNonScalairesOuHorsPlafondEcartees(): void
    {
        $retenus = $this->makeWhitelist()->filtrer(Client::class, [
            'nom'       => ['tableau'],                   // non scalaire
            'telephone' => str_repeat('9', 300),          // > 255
            'exonere'   => '',                            // chaîne vide
        ]);

        $this->assertSame([], $retenus);
    }

    public function testPlafondDouzeChamps(): void
    {
        $metadata = new ClassMetadata(Client::class);
        $valeurs = [];
        for ($i = 1; $i <= 15; ++$i) {
            $metadata->fieldMappings['champ' . $i] = FieldMapping::fromMappingArray(
                ['fieldName' => 'champ' . $i, 'type' => 'string', 'columnName' => 'champ' . $i],
            );
            $valeurs['champ' . $i] = 'v' . $i;
        }
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($metadata);

        $retenus = (new PrefillWhitelist($em))->filtrer(Client::class, $valeurs);

        $this->assertCount(12, $retenus);
    }

    public function testClasseInconnueRendVide(): void
    {
        $this->assertSame([], $this->makeWhitelist()->filtrer('App\\Entity\\NExistePas', ['nom' => 'x']));
    }

    public function testMetadonneesIntrouvablesRendVide(): void
    {
        // EM qui refuse (entité non gérée) : la whitelist ne laisse rien passer.
        $this->assertSame([], $this->makeWhitelist()->filtrer(\stdClass::class, ['nom' => 'x']));
    }
}
