<?php

namespace App\Ai\Action;

use Psr\Log\LoggerInterface;

/**
 * Écarte, avant l'envoi au navigateur, toute action qu'il ne saurait pas exécuter.
 *
 * POURQUOI CE FILTRE. Le navigateur ignore silencieusement ce qu'il ne reconnaît pas.
 * Une action d'un type inconnu, ou privée d'un champ dont son handler a besoin, part
 * donc pour ne rien produire : l'utilisateur attend un bouton qui n'arrivera jamais, et
 * aucune trace n'en subsiste. C'est exactement la pathologie du plan fantôme, déplacée
 * d'un cran.
 *
 * FAIL-SAFE ET VISIBLE, dans cet ordre. On écarte l'action — la laisser passer ne
 * servirait personne — mais on la JOURNALISE en avertissement, avec son type et le
 * champ manquant. Un défaut silencieux devient un défaut qu'on peut corriger.
 *
 * Ce filtre ne juge PAS le contenu métier d'un plan : cela reste le travail de
 * {@see \App\Ai\Mutation\PlanEnAttente::planStockable()}, seul à connaître la forme
 * d'un plan. Ici on ne vérifie que ce que le navigateur exige pour agir.
 */
final class ValidateurDActions
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $actions
     *
     * @return array<int, array<string, mixed>> les seules actions réellement exécutables
     */
    public function filtrer(array $actions, ?int $conversation = null): array
    {
        $retenues = [];
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $type = TypeAction::depuis($action['type'] ?? null);
            if ($type === null) {
                $this->logger->warning('Assistant IA : action d’un type inconnu, écartée avant envoi.', [
                    'type'         => $action['type'] ?? null,
                    'conversation' => $conversation,
                ]);
                continue;
            }

            $manquants = [];
            foreach ($type->champsRequis() as $champ) {
                $valeur = $action[$champ] ?? null;
                if ($valeur === null || $valeur === '' || $valeur === []) {
                    $manquants[] = $champ;
                }
            }
            if ($manquants !== []) {
                $this->logger->warning('Assistant IA : action incomplète, écartée avant envoi.', [
                    'type'         => $type->value,
                    'manquants'    => $manquants,
                    'conversation' => $conversation,
                ]);
                continue;
            }

            $retenues[] = $action;
        }

        return array_values($retenues);
    }
}
