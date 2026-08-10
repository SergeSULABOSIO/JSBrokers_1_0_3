<?php

namespace App\Tests\Ai;

use App\Ai\Resolution\ResolveurDeReferences;
use App\Ai\Tool\EntiteLibelle;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Fabrique de ResolveurDeReferences pour les tests unitaires.
 *
 * Le résolveur est `final` (comme EntiteLibelle) : on ne le double pas, on le
 * CONSTRUIT avec des dépendances simulées. C'est d'ailleurs préférable — les tests
 * exercent ainsi la vraie logique de résolution, celle qui décide entre « résolu »,
 * « introuvable » et « ambigu ».
 */
trait ResolveurDeTest
{
    /**
     * @param array<string, array<int, string>> $parEntite nom court => (id => libellé)
     *                                                     ce que la recherche doit trouver
     */
    private function resolveurAvec(array $parEntite = [], ?WorkspaceAccessResolver $accessResolver = null): ResolveurDeReferences
    {
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturnCallback(
            function (string $fqcn) use ($parEntite) {
                $court = substr($fqcn, strrpos($fqcn, '\\') + 1);
                $entites = [];
                foreach ($parEntite[$court] ?? [] as $id => $libelle) {
                    $entites[] = new class((int) $id, (string) $libelle) {
                        public function __construct(private int $id, public string $nom)
                        {
                        }

                        public function getId(): int
                        {
                            return $this->id;
                        }

                        public function getNom(): string
                        {
                            return $this->nom;
                        }
                    };
                }

                return ['status' => ['code' => 200], 'data' => $entites];
            }
        );

        if ($accessResolver === null) {
            $accessResolver = $this->createMock(WorkspaceAccessResolver::class);
            $accessResolver->method('canRead')->willReturn(true);
            $accessResolver->method('libellesEntites')->willReturn([]);
        }

        return new ResolveurDeReferences($search, $accessResolver, new EntiteLibelle($this->emAvecChampNom()));
    }

    /** EntityManager minimal : toute entité possède un champ « nom » (champ d'affichage). */
    private function emAvecChampNom(): EntityManagerInterface&MockObject
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('hasField')->willReturnCallback(static fn (string $champ) => $champ === 'nom');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($metadata);

        return $em;
    }
}
