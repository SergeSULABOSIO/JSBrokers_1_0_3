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
