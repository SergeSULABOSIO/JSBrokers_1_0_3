<?php

namespace App\Services\Canvas;

use App\Services\ServiceMonnaies;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

class ListCanvasProvider
{
    /**
     * @var ListCanvasProviderInterface[]
     */
    private iterable $providers;

    public function __construct(
        #[TaggedIterator('app.list_canvas_provider')] iterable $providers,
        private ServiceMonnaies $serviceMonnaies
    ) {
        $this->providers = $providers;
    }

    public function getCanvas(string $entityClassName): array
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($entityClassName)) {
                $canvas = $provider->getCanvas();

                // S'assurer que 'colonnes_numeriques' existe toujours, même vide, avant d'ajouter les colonnes partagées.
                $canvas['colonnes_numeriques'] = $canvas['colonnes_numeriques'] ?? [];

                // Ajouter les colonnes numériques partagées si elles existent.
                $canvas['colonnes_numeriques'] = array_merge($canvas['colonnes_numeriques'], $this->getSharedNumericColumnsForEntity($entityClassName));

                return $canvas;
            }
        }

        return [];
    }

    private function getSharedNumericColumnsForEntity(string $entityClassName): array
    {
        return [];
    }
}
