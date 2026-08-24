<?php

namespace App\Tests\Workspace;

use PHPUnit\Framework\TestCase;

/**
 * ON NE FILTRE PAS UN ONGLET QUI N'A PAS ENCORE D'ÉTAT (incident du 2026-08-24).
 *
 * Le bouton « Versements enregistrés » d'un rapport — et `ouvrir_rubrique` de l'assistant,
 * qui emprunte le même chemin — ouvrent une rubrique DÉJÀ FILTRÉE. Le filtre était posé dès
 * `handleComponentLoaded`, c'est-à-dire dès que le HTML de la liste était en place. Mais le
 * Cerveau n'enregistre l'état d'un onglet qu'au `ui:tab.initialized` du list-manager,
 * lui-même différé d'une frame.
 *
 * La recherche partait donc vers un onglet SANS état. `_buildDynamicQueryUrl` ne trouvait
 * pas de `serverRootName`, `_requestListRefresh` abandonnait avant le `fetch`, et QUATRE
 * symptômes en découlaient — tous rapportés séparément, tous nés de cette seule ligne :
 *
 *   1. la liste n'était pas filtrée : Bruno restait affiché sous un badge « Alice » ;
 *   2. la barre de progression, allumée avant la recherche, n'était jamais éteinte ;
 *   3. le chip du bénéficiaire ne nommait pas l'agent chargé ;
 *   4. la barre des totaux portait sur un ensemble que le badge démentait.
 *
 * Un écran qui affiche un badge de filtre sans avoir filtré est pire qu'un écran non
 * filtré : il AFFIRME. C'est très exactement la contradiction que ce mécanisme existait
 * pour empêcher.
 *
 * POURQUOI UNE VÉRIFICATION STATIQUE. La correction est un ORDONNANCEMENT entre deux
 * contrôleurs Stimulus, et l'ordre ne se rejoue pas sans DOM ni frames : ce que l'on peut
 * tenir, en revanche, c'est le CONTRAT qui le garantit — le Cerveau annonce que l'état est
 * prêt, et le workspace-manager n'agit qu'à cette annonce. Remettre l'application du filtre
 * dans `handleComponentLoaded` ferait retomber les quatre symptômes d'un coup, sans qu'une
 * seule assertion de la suite ne bronche.
 */
class FiltreApresEtatOngletTest extends TestCase
{
    private const CERVEAU = __DIR__ . '/../../assets/controllers/cerveau_controller.js';
    private const WORKSPACE = __DIR__ . '/../../assets/controllers/workspace-manager_controller.js';

    private const SIGNAL = 'app:tab.state-ready';

    private function source(string $chemin): string
    {
        $contenu = file_get_contents($chemin);
        self::assertIsString($contenu, sprintf('Fichier introuvable : %s', $chemin));

        return $contenu;
    }

    /** Le code seul : un commentaire qui cite le signal ne le branche pas. */
    private function codeNu(string $source): string
    {
        return preg_replace('!/\*.*?\*/|//[^\n]*!s', '', $source) ?? $source;
    }

    /**
     * LE CERVEAU ANNONCE L'ÉTAT PRÊT, ET DEPUIS LE BON ENDROIT.
     *
     * Le signal doit naître dans le traitement de `ui:tab.initialized` : c'est la seule
     * ligne du fichier où l'on sait qu'un `serverRootName` a été enregistré. Émis ailleurs,
     * il promettrait ce qu'il ne peut pas tenir.
     */
    public function testLeCerveauAnnonceLEtatPretApresEnregistrement(): void
    {
        $code = $this->codeNu($this->source(self::CERVEAU));

        self::assertStringContainsString(self::SIGNAL, $code, 'Le signal d’état prêt a disparu.');

        // La portion qui va de `ui:tab.initialized` au `case` suivant.
        $debut = strpos($code, "case 'ui:tab.initialized':");
        self::assertNotFalse($debut, 'Le traitement de ui:tab.initialized est introuvable.');
        $suite = substr($code, $debut);
        $fin = strpos($suite, "case '", 10);
        $bloc = $fin === false ? $suite : substr($suite, 0, $fin);

        self::assertStringContainsString(
            self::SIGNAL,
            $bloc,
            'Le signal doit être émis DANS le traitement de ui:tab.initialized : c’est le seul '
            . 'endroit où l’état de l’onglet vient d’être enregistré.',
        );
    }

    /**
     * LE WORKSPACE-MANAGER N'APPLIQUE LE FILTRE QU'À CE SIGNAL.
     *
     * Deux moitiés indissociables : il écoute le signal, et il ne pose PLUS le filtre au
     * chargement du composant. La seconde est celle qui avait le défaut.
     */
    public function testLeFiltreEstPoseAuSignalEtPasAuChargement(): void
    {
        $code = $this->codeNu($this->source(self::WORKSPACE));

        self::assertStringContainsString(
            self::SIGNAL,
            $code,
            'Le workspace-manager doit écouter l’annonce d’état prêt.',
        );

        $debut = strpos($code, 'handleComponentLoaded(event)');
        self::assertNotFalse($debut, 'handleComponentLoaded est introuvable.');
        $suite = substr($code, $debut);
        // Jusqu'à la fin de la méthode : la première accolade fermante en début de ligne.
        $fin = preg_match('/\n    \}/', $suite, $m, PREG_OFFSET_CAPTURE) === 1 ? $m[0][1] : strlen($suite);
        $corps = substr($suite, 0, $fin);

        self::assertStringNotContainsString(
            '_appliquerCriteresEnAttente',
            $corps,
            'handleComponentLoaded ne doit PAS poser le filtre : à cet instant le Cerveau n’a pas '
            . 'encore enregistré l’état de l’onglet, la recherche part dans le vide, et l’écran '
            . 'affiche un badge de filtre sans avoir filtré.',
        );
    }

    /**
     * UN FILTRE EN ATTENTE N'EST CONSOMMÉ QU'APRÈS AVOIR ÉTÉ POSÉ.
     *
     * Le retrait précédait le contrôle « est-ce bien l'onglet actif ? » : un onglet non
     * actif perdait son filtre sans l'avoir jamais appliqué. Sans conséquence tant qu'un
     * seul onglet était concerné à la fois — mais la RESTAURATION après un F5 en rétablit
     * plusieurs d'un coup, et un seul est actif. Les autres reviendraient donc non
     * filtrés : très exactement le défaut que la persistance vient corriger.
     *
     * On vérifie l'ORDRE dans le corps de la méthode, faute de pouvoir rejouer sans DOM
     * l'enchaînement de plusieurs onglets restaurés.
     */
    public function testLeFiltreEnAttenteNEstConsommeQuApresAvoirEtePose(): void
    {
        $code = $this->codeNu($this->source(self::WORKSPACE));

        $debut = strpos($code, '_appliquerCriteresEnAttente(tabId)');
        self::assertNotFalse($debut, '_appliquerCriteresEnAttente est introuvable.');
        $corps = substr($code, $debut, 700);

        $garde = strpos($corps, 'activeWorkspaceTabId');
        $retrait = strpos($corps, 'delete this._criteresEnAttente');
        self::assertNotFalse($garde, 'Le contrôle de l’onglet actif a disparu.');
        self::assertNotFalse($retrait, 'La consommation du filtre en attente a disparu.');

        self::assertLessThan(
            $retrait,
            $garde,
            'Le filtre doit être retiré APRÈS le contrôle de l’onglet actif : retiré avant, un '
            . 'onglet restauré non actif le perd sans l’avoir jamais appliqué.',
        );
    }

    /**
     * LES FILTRES SONT MÉMORISÉS ET REMIS EN ATTENTE À LA RESTAURATION.
     *
     * Les deux moitiés du mécanisme, chacune inutile sans l'autre : on se greffe sur
     * `app:context.changed` pour retenir, et on amorce les critères en attente lors de la
     * reconstruction des onglets.
     */
    public function testLesFiltresSontMemorisesEtRemisEnAttente(): void
    {
        $code = $this->codeNu($this->source(self::WORKSPACE));

        self::assertStringContainsString(
            'memoriserCriteres',
            $code,
            'Les critères d’un onglet doivent être mémorisés au passage de app:context.changed.',
        );

        // On s'ancre sur la reconstruction de la barre — la ligne qui EST la restauration,
        // et non sur le nom de la méthode, qui apparaît aussi à son appel.
        $debut = strpos($code, 'this.workspaceTabs = tabs;');
        self::assertNotFalse($debut, 'La reconstruction des onglets restaurés est introuvable.');
        self::assertStringContainsString(
            'criteresARestaurer',
            substr($code, $debut, 1200),
            'La restauration doit remettre les filtres en attente, sans quoi les onglets '
            . 'reviennent vidés de leur filtre — le défaut à corriger.',
        );
    }

    /**
     * UN ABANDON AVANT LA REQUÊTE ÉTEINT CE QU'IL A ALLUMÉ.
     *
     * Filet de sécurité, et non le correctif : l'extinction de la barre dépend de la chaîne
     * `app:list.refreshed` → `app:list.rendered` → `app:loading.stop`, dont aucun maillon
     * n'a lieu sans requête. Tout chemin qui renonce au `fetch` doit donc s'éteindre
     * lui-même, sous peine de laisser la barre tourner indéfiniment.
     */
    public function testUnAbandonAvantLaRequeteEteintLaBarre(): void
    {
        $code = $this->codeNu($this->source(self::CERVEAU));

        $debut = strpos($code, 'const url = this._buildDynamicQueryUrl(tabState);');
        self::assertNotFalse($debut, 'Le point d’abandon de _requestListRefresh est introuvable.');
        $garde = substr($code, $debut, 600);

        $fermeture = strpos($garde, 'return;');
        self::assertNotFalse($fermeture, 'La garde `if (!url)` a changé de forme.');

        self::assertStringContainsString(
            "app:loading.stop",
            substr($garde, 0, $fermeture),
            'Renoncer à la requête sans éteindre la barre la laisse tourner pour toujours.',
        );
    }
}
