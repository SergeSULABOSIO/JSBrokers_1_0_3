<?php

namespace App\Serializer;

use Doctrine\ORM\EntityNotFoundException;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;

/**
 * This custom normalizer extends the default ObjectNormalizer to gracefully handle
 * Doctrine's EntityNotFoundException during the serialization process.
 */
class SafeObjectNormalizer extends ObjectNormalizer
{
    /**
     * Overrides the parent method to safely access attribute values.
     *
     * When the serializer tries to access a property that is a broken proxy
     * (i.e., the related entity has been deleted from the database), Doctrine throws
     * an EntityNotFoundException. This method catches that specific exception
     * and returns null for that attribute, allowing the serialization of the
     * rest of the object to proceed without crashing the application.
     */
    protected function getAttributeValue(object $object, string $attribute, ?string $format = null, array $context = []): mixed
    {
        try {
            return parent::getAttributeValue($object, $attribute, $format, $context);
        } catch (EntityNotFoundException) {
            return null;
        }
    }
}