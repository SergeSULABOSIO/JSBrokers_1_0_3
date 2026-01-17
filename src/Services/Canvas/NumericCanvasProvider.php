<?php

namespace App\Services\Canvas;

use App\Services\Canvas\Provider\Numeric\NumericCanvasProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

class NumericCanvasProvider
{
    /**
     * @var NumericCanvasProviderInterface[]
     */
    private iterable $providers;

    public function __construct(
        #[TaggedIterator('app.numeric_canvas_provider')] iterable $providers
    ) {
        $this->providers = $providers;
    }

    public function getAttributesAndValues($object): array
    {
        $entityClassName = get_class($object);

        foreach ($this->providers as $provider) {
            if ($provider->supports($entityClassName)) {
                return $provider->getCanvas($object);
            }
        }

        // If no specific provider is found, return an empty array.
        return [];
    }

    public function getAttributesAndValuesForCollection($data): array
    {
        $numericValues = [];
        if (empty($data)) {
            return $numericValues;
        }

        foreach ($data as $entity) {
            // Here we call the main method which will resolve the provider
            $attributes = $this->getAttributesAndValues($entity);
            if (!empty($attributes)) {
                $numericValues[$entity->getId()] = $attributes;
            }
        }
        return $numericValues;
    }
}
