<?php

namespace App\Tests\Workspace;

use PHPUnit\Framework\TestCase;

/**
 * LES DEUX BARRES D'ONGLETS DU WORKSPACE SE RESSEMBLENT.
 *
 * Il y en a deux : celle des RUBRIQUES ouvertes (colonne 3 — « Clients », « Pistes »…) et
 * celle des onglets d'une rubrique (« Principal », « Contacts », « Sinistres »… pour la
 * ligne sélectionnée). Elles portaient deux grammaires différentes dans le même écran :
 * l'une encadrée et posée sur un bandeau, l'autre soulignée sur fond blanc. Deux façons
 * d'être un onglet obligent à réapprendre ce qu'on sait déjà faire (Bastien & Scapin >
 * Cohérence) — et garantissaient qu'en retouchant l'une, on oublierait l'autre.
 *
 * ── POURQUOI CE TEST LIT DES FICHIERS ───────────────────────────────────────────────
 * Le rendu vit dans le DOM, et la suite JavaScript du projet n'en a pas (aucun jsdom en
 * dépendance, délibérément — cf. OngletReplieFermableTest). On vérifie donc ce qui se
 * vérifie sans navigateur : que le dessin n'est écrit QU'UNE FOIS, que les deux barres
 * le portent, et qu'aucune des deux n'a recommencé à écrire le sien dans son coin.
 */
class OngletsMemeGrammaireTest extends TestCase
{
    private const CSS_PARTAGE = __DIR__ . '/../../assets/styles/app.css';
    private const CSS_WORKSPACE = __DIR__ . '/../../assets/styles/interactive-menu.css';
    private const TWIG_WORKSPACE = __DIR__ . '/../../templates/components/_interactive_menu.html.twig';
    private const TWIG_RUBRIQUE = __DIR__ . '/../../templates/components/_view_manager.html.twig';
    private const JS_RUBRIQUE = __DIR__ . '/../../assets/controllers/view-manager_controller.js';
    private const JS_SOCLE = __DIR__ . '/../../assets/controllers/onglets-debordement.js';

    private function lire(string $chemin): string
    {
        self::assertFileExists($chemin);

        return str_replace("\r\n", "\n", (string) file_get_contents($chemin));
    }

    /**
     * Extrait le corps d'une règle CSS (ce qui est entre ses accolades).
     *
     * Volontairement naïf : les règles visées ne contiennent pas d'accolade imbriquée.
     */
    private function corpsDeRegle(string $css, string $selecteur): string
    {
        $debut = strpos($css, $selecteur . ' {');
        self::assertNotFalse($debut, sprintf('Règle « %s » absente.', $selecteur));
        $ouvre = strpos($css, '{', $debut);
        $ferme = strpos($css, '}', $ouvre);
        self::assertNotFalse($ferme);

        return substr($css, $ouvre + 1, $ferme - $ouvre - 1);
    }

    /**
     * Le dessin d'un onglet est écrit une seule fois, dans la feuille partagée.
     *
     * C'est le cœur de la refonte : si une des deux barres se remet à déclarer son propre
     * fond ou ses propres coins, elles recommenceront à diverger en silence.
     */
    public function testLeDessinDUnOngletNEstEcritQuUneFois(): void
    {
        $partage = $this->lire(self::CSS_PARTAGE);
        $workspace = $this->lire(self::CSS_WORKSPACE);

        $onglet = $this->corpsDeRegle($partage, '.jsb-onglet');
        self::assertStringContainsString('background-color: var(--bg-muted)', $onglet);
        self::assertStringContainsString('border-radius: var(--nav-radius-sm)', $onglet);

        // La feuille du workspace ne redessine plus l'onglet : elle ne garde que ce qui
        // lui est propre (la croix de fermeture, le halo de réutilisation, la rangée vide).
        self::assertStringNotContainsString(
            '.workspace-tab-item {',
            $workspace,
            "La barre du workspace a recommencé à écrire son propre onglet : le composant partagé ne sert plus à rien.",
        );
        self::assertStringNotContainsString('.workspace-tab-bar-wrapper {', $workspace);
        self::assertStringNotContainsString('.workspace-tab-bar {', $workspace);
    }

    /** Les deux barres portent le composant partagé — sinon il ne s'applique à personne. */
    public function testLesDeuxBarresPortentLeComposantPartage(): void
    {
        $workspace = $this->lire(self::TWIG_WORKSPACE);
        $rubrique = $this->lire(self::TWIG_RUBRIQUE);
        $js = $this->lire(self::JS_RUBRIQUE);

        // Les classes d'origine SUBSISTENT : ce sont des points d'accroche JavaScript.
        self::assertStringContainsString('class="workspace-tab-item jsb-onglet"', $workspace);
        self::assertStringContainsString('class="workspace-tab-icon jsb-onglet-icone"', $workspace);
        self::assertStringContainsString('class="workspace-tab-title jsb-onglet-titre"', $workspace);

        self::assertStringContainsString('class="list-tab jsb-onglet active"', $rubrique);
        self::assertStringContainsString('class="list-tab-icon jsb-onglet-icone"', $rubrique);

        // Les onglets de collection sont créés en JavaScript : ils doivent la porter aussi.
        self::assertStringContainsString("tab.className = 'list-tab jsb-onglet'", $js);
        self::assertStringContainsString("iconHolder.className = 'list-tab-icon jsb-onglet-icone'", $js);
        self::assertStringContainsString("titre.className = 'jsb-onglet-titre'", $js);
    }

    /** Le bandeau — fond clair et bordure basse — est le même des deux côtés. */
    public function testLesDeuxBarresPortentLeMemeBandeau(): void
    {
        self::assertStringContainsString('jsb-onglets-barre', $this->lire(self::TWIG_WORKSPACE));
        self::assertStringContainsString('jsb-onglets-barre', $this->lire(self::TWIG_RUBRIQUE));

        $barre = $this->corpsDeRegle($this->lire(self::CSS_PARTAGE), '.jsb-onglets-barre');
        self::assertStringContainsString('background-color: var(--bg-light)', $barre);
        self::assertStringContainsString('border-bottom: 1px solid var(--border-light)', $barre);
    }

    /**
     * La barre d'une rubrique n'est plus nichée dans la colonne du titre.
     *
     * Elle y vivait à droite de l'icône de rubrique : son bandeau y commençait 56 px trop
     * loin et s'arrêtait avant le bord. Un bandeau qui ne traverse pas ne se lit pas comme
     * le socle des onglets, mais comme une boîte posée au milieu de l'entête.
     */
    public function testLaBarreDeRubriqueTraverseLEntete(): void
    {
        $rubrique = $this->lire(self::TWIG_RUBRIQUE);

        $titre = strpos($rubrique, 'class="rubrique-title"');
        $barre = strpos($rubrique, 'jsb-onglets-barre');
        self::assertNotFalse($titre);
        self::assertNotFalse($barre);
        self::assertGreaterThan(
            $titre,
            $barre,
            "La barre doit suivre la rangée du titre, pas y être imbriquée.",
        );

        // Le <h2> se referme AVANT la barre : si elle était redevenue sa voisine dans le
        // même bloc, elle hériterait de nouveau du décalage de la colonne du titre.
        $fermeTitre = strpos($rubrique, '</h2>');
        self::assertNotFalse($fermeTitre);
        self::assertGreaterThan($fermeTitre, $barre);
    }

    /** Le bouton « + N » s'assoit sur la ligne des onglets sans perdre sa cible au doigt. */
    public function testLeBoutonDeRepliGardeSaCibleTactile(): void
    {
        $css = $this->lire(self::CSS_PARTAGE);

        $bouton = $this->corpsDeRegle($css, '.list-tabs-more');
        self::assertStringContainsString('min-height: 38px', $bouton);

        // 38 px de dessin, 44 px de cible : les 3 px manquants de chaque côté sont repris
        // par une zone invisible au doigt (WCAG 2.5.5).
        self::assertMatchesRegularExpression(
            '/@media \(pointer: coarse\) \{[^@]*\.list-tabs-more::after \{[^}]*inset: -3px/s',
            $css,
            "Sans cette extension, le bouton tombe à 38 px de cible tactile.",
        );
    }

    /** L'état actif ne tient jamais à la couleur seule (WCAG 1.4.1). */
    public function testLEtatActifNeTientPasALaCouleurSeule(): void
    {
        $actif = $this->corpsDeRegle($this->lire(self::CSS_PARTAGE), '.jsb-onglet.active');

        self::assertStringContainsString('background-color', $actif);
        self::assertStringContainsString('box-shadow', $actif);
        self::assertStringContainsString('font-weight', $actif);
    }

    /**
     * Les onglets contextuels se DÉPLIENT, en cascade.
     *
     * Cocher une ligne de la liste principale fait naître six onglets d'un coup. Sans
     * transition, le changement est si net qu'il ne se rattache pas au geste qui l'a causé :
     * on voit un écran différent, pas une conséquence (Nielsen 1).
     */
    public function testLesOngletsContextuelsSeDeplient(): void
    {
        $css = $this->lire(self::CSS_PARTAGE);
        $js = $this->lire(self::JS_RUBRIQUE);

        self::assertStringContainsString('@keyframes jsb-onglet-deplie', $css);
        self::assertStringContainsString('.jsb-onglet.se-deplie {', $css);

        self::assertStringContainsString("tab.classList.add('se-deplie')", $js);
        self::assertStringContainsString('tab.style.animationDelay', $js);

        // La cascade suppose un RANG : sans lui, les six onglets entreraient ensemble.
        self::assertStringContainsString(
            '(collectionInfo, rang) => this._createTab(collectionInfo, entities[0], entityType, rang)',
            $js,
        );
        self::assertStringContainsString('Math.min(rang, 6) * 40', $js);
    }

    /**
     * L'ANIMATION NE FAUSSE JAMAIS LA MESURE DU REPLI.
     *
     * C'est le garde-fou qui compte, parce que la régression serait invisible sur le coup :
     * `DebordementOnglets.recalculer()` mesure `offsetWidth` pour décider de ce qui se
     * replie, et cette mesure a lieu PENDANT l'animation. `opacity` et `transform` la
     * laissent intacte ; `width`, `padding`, `margin` ou `font-size` feraient mesurer des
     * onglets rétrécis, afficheraient un « + N » faux, puis se corrigeraient tout seuls.
     */
    public function testLAnimationNeFaussePasLaMesureDuRepli(): void
    {
        $css = $this->lire(self::CSS_PARTAGE);

        $debut = strpos($css, '@keyframes jsb-onglet-deplie');
        self::assertNotFalse($debut);
        $fin = strpos($css, '.jsb-onglet.se-deplie', $debut);
        self::assertNotFalse($fin);
        $corps = substr($css, $debut, $fin - $debut);

        foreach (['width', 'padding', 'margin', 'font-size', 'border'] as $interdite) {
            self::assertStringNotContainsString(
                $interdite . ':',
                $corps,
                sprintf("« %s » change offsetWidth : le compteur du bouton « + N » deviendrait faux pendant l'animation.", $interdite),
            );
        }

        self::assertStringContainsString('opacity', $corps);
        self::assertStringContainsString('transform', $corps);

        // La mesure du repli reste bien branchée sur offsetWidth : si elle changeait de
        // base, la contrainte ci-dessus n'aurait plus de raison d'être et ce test
        // protégerait une règle devenue arbitraire.
        self::assertStringContainsString('offsetWidth', $this->lire(self::JS_SOCLE));
    }

    /** WCAG 2.3.3 : on retire le mouvement, pas le retour visuel. */
    public function testLeMouvementSeTaitQuandOnLeDemande(): void
    {
        $css = $this->lire(self::CSS_PARTAGE);

        self::assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\) \{[^@]*\.jsb-onglet\.se-deplie \{ animation: none !important; \}/s',
            $css,
            "Le dépliement doit se taire pour qui demande moins de mouvement.",
        );
    }

    /**
     * Le panneau des onglets repliés lit les DEUX barres avec les mêmes sélecteurs.
     *
     * Il clonait l'icône via une liste de classes propres à chaque barre — une liste qu'il
     * fallait penser à rallonger. Et il ne dimensionnait les icônes que d'un seul côté :
     * celles du panneau « + N » du workspace gardaient leur taille brute.
     */
    public function testLePanneauDeRepliNeConnaitPlusQuUnSeulJeuDeClasses(): void
    {
        $socle = $this->lire(self::JS_SOCLE);

        self::assertStringContainsString("querySelector('.jsb-onglet-icone')", $socle);
        self::assertStringNotContainsString('.list-tab-icon, .workspace-tab-icon', $socle);

        self::assertStringContainsString(
            '.list-tabs-overflow-item .jsb-onglet-icone svg',
            $this->lire(self::CSS_PARTAGE),
        );
    }
}
