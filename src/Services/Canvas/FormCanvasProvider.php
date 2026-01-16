<?php

namespace App\Services\Canvas;
use App\Services\Canvas\Provider\Form\FormCanvasProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

class FormCanvasProvider
{
    /**
     * @var FormCanvasProviderInterface[]
     */
    private iterable $providers;

    public function __construct(
        #[TaggedIterator('app.form_canvas_provider')] iterable $providers
    ) {
        $this->providers = $providers;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        $entityClassName = get_class($object);

        foreach ($this->providers as $provider) {
            if ($provider->supports($entityClassName)) {
                return $provider->getCanvas($object, $idEntreprise);
            }
        }

        // If no specific provider is found, return an empty array.
        return [];
    }

}