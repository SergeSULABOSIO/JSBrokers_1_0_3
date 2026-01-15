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
        foreach ($this->providers as $provider) {
            if ($provider->supports($entityClassName)) {
                return $provider->getCanvas();
            }
        }
        return [];
    }
}
