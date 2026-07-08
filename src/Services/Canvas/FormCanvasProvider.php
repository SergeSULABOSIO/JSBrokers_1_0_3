<?php

namespace App\Services\Canvas;
use App\Services\Canvas\Provider\Form\FormCanvasProviderInterface;
use Doctrine\Persistence\Proxy;
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
        // Proxy-safe : une association lazy (ex. Avenant::pisteDeRenouvellement) est un
        // proxy Doctrine dont get_class() renvoie « Proxies\__CG__\… », qui ne matche
        // aucun supports(). On remonte à la classe réelle (le proxy étend l'entité).
        $entityClassName = $object instanceof Proxy ? get_parent_class($object) : get_class($object);

        foreach ($this->providers as $provider) {
            if ($provider->supports($entityClassName)) {
                return $provider->getCanvas($object, $idEntreprise);
            }
        }

        // If no specific provider is found, return an empty array.
        return [];
    }

}