<?php

namespace App\Tests\Workspace;

use PHPUnit\Framework\TestCase;

/**
 * UNE RUBRIQUE N'EXISTE QU'UNE FOIS DANS LA BARRE D'ONGLETS.
 *
 * Chaque clic de rubrique, chaque tuile du tableau de bord, chaque bouton « Voir la
 * production » et chaque `ouvrir_rubrique` de Ket créaient un onglet NEUF sans jamais
 * regarder si cette rubrique était déjà ouverte. La barre finissait encombrée d'instances
 * mortes de la même rubrique, et chaque ouverture repayait le chargement complet du
 * composant — alors que le panneau demandé était déjà là, juste à côté.
 *
 * ET « RÉUTILISER » NE VEUT PAS DIRE « SE CONTENTER D'ACTIVER ».
 *
 * Une rubrique s'ouvre souvent FILTRÉE : « ouvre la production de M. Modogo », « voir les
 * versements de cet agent ». Le filtre voyage à part de l'ouverture — il est mis en attente
 * par `_armerCriteresDeRubrique`, puis posé au signal `app:tab.state-ready` que le Cerveau
 * émet quand l'état de l'onglet est enregistré (cf. FiltreApresEtatOngletTest).
 *
 * Or un onglet RÉUTILISÉ et déjà chargé ne redemande aucun composant : aucun
 * `ui:tab.initialized` ne suit, donc ce signal ne vient JAMAIS. Sans la pose immédiate
 * vérifiée ici, la rubrique se réactiverait sans se filtrer — et Ket annoncerait « voici la
 * production de M. Modogo » au-dessus de la production de tout le monde. C'est exactement
 * l'incident du 2026-08-10 que le mécanisme des critères en attente existe pour empêcher,
 * réintroduit par la porte d'à côté.
 *
 * POURQUOI UNE VÉRIFICATION STATIQUE. La décision d'identité, elle, est éprouvée sans DOM
 * (tests/js/onglets-uniques.test.mjs). Ce qui ne se rejoue pas sans navigateur, c'est son
 * BRANCHEMENT : que le dédoublonnage soit bien posé sur le seul chemin par lequel tous les
 * gestes d'ouverture passent, et que le filtre y survive. Débrancher l'un ou l'autre ne
 * ferait broncher aucune assertion de la suite.
 */
class OngletUniqueParRubriqueTest extends TestCase
{
    private const WORKSPACE = __DIR__ . '/../../assets/controllers/workspace-manager_controller.js';
    private const MODULE = __DIR__ . '/../../assets/controllers/onglets-uniques.js';
    private const MENU = __DIR__ . '/../../templates/components/_interactive_menu.html.twig';

    private function source(string $chemin): string
    {
        $contenu = file_get_contents($chemin);
        self::assertIsString($contenu, sprintf('Fichier introuvable : %s', $chemin));

        return $contenu;
    }

    /** Le code seul : un commentaire qui cite une fonction ne l'appelle pas. */
    private function codeNu(string $source): string
    {
        return preg_replace('!/\*.*?\*/|//[^\n]*!s', '', $source) ?? $source;
    }

    /** La portion d'une méthode, de sa signature à l'accolade fermante de même indentation. */
    private function corpsDeMethode(string $code, string $signature): string
    {
        $debut = strpos($code, $signature);
        self::assertNotFalse($debut, sprintf('Méthode introuvable : %s', $signature));

        $fin = strpos($code, "\n    }", $debut);
        self::assertNotFalse($fin, sprintf('Fin de méthode introuvable : %s', $signature));

        return substr($code, $debut, $fin - $debut);
    }

    /**
     * LA DÉCISION D'IDENTITÉ VIT HORS DU CONTRÔLEUR.
     *
     * Recopier la comparaison dans le contrôleur la rendrait inéprouvable sans DOM, et
     * surtout : elle existerait alors en deux endroits, qui divergeraient.
     */
    public function testLeModuleDeDecisionExisteEtEstImporte(): void
    {
        $module = $this->source(self::MODULE);

        foreach (['cleDeRubrique', 'ongletExistantPourRubrique', 'dedoublonnerOnglets'] as $fonction) {
            self::assertStringContainsString(
                sprintf('export function %s', $fonction),
                $module,
                sprintf('Le module de décision doit exporter %s().', $fonction)
            );
        }

        self::assertMatchesRegularExpression(
            "!import\s*\{[^}]*ongletExistantPourRubrique[^}]*\}\s*from\s*'\./onglets-uniques\.js'!",
            $this->source(self::WORKSPACE),
            'Le workspace-manager doit importer la décision, pas la refaire.'
        );
    }

    /**
     * LE DÉDOUBLONNAGE EST POSÉ SUR LE PASSAGE OBLIGÉ.
     *
     * `createWorkspaceTab` est le seul créateur d'onglet de rubrique : clic de menu et
     * tuile du tableau de bord y arrivent par `loadComponent` et `handleNavigateTo`, Ket et
     * les boutons de rapport par `openRubriqueByEntity`. Le poser ailleurs laisserait
     * forcément une porte ouverte.
     */
    public function testLaCreationReutiliseLOngletDeLaMemeRubrique(): void
    {
        $corps = $this->corpsDeMethode(
            $this->codeNu($this->source(self::WORKSPACE)),
            'createWorkspaceTab({ componentName, entityName, groupName, title, iconAlias }) {'
        );

        self::assertStringContainsString(
            'ongletExistantPourRubrique(this.workspaceTabs',
            $corps,
            'createWorkspaceTab doit chercher l’onglet déjà ouvert avant d’en créer un.'
        );

        // La recherche doit précéder la fabrication de l'identifiant : trouvée après, elle
        // laisserait l'onglet en double se créer quand même.
        $positionRecherche = strpos($corps, 'ongletExistantPourRubrique');
        $positionCreation = strpos($corps, 'const tabId =');
        self::assertNotFalse($positionCreation, 'La fabrication de l’identifiant a disparu.');
        self::assertLessThan(
            $positionCreation,
            $positionRecherche,
            'Le dédoublonnage doit précéder la création, sinon il ne dédoublonne rien.'
        );

        self::assertMatchesRegularExpression(
            '!if\s*\(existant\)\s*\{.*?return;.*?\}!s',
            $corps,
            'Un onglet retrouvé doit interrompre la création (return), pas seulement s’activer.'
        );
    }

    /**
     * L'ONGLET RÉUTILISÉ RESTE LA CIBLE DES CRITÈRES.
     *
     * `pendingWorkspaceTabId` est la clé sur laquelle `_armerCriteresDeRubrique` range les
     * critères, et tous les appelants les arment APRÈS l'ouverture. Ne pas la poser sur
     * l'onglet réutilisé enverrait le filtre vers l'onglet précédemment ouvert — ou nulle
     * part.
     */
    public function testLOngletReutiliseDevientLaCibleDesCriteres(): void
    {
        $corps = $this->corpsDeMethode(
            $this->codeNu($this->source(self::WORKSPACE)),
            'createWorkspaceTab({ componentName, entityName, groupName, title, iconAlias }) {'
        );

        $reutilisation = substr($corps, (int) strpos($corps, 'if (existant)'));
        self::assertStringContainsString(
            'this.pendingWorkspaceTabId = existant.id;',
            $reutilisation,
            'Le filtre à venir doit viser l’onglet réutilisé.'
        );
        self::assertStringContainsString(
            '_activateWorkspaceTabById(existant.id)',
            $reutilisation,
            'L’onglet retrouvé doit être ramené au premier plan.'
        );
    }

    /**
     * LE FILTRE EST POSÉ TOUT DE SUITE SUR UN ONGLET DÉJÀ CHARGÉ.
     *
     * C'est le cœur du correctif : aucun `app:tab.state-ready` ne viendra plus pour cet
     * onglet-là. Et ce doit être le MÊME `_appliquerCriteresEnAttente` — un second chemin de
     * pose serait un second endroit où la garde « est-ce bien l'onglet actif ? » pourrait
     * manquer.
     */
    public function testLeFiltreEstPoseImmediatementSurUnOngletDejaCharge(): void
    {
        $code = $this->codeNu($this->source(self::WORKSPACE));
        $corps = $this->corpsDeMethode($code, '_armerCriteresDeRubrique(criteres) {');

        self::assertStringContainsString(
            '_ongletDejaCharge(this.pendingWorkspaceTabId)',
            $corps,
            'Un onglet réutilisé et déjà chargé n’attend aucun signal : il faut le reconnaître.'
        );
        self::assertStringContainsString(
            '_appliquerCriteresEnAttente(this.pendingWorkspaceTabId)',
            $corps,
            'La pose doit emprunter le chemin existant, pas en ouvrir un second.'
        );

        // Reconnaître un onglet chargé, c'est lire l'état du panneau — le même drapeau que
        // celui sur lequel `_activateWorkspaceTabById` décide de ne rien recharger.
        self::assertStringContainsString(
            "dataset.loaded === 'true'",
            $this->corpsDeMethode($code, '_ongletDejaCharge(tabId) {'),
            '_ongletDejaCharge doit lire le drapeau de chargement du panneau.'
        );
    }

    /**
     * LE STOCKAGE HÉRITÉ EST NETTOYÉ À LA RESTAURATION.
     *
     * Chaque utilisateur a déjà des doublons en localStorage, accumulés avant la règle. Sans
     * ce passage, la barre nettoyée à l'usage se resalirait au premier F5 — et la règle
     * paraîtrait n'avoir jamais été posée.
     */
    public function testLaRestaurationDedoublonneLeStockageHerite(): void
    {
        $corps = $this->corpsDeMethode(
            $this->codeNu($this->source(self::WORKSPACE)),
            '_restoreWorkspaceTabsFromStorage() {'
        );

        self::assertStringContainsString(
            'dedoublonnerOnglets(',
            $corps,
            'La restauration doit écarter les doublons hérités du stockage.'
        );

        // Le nettoyage doit précéder la reconstruction du DOM : après, les onglets en double
        // seraient déjà dans la barre.
        self::assertLessThan(
            (int) strpos($corps, '_createTabStructure'),
            (int) strpos($corps, 'dedoublonnerOnglets('),
            'Il faut dédoublonner AVANT de reconstruire la barre.'
        );
    }

    /**
     * LE GESTE RÉUTILISÉ SE SIGNALE, Y COMPRIS SANS ÉCRAN.
     *
     * Rouvrir la rubrique de l'onglet DÉJÀ actif ne déplace plus rien : sans retour
     * perceptible, le geste passe pour un bug et l'utilisateur re-clique. Le halo le dit à
     * l'œil ; la zone `aria-live` le dit aux lecteurs d'écran, qui ne voient aucun halo.
     */
    public function testLaReutilisationEstAnnoncee(): void
    {
        $corps = $this->corpsDeMethode(
            $this->codeNu($this->source(self::WORKSPACE)),
            '_signalerOngletReutilise(tabId) {'
        );

        self::assertStringContainsString('is-onglet-reutilise', $corps, 'Le halo visuel a disparu.');
        self::assertStringContainsString(
            'annonceOngletsTarget.textContent',
            $corps,
            'L’annonce aux lecteurs d’écran a disparu.'
        );

        $menu = $this->source(self::MENU);
        self::assertMatchesRegularExpression(
            '!aria-live="polite"[^>]*data-workspace-manager-target="annonceOnglets"!s',
            $menu,
            'La zone d’annonce doit exister dans la barre d’onglets, et être une zone aria-live.'
        );
    }
}
