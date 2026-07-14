<?php

namespace App\Ai;

use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Sérialise la fiche d'un enregistrement pour l'assistant IA : attributs
 * stockés au contrat `list:read` (le même que les listes du workspace),
 * élagués des valeurs vides — chaque champ non rempli coûterait des tokens
 * pour rien. Partagé entre LireFicheTool et les objets attachés au contexte
 * d'une conversation (AiContextBuilder).
 */
final class FicheNormaliseur
{
    public function __construct(
        private readonly NormalizerInterface $normalizer,
    ) {
    }

    public function fiche(object $entity): array
    {
        $data = (array) $this->normalizer->normalize($entity, null, ['groups' => ['list:read']]);

        return array_filter(
            $data,
            static fn ($v) => $v !== null && $v !== '' && $v !== [],
        );
    }
}
