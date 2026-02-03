<?php

namespace App\Serializer;

use Doctrine\ORM\EntityNotFoundException;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\PropertyAccess\PropertyPathInterface;

/**
 * Decorates the default property accessor to gracefully handle Doctrine's EntityNotFoundException.
 * When accessing a property that is a broken proxy (related entity deleted),
 * this accessor catches the exception and returns null, preventing a crash.
 */
class SafePropertyAccessor implements PropertyAccessorInterface
{
    public function __construct(private PropertyAccessorInterface $innerPropertyAccessor)
    {
    }

    public function setValue(object|array &$objectOrArray, string|PropertyPathInterface $propertyPath, mixed $value): void
    {
        $this->innerPropertyAccessor->setValue($objectOrArray, $propertyPath, $value);
    }

    public function getValue(object|array $objectOrArray, string|PropertyPathInterface $propertyPath): mixed
    {
        try {
            return $this->innerPropertyAccessor->getValue($objectOrArray, $propertyPath);
        } catch (EntityNotFoundException) {
            // Return null if the related entity is not found, instead of crashing.
            return null;
        }
    }

    public function isReadable(object|array $objectOrArray, string|PropertyPathInterface $propertyPath): bool
    {
        return $this->innerPropertyAccessor->isReadable($objectOrArray, $propertyPath);
    }

    public function isWritable(object|array $objectOrArray, string|PropertyPathInterface $propertyPath): bool
    {
        return $this->innerPropertyAccessor->isWritable($objectOrArray, $propertyPath);
    }
}