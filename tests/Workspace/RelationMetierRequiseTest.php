<?php

namespace App\Tests\Workspace;

use App\Entity\Cotation;
use App\Entity\RevenuPourCourtier;
use App\Service\Workspace\ChampsObligatoiresInspector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Durcissement : certaines relations sont exigées à la CRÉATION même quand leur
 * colonne est nullable en base, parce qu'une fiche sans elles est incohérente
 * (un RevenuPourCourtier sans typeRevenu casse le calcul de commission). La règle
 * vit dans ChampsObligatoiresInspector (source unique HTTP + assistant Ket).
 */
class RelationMetierRequiseTest extends KernelTestCase
{
    private ChampsObligatoiresInspector $inspector;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->inspector = static::getContainer()->get(ChampsObligatoiresInspector::class);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testTypeRevenuEstRequisPourUnRevenu(): void
    {
        $meta = $this->em->getClassMetadata(RevenuPourCourtier::class);
        $mapping = $meta->getAssociationMapping('typeRevenu');

        $this->assertTrue(
            $this->inspector->relationRequise('typeRevenu', $mapping),
            'typeRevenu doit être exigé à la création d’un revenu (relation métier requise).',
        );
    }

    public function testUneRelationNullableOrdinaireResteFacultative(): void
    {
        // Contre-exemple : l'assureur d'une cotation est nullable et NON requis métier.
        $meta = $this->em->getClassMetadata(Cotation::class);
        $mapping = $meta->getAssociationMapping('assureur');

        $this->assertFalse(
            $this->inspector->relationRequise('assureur', $mapping),
            'Une relation nullable ordinaire ne doit pas devenir obligatoire.',
        );
    }
}
