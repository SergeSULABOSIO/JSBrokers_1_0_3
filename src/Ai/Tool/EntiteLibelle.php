<?php

namespace App\Ai\Tool;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Libellé lisible d'un enregistrement pour l'assistant IA : détecte le champ
 * de libellé persisté d'une entité (métadonnées Doctrine) et le lit via
 * PropertyAccess, avec repli __toString puis #id — même heuristique que
 * l'autocomplétion de la recherche avancée (DRY entre outils).
 */
final class EntiteLibelle
{
    /**
     * Champs candidats au libellé/filtre texte, par ordre de préférence. Le
     * premier présent dans les métadonnées Doctrine de l'entité est retenu.
     */
    private const DISPLAY_FIELD_CANDIDATES = ['nom', 'titre', 'libelle', 'intitule', 'reference', 'numero', 'description'];

    private readonly PropertyAccessorInterface $accessor;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        $this->accessor = PropertyAccess::createPropertyAccessor();
    }

    /** Premier champ persisté de l'entité utilisable comme libellé, ou null. */
    public function displayField(string $fqcn): ?string
    {
        $metadata = $this->em->getClassMetadata($fqcn);
        foreach (self::DISPLAY_FIELD_CANDIDATES as $candidate) {
            if ($metadata->hasField($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Libellé lisible d'une instance : champ détecté, sinon __toString, sinon #id. */
    public function libelle(object $entity, ?string $displayField): string
    {
        $libelle = null;
        if ($displayField !== null) {
            try {
                $libelle = $this->accessor->getValue($entity, $displayField);
            } catch (\Throwable) {
                // Champ illisible sur cette instance : repli __toString / id.
            }
        }
        if ($libelle === null || $libelle === '') {
            $libelle = method_exists($entity, '__toString') ? (string) $entity : ('#' . $entity->getId());
        }

        return (string) $libelle;
    }
}
