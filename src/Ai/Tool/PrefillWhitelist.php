<?php

namespace App\Ai\Tool;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Whitelist de PRÉ-REMPLISSAGE des formulaires ouverts par l'assistant IA :
 * ne laisse passer que les champs SCALAIRES mappés Doctrine de l'entité —
 * jamais une relation, jamais l'id ni les champs d'audit — avec des valeurs
 * scalaires plafonnées. FAIL-CLOSED : tout ce qui n'est pas explicitement
 * autorisé est silencieusement écarté.
 *
 * Défense en profondeur : appliquée dans OuvrirDialogueTool (avant d'émettre
 * la directive) ET re-appliquée dans AssistantIaController::dialogContext()
 * (seule la réponse de cet endpoint touche le DOM) — on ne fait confiance ni
 * au modèle ni au front. L'utilisateur relit et enregistre lui-même : la
 * validation serveur du formulaire reste le juge final.
 */
final class PrefillWhitelist
{
    private const MAX_CHAMPS = 12;
    private const MAX_LONGUEUR = 255;
    private const CHAMPS_INTERDITS = ['id', 'createdAt', 'updatedAt'];
    private const TYPES_AUTORISES = [
        'string', 'text', 'integer', 'smallint', 'bigint', 'float', 'decimal',
        'boolean', 'date', 'date_immutable', 'datetime', 'datetime_immutable',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<mixed> $valeurs Proposition champ => valeur (non fiable)
     *
     * @return array<string, bool|float|int|string> Champs retenus (vide si rien de valide)
     */
    public function filtrer(string $fqcn, array $valeurs): array
    {
        if (!class_exists($fqcn)) {
            return [];
        }

        try {
            $metadata = $this->em->getClassMetadata($fqcn);
        } catch (\Throwable) {
            return [];
        }

        $retenus = [];
        foreach ($valeurs as $champ => $valeur) {
            if (count($retenus) >= self::MAX_CHAMPS) {
                break;
            }
            if (!is_string($champ) || in_array($champ, self::CHAMPS_INTERDITS, true)) {
                continue;
            }
            // Champs scalaires mappés uniquement : une relation n'est pas un field.
            if (!$metadata->hasField($champ)
                || !in_array((string) $metadata->getTypeOfField($champ), self::TYPES_AUTORISES, true)) {
                continue;
            }
            if (!is_scalar($valeur)) {
                continue;
            }
            if (is_string($valeur)) {
                $valeur = trim($valeur);
                if ($valeur === '' || mb_strlen($valeur) > self::MAX_LONGUEUR) {
                    continue;
                }
            }

            $retenus[$champ] = $valeur;
        }

        return $retenus;
    }
}
