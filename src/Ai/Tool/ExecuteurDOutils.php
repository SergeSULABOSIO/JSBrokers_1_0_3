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

        // UN ANCIEN NOM, ACCEPTÉ TEL QUEL — et AVANT le rattrapage, jamais après.
        //
        // L'ordre n'est pas indifférent. Un renommage est une correspondance DÉCLARÉE :
        // elle doit s'appliquer telle quelle, sans dépendre de la ressemblance
        // orthographique entre l'ancien nom et le nouveau. « compter_entites » est à
        // distance 8 de « rechercher_entites » — le filet ne l'aurait jamais rattrapé,
        // et faire reposer une transition sur le hasard des lettres n'aurait pas tenu.
        //
        // La frontière reste tenue par la suite : l'outil visé est cherché parmi ceux
        // qu'on a, et un outil coupé en console a déjà rendu « introuvable » plus haut.
        $alias = AliasDOutils::resoudre($nom, $this->nomsDesOutils());
        if ($alias !== null && $this->estAtteignable($alias, $scope, $trousse)) {
            foreach ($this->outils as $outil) {
                if ($outil->name() !== $alias) {
                    continue;
                }
                $this->journal?->rattrapage(
                    $scope,
                    $nom,
                    $alias,
                    $trousse?->libelle() ?? '',
                    JournalTokens::ORIGINE_ALIAS,
                );

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

    /**
     * ⚠ UN ALIAS NE DOIT ATTEINDRE QUE CE QUE SON NOM ACTUEL ATTEINDRAIT.
     *
     * Écrit après qu'un test l'eut mis en défaut : la première version cherchait la cible
     * de l'alias directement parmi les outils du processus, court-circuitant les deux
     * filtres qui comptent. Un outil COUPÉ EN CONSOLE redevenait donc joignable par son
     * ancien nom — la coupure ne porte que sur le nom demandé —, et un outil d'écriture
     * aurait pu l'être depuis un tour de lecture.
     *
     * La règle est désormais celle du rattrapage, mot pour mot : la cible doit figurer
     * parmi les outils DÉCLARÉS à ce tour-ci. Ce n'est pas une garde de sécurité — elle
     * reste dans execute(), en fail-closed — mais c'est la propriété qui empêche une
     * commodité de transition d'ouvrir ce que la console a fermé.
     *
     * Sans trousse ni catalogue, l'appelant ne sait pas ce qui était déclaré : on se
     * rabat sur le seul filtre dont on dispose, l'état de l'outil en console.
     */
    private function estAtteignable(string $cible, AiScope $scope, ?Trousse $trousse): bool
    {
        if ($trousse !== null && $this->catalogue !== null) {
            return \in_array($cible, $this->catalogue->nomsDe($trousse, $scope), true);
        }

        return $this->reglages === null || $this->reglages->outilActif($cible);
    }

    /**
     * Les noms d'outils réellement présents dans ce processus.
     *
     * Sert UNIQUEMENT à la garde « un nom qui existe n'est jamais un alias » : ce n'est
     * pas la liste des outils déclarés au tour, qui dépend de la trousse et du périmètre,
     * et que seul TrousseCatalogue sait établir.
     *
     * @return list<string>
     */
    private function nomsDesOutils(): array
    {
        $noms = [];
        foreach ($this->outils as $outil) {
            $noms[] = $outil->name();
        }

        return $noms;
    }
}
