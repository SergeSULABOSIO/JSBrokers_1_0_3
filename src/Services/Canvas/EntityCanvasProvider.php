<?php

namespace App\Services\Canvas;
use App\Services\Canvas\Provider\Entity\EntityCanvasProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;


class EntityCanvasProvider
{
    /**
     * @var EntityCanvasProviderInterface[]
     */
    private iterable $providers;

    /**
     * @param iterable<EntityCanvasProviderInterface> $providers
     */
    public function __construct(
        #[TaggedIterator('app.entity_canvas_provider')] iterable $providers
    ) {
        $this->providers = $providers;
    }

    public function getCanvas(string $entityClassName): array
    {
        $canvas = $this->findRawCanvas($entityClassName);
        if ($canvas === []) {
            return [];
        }

        // Enrichissement : chaque attribut « Collection » hérite de l'icône (alias
        // IconCanvasProvider) de son entité CIBLE — ex. la collection `contacts` d'un
        // Client reçoit l'icône du canvas de Contact. Utilisé notamment par les onglets
        // de collection du workspace (Signifiance : icône + libellé sur chaque onglet).
        // On lit le canvas cible via findRawCanvas (sans enrichissement) pour éviter
        // toute récursion (Client → Piste → Client…).
        if (isset($canvas['liste']) && is_array($canvas['liste'])) {
            foreach ($canvas['liste'] as &$attr) {
                if (($attr['type'] ?? null) === 'Collection' && empty($attr['icone']) && !empty($attr['targetEntity'])) {
                    $targetCanvas = $this->findRawCanvas($attr['targetEntity']);
                    if (!empty($targetCanvas['parametres']['icone'])) {
                        $attr['icone'] = $targetCanvas['parametres']['icone'];
                    }
                }
            }
            unset($attr);
        }

        return $canvas;
    }

    /**
     * Canvas brut du provider correspondant, sans enrichissement.
     */
    private function findRawCanvas(string $entityClassName): array
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($entityClassName)) {
                return $provider->getCanvas();
            }
        }
        return [];
    }
}
