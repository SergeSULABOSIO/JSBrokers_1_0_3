<?php

namespace App\Services\Canvas\Provider\Form;

interface FormCanvasProviderInterface
{
    /**
     * Checks if this provider supports the given entity class.
     *
     * @param string $entityClassName The FQCN of the entity.
     * @return boolean
     */
    public function supports(string $entityClassName): bool;

    /**
     * Returns the form canvas for the given object.
     *
     * @param object $object The entity instance.
     * @param integer|null $idEntreprise The ID of the company.
     * @return array
     */
    public function getCanvas(object $object, ?int $idEntreprise): array;
}