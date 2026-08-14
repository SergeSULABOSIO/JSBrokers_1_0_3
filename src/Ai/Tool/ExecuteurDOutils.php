<?php

namespace App\Ai\Tool;

use App\Ai\Scope\AiScope;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Exécute un outil désigné par son nom technique — le seul chemin par lequel un
 * appel du modèle atteint le code métier.
 *
 * Existait en TROIS exemplaires rigoureusement identiques (les deux moteurs réels,
 * puis la phase de compréhension). Une quatrième copie serait arrivée avec le
 * prochain appelant, et c'est le genre de recopie qui finit par diverger sur le
 * point qui compte : ici, le fait qu'un nom inconnu renvoie « introuvable » plutôt
 * que de lever une exception.
 *
 * SÉCURITÉ : ce n'est PAS une garde. Chaque outil re-vérifie les droits dans son
 * propre execute() (fail-closed), et c'est là que la vérification doit rester —
 * le périmètre ne dépend jamais de qui appelle, ni de ce que le modèle a demandé.
 */
final class ExecuteurDOutils
{
    /** @var iterable<AiToolInterface> */
    private iterable $outils;

    public function __construct(
        #[AutowireIterator('app.ai_tool')] iterable $outils,
    ) {
        $this->outils = $outils;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function executer(string $nom, array $args, AiScope $scope): AiToolResult
    {
        foreach ($this->outils as $outil) {
            if ($outil->name() === $nom) {
                return $outil->execute($args, $scope);
            }
        }

        return AiToolResult::introuvable($nom);
    }
}
