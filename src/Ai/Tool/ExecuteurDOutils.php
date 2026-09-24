<?php

namespace App\Ai\Tool;

use App\Ai\Reglage\ReglagesDeKet;
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
        /** Facultatif pour les tests unitaires — cf. TrousseCatalogue, même raison. */
        private readonly ?ReglagesDeKet $reglages = null,
    ) {
        $this->outils = $outils;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function executer(string $nom, array $args, AiScope $scope): AiToolResult
    {
        // COUPÉ EN CONSOLE = INTROUVABLE, et c'est la bonne réponse.
        //
        // TrousseCatalogue ne le déclare déjà plus : le modèle ne devrait donc pas
        // pouvoir le demander. « Ne devrait pas » n'est pas « ne peut pas » — une
        // page restée ouverte pendant qu'un agent coupait l'outil, un plan préparé
        // au tour d'avant, un fil rejoué. Dans ces cas-là, répondre « introuvable »
        // comme pour un nom inventé est exactement ce qu'il faut : le modèle sait
        // déjà quoi en faire, et l'utilisateur n'apprend rien de la configuration de
        // la plateforme — ce qu'il n'a pas à connaître.
        if ($this->reglages !== null && !$this->reglages->outilActif($nom)) {
            return AiToolResult::introuvable($nom);
        }

        foreach ($this->outils as $outil) {
            if ($outil->name() === $nom) {
                return $outil->execute($args, $scope);
            }
        }

        return AiToolResult::introuvable($nom);
    }
}
