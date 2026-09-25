<?php

namespace App\Ai\Tool;

use App\Ai\Reglage\ReglagesDeKet;
use App\Ai\Scope\AiScope;
use App\Ai\Telemetrie\JournalTokens;
use App\Ai\Trousse\Trousse;
use App\Ai\Trousse\TrousseCatalogue;
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
        /**
         * Pour le RATTRAPAGE D'UN NOM ÉCORCHÉ : lui seul sait quels outils sont
         * réellement déclarés au tour en cours, et c'est cette liste — pas un
         * jugement porté ici — qui tient la frontière lecture/écriture.
         */
        private readonly ?TrousseCatalogue $catalogue = null,
        /**
         * TOUT CE QUI N'A RIEN EXÉCUTÉ LAISSE UNE TRACE, et sur le canal qu'on relit.
         *
         * C'était un LoggerInterface autowiré, donc le canal « app » : noyé dans un
         * dev.log de plusieurs gigaoctets en développement, et JAMAIS ÉCRIT en
         * production, où le handler est en fingers_crossed sur « error ». La seule
         * mesure du rattrapage manquait précisément là où le rattrapage sert.
         *
         * Facultatif comme les deux précédents, et pour la même raison : les tests
         * unitaires montent cet exécuteur à la main.
         */
        private readonly ?JournalTokens $journal = null,
    ) {
        $this->outils = $outils;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function executer(string $nom, array $args, AiScope $scope, ?Trousse $trousse = null): AiToolResult
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
            // Journalisé à part des noms inconnus : ce n'est pas une faute du modèle,
            // c'est une décision de la plateforme. Les mélanger ferait croire à un
            // problème de nommage là où il n'y en a pas.
            $this->journal?->introuvable($scope, $nom, JournalTokens::MOTIF_COUPE, $trousse?->libelle());

            return AiToolResult::introuvable($nom);
        }

        foreach ($this->outils as $outil) {
            if ($outil->name() === $nom) {
                return $outil->execute($args, $scope);
            }
        }

        // UN NOM ÉCORCHÉ COÛTAIT UN TOUR ENTIER. Mesuré au 2026-09-25 : 10 appels sur
        // 188 (5,3 %) visaient un nom inexistant, tous quasi-homonymes du vrai —
        // `analyser_portefeuille` pour `analyse_portefeuille`, `rechercher_entite` au
        // singulier, `consultar_guide` en espagnol. Chacun rendait « introuvable », et
        // le modèle devait repartir pour un tour de quarante mille jetons d'entrée.
        //
        // La correction ne devine rien : elle ne cherche que parmi les outils DÉCLARÉS
        // à ce tour-là, et seulement si le plus proche est seul et à deux caractères
        // près (cf. RattrapageDeNom). Sans trousse — appelant qui ne la connaît pas —,
        // aucun rattrapage : on ne sait pas ce qui était déclaré, donc on ne touche à rien.
        $vise = $trousse !== null && $this->catalogue !== null
            ? RattrapageDeNom::leProche($nom, $this->catalogue->nomsDe($trousse, $scope))
            : null;

        if ($vise !== null) {
            foreach ($this->outils as $outil) {
                if ($outil->name() !== $vise) {
                    continue;
                }
                // JOURNALISÉ SANS EXCEPTION : un rattrapage est une décision prise à la
                // place du modèle, et rien ne doit permettre d'en douter après coup.
                $this->journal?->rattrapage($scope, $nom, $vise, $trousse->libelle());

                return $outil->execute($args, $scope);
            }
        }

        // RIEN N'A RÉPONDU, et c'est le chiffre du chantier. Un nom que ni le
        // catalogue ni le rattrapage ne reconnaissent coûte un tour d'entrée complet
        // — de l'ordre de quarante mille jetons — pour un résultat vide. Sans cette
        // ligne, il était indistinguable d'un succès : l'orchestrateur pousse le nom
        // DEMANDÉ dans `tour.outils` quoi qu'il arrive.
        $this->journal?->introuvable($scope, $nom, JournalTokens::MOTIF_INCONNU, $trousse?->libelle());

        return AiToolResult::introuvable($nom);
    }
}
