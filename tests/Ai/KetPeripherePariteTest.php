<?php

namespace App\Tests\Ai;

use App\Ai\Mutation\MutationAllowlist;
use App\Service\Workspace\WorkspaceAccessResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Invariant de POLITIQUE : parité lecture/écriture de l'assistant (Ket). Toute
 * entité interrogeable (entrée de WorkspaceAccessResolver::MAP dotée d'une classe
 * Doctrine) doit être mutable, et réciproquement. Verrou anti-dérive : ajouter une
 * rubrique à la carte d'accès SANS l'ouvrir à l'écriture (ou l'inverse) casse ce
 * test — rappel d'appliquer le garde-fou d'extension de MutationAllowlist.
 */
class KetPeripherePariteTest extends KernelTestCase
{
    public function testEcritureAParfaitePariteAvecLaLecture(): void
    {
        self::bootKernel();
        $resolver = static::getContainer()->get(WorkspaceAccessResolver::class);

        // Ensemble LISIBLE = entrées de la carte d'accès ayant une classe Doctrine
        // (les pseudo-entités DocumentComptable/AssistantIa en sont exclues de fait).
        $lisibles = array_values(array_filter(
            array_keys($resolver->libellesEntites()),
            static fn (string $shortName) => class_exists('App\\Entity\\' . $shortName),
        ));

        $mutables = MutationAllowlist::MEMBRES;
        sort($lisibles);
        sort($mutables);

        $this->assertSame(
            $lisibles,
            $mutables,
            'Parité lecture/écriture rompue : synchroniser MutationAllowlist::MEMBRES avec la carte d\'accès '
            . '(après vérification du garde-fou cascade + présence du FormType).',
        );
    }
}
