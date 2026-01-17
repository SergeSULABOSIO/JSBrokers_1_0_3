<?php

namespace App\Services\Canvas\Provider\Numeric;

interface NumericCanvasProviderInterface
{
    /**
     * Checks if this provider supports the given entity class.
     *
     * @param string $entityClassName The FQCN of the entity.
     * @return boolean
     */
    public function supports(string $entityClassName): bool;

    /**
     * Returns the numeric attributes canvas for the given object.
     *
     * @param object $object The entity instance.
     * @return array
     */
    public function getCanvas(object $object): array;
}