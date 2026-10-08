<?php

namespace App\Tests\Workspace;

use PHPUnit\Framework\TestCase;

/**
 * LES ONGLETS D'UN FORMULAIRE DE SAISIE.
 *
 * Un dialogue empilait ses champs puis ses collections, chacune repliée derrière un
 * accordéon. Chaque collection est désormais un ONGLET, et les champs ordinaires forment
 * « Principal » — la même grammaire que les deux barres du workspace, le même composant
 * `.jsb-onglet*`, le même module de repli.
 *
 * ── POURQUOI CE TEST LIT DES FICHIERS ───────────────────────────────────────────────
 * Comme OngletsMemeGrammaireTest : le rendu vit dans le DOM, et la suite JavaScript du
 * projet n'en a pas (aucun jsdom en dépendance, délibérément). On vérifie donc ce qui se
 * vérifie sans navigateur — et surtout les quelques points où une erreur serait SILENCIEUSE :
 * un dialogue sans collection qui se mettrait à porter une barre, une liste qui ne se
 * chargerait jamais, un champ obligatoire qui cesserait d'être exigé.
 */
class OngletsDeFormulaireTest extends TestCase
{
    private const TWIG_DIALOGUE = __DIR__ . '/../../templates/components/dialog/_form_content.html.twig';
    private const TWIG_RANGEE = __DIR__ . '/../../templates/components/dialog/_form_row.html.twig';
    private const TWIG_COLLECTION = __DIR__ . '/../../templates/themes/_collection_widget.html.twig';
    private const JS_ONGLETS = __DIR__ . '/../../assets/controllers/onglets-formulaire_controller.js';
    private const JS_COLLECTION = __DIR__ . '/../../assets/controllers/collection_controller.js';
    private const JS_DIALOGUE = __DIR__ . '/../../assets/controllers/dialog-instance_controller.js';
    private const CSS_PARTAGE = __DIR__ . '/../../assets/styles/app.css';
    private const CSS_WORKSPACE = __DIR__ . '/../../assets/styles/interactive-menu.css';
    private const TRAIT_CANEVAS = __DIR__ . '/../../src/Services/Canvas/Provider/Form/FormCanvasProviderTrait.php';
    private const TRAIT_CONTROLEUR = __DIR__ . '/../../src/Controller/Admin/ControllerUtilsTrait.php';

    private function lire(string $chemin): string
    {
        self::assertFileExists($chemin);

        return str_replace("\r\n", "\n", (string) file_get_contents($chemin));
    }

    /**
     * UN DIALOGUE SANS COLLECTION NE BOUGE PAS D'UN PIXEL.
     *
     * C'est la garantie de non-régression la moins chère à tenir, et la plus précieuse :
     * la grande majorité des dialogues n'a aucune collection. Sans la branche « aucun
     * onglet », ils hériteraient tous d'une barre à un seul onglet qui ne sert à rien.
     */
    public function testUnDialogueSansCollectionNeRendAucuneBarre(): void
    {
        $twig = $this->lire(self::TWIG_DIALOGUE);

        self::assertStringContainsString('{% if rangees_onglets is empty %}', $twig);

        // Dans cette branche, on rend les rangées en flux — et rien d'autre.
        //
        // Le `{% else %}` recherché est celui de CE bloc : le gabarit en compte d'autres
        // bien avant (l'entête contextuel), et partir du premier donnerait une borne
        // négative — donc une branche qui déborde sur tout le reste du fichier.
        $debut = (int) strpos($twig, '{% if rangees_onglets is empty %}');
        $branche = substr($twig, $debut, (int) strpos($twig, '{% else %}', $debut) - $debut);
        self::assertStringContainsString("_form_row.html.twig", $branche);
        self::assertStringNotContainsString('jsb-onglets-barre', $branche);
    }

    /**
     * La partition se fait par `|filter`, et SURTOUT PAS par une boucle avec `set` : en
     * Twig, une variable posée dans un `for` ne survit pas à la sortie de la boucle. La
     * partition repartirait vide — sans la moindre erreur pour le dire.
     */
    public function testLaPartitionNeReposePasSurUneVariableDeBoucle(): void
    {
        $twig = $this->lire(self::TWIG_DIALOGUE);

        self::assertStringContainsString(
            "{% set rangees_onglets = formCanvas.form_layout|default([])|filter(r => r.onglet_titre is defined) %}",
            $twig,
        );
        self::assertStringContainsString(
            "{% set rangees_principales = formCanvas.form_layout|default([])|filter(r => r.onglet_titre is not defined) %}",
            $twig,
        );
    }

    /** Un onglet par rangée de collection, et un panneau qui lui répond. */
    public function testChaqueRangeeDOngletProduitUnOngletEtUnPanneau(): void
    {
        $twig = $this->lire(self::TWIG_DIALOGUE);

        // La barre porte le composant partagé — pas un dessin maison.
        self::assertStringContainsString('class="jsb-onglets-barre"', $twig);
        self::assertStringContainsString('class="jsb-onglets-rangee" role="tablist"', $twig);
        self::assertStringContainsString('class="jsb-onglet-titre">{{ row.onglet_titre }}', $twig);
        self::assertStringContainsString('class="jsb-onglets-panneau', $twig);

        // Deux boucles sur la même partition : les onglets, puis les panneaux.
        self::assertSame(2, substr_count($twig, '{% for row in rangees_onglets %}'));

        // L'onglet et son panneau se répondent par aria-controls / aria-labelledby.
        self::assertStringContainsString('aria-controls="{{ uid }}-panneau-{{ field_code }}"', $twig);
        self::assertStringContainsString('aria-labelledby="{{ uid }}-onglet-', $twig);
    }

    /**
     * LES IDENTIFIANTS SONT PRÉFIXÉS PAR INSTANCE.
     *
     * Deux dialogues coexistent dès qu'on ouvre une cotation depuis la liste d'une piste.
     * Sans préfixe, deux `id="onglet-principal"` dans le même document : `aria-controls`
     * et `querySelector('#…')` désigneraient le mauvais dialogue, et cliquer un onglet de
     * l'enfant remuerait le parent.
     */
    public function testLesIdentifiantsSontPrefixesParInstance(): void
    {
        $twig = $this->lire(self::TWIG_DIALOGUE);

        self::assertMatchesRegularExpression("/\{% set uid = 'ong' ~ random\(/", $twig);

        // Tout `id="…"` du bloc d'onglets passe par {{ uid }}.
        //
        // Les commentaires Twig sont retirés avant l'examen : ils CITENT justement des
        // identifiants pour expliquer le piège, et un commentaire n'est pas du balisage.
        $balisage = (string) preg_replace('/\{#.*?#\}/s', '', $twig);
        preg_match_all('/\bid="([^"]*)"/', $balisage, $trouves);
        foreach ($trouves[1] as $valeur) {
            if (!str_contains($valeur, 'onglet') && !str_contains($valeur, 'panneau')) {
                continue;
            }
            self::assertStringContainsString(
                '{{ uid }}',
                $valeur,
                sprintf('L\'identifiant « %s » n\'est pas préfixé : deux dialogues ouverts se confondraient.', $valeur),
            );
        }
    }

    /**
     * LE CHARGEMENT N'EST PLUS INCONDITIONNEL — et l'attribut est lu AVANT d'être écrit.
     *
     * L'ordre est tout : un contrôleur qui se branche après l'ouverture de son onglet n'a
     * pas entendu l'événement de réveil. Si le marquage « non » écrasait le « oui » déjà
     * posé, sa liste resterait vide pour toujours.
     */
    public function testLeChargementEstParesseuxSansJamaisEcraserUnReveil(): void
    {
        $js = $this->lire(self::JS_COLLECTION);

        $lecture = strpos($js, "this.element.dataset.chargeDemandee === 'oui'");
        $ecriture = strpos($js, "this.element.dataset.chargeDemandee = 'non'");
        self::assertNotFalse($lecture, "La branche du réveil déjà demandé a disparu.");
        self::assertNotFalse($ecriture);
        self::assertLessThan(
            $ecriture,
            $lecture,
            "L'attribut est écrit avant d'être lu : un onglet ouvert trop tôt garderait une liste vide.",
        );

        // L'écouteur est posé AVANT le marquage, pour qu'aucun réveil ne se perde entre les deux.
        $ecouteur = strpos($js, "this.element.addEventListener('app:collection.charger'");
        self::assertNotFalse($ecouteur);
        self::assertLessThan($ecriture, $ecouteur);

        // Trois cas ne se diffèrent pas, et chacun pour sa raison.
        self::assertStringContainsString("panneau.classList.contains('est-cache')", $js);
        self::assertStringContainsString('!this.differeValue', $js);
        self::assertStringContainsString("startsWith('data-collection-default-value-config')", $js);
    }

    /**
     * Une collection endormie le reste : `refresh()` ne doit pas faire rentrer par la
     * fenêtre les requêtes que le chargement paresseux vient de faire sortir par la porte.
     */
    public function testUneCollectionEndormieNEstPasReveilleeParUnRafraichissement(): void
    {
        $js = $this->lire(self::JS_COLLECTION);
        $refresh = substr($js, (int) strpos($js, '    refresh(event) {'), 600);

        self::assertStringContainsString("if (this.element.dataset.chargeDemandee === 'non') return;", $refresh);
    }

    /**
     * UN CHAMP MASQUÉ PAR LE SEUL ONGLET RESTE EXIGÉ.
     *
     * `offsetParent === null` suffisait à écarter un champ du contrôle avant envoi. Depuis
     * que le formulaire a des onglets, un champ requis de « Principal » est invisible dès
     * qu'un autre onglet est ouvert : il aurait été silencieusement dispensé d'être rempli.
     */
    public function testUnChampMasqueParLeSeulOngletResteValide(): void
    {
        $js = $this->lire(self::JS_DIALOGUE);

        self::assertStringContainsString(
            "const masqueParOnglet = champ.closest('.jsb-onglets-panneau.est-cache');",
            $js,
        );
        self::assertStringNotContainsString(
            'if (champ.offsetParent === null || champ.disabled) return;',
            $js,
            "Le contrôle avant envoi ignore de nouveau tout champ invisible, onglet compris.",
        );
    }

    /** Une erreur hors de l'onglet ouvert y ramène : sinon le refus d'enregistrer ne montre rien. */
    public function testUneErreurRevelesonOnglet(): void
    {
        $js = $this->lire(self::JS_DIALOGUE);

        self::assertSame(
            2,
            substr_count($js, "this._notifierLesOnglets('app:formulaire-onglets.reveler'"),
            "La révélation doit valoir pour les erreurs SERVEUR comme pour le contrôle avant envoi.",
        );
        // La barre est visée directement, jamais `document` : deux dialogues peuvent être ouverts.
        self::assertStringContainsString(
            'this.contentTarget?.querySelector(\'[data-controller~="onglets-formulaire"]\')',
            $js,
        );
    }

    /**
     * AUCUN `aria-label` SUR UN ONGLET : il remplacerait le nom accessible entier et
     * effacerait le compte. Le nom se lit « Cotations 3 éléments ».
     */
    public function testAucunAriaLabelSurUnOnglet(): void
    {
        $twig = $this->lire(self::TWIG_DIALOGUE);

        foreach (explode('<button', $twig) as $bouton) {
            if (!str_contains($bouton, 'class="jsb-onglet')) {
                continue;
            }
            $ouverture = substr($bouton, 0, (int) strpos($bouton, '>'));
            self::assertStringNotContainsString(
                'aria-label',
                $ouverture,
                "Un aria-label sur l'onglet effacerait le compte du nom accessible.",
            );
        }

        // Le nombre est accordé, des deux côtés : « 1 élément », jamais « 1 éléments ».
        self::assertStringContainsString("compte > 1 ? 'éléments' : 'élément'", $twig);
        self::assertStringContainsString("compte > 1 ? ' éléments' : ' élément'", $this->lire(self::JS_ONGLETS));
    }

    /**
     * LA PASTILLE SE CRÉE QUAND ELLE MANQUE. En création, le parent n'a pas d'id : le
     * serveur ne compte rien, et une cotation mise en attente dans le tampon n'aurait nulle
     * part où s'afficher.
     */
    public function testLaPastilleDeCompteSeCreeEtSeRetire(): void
    {
        $js = $this->lire(self::JS_ONGLETS);

        self::assertStringContainsString("pastille.className = 'jsb-onglet-compte';", $js);
        self::assertStringContainsString('onglet.insertBefore(pastille', $js);
        self::assertStringContainsString('pastille?.remove();', $js);

        // Et la collection diffuse bien son compte à chaque mise à jour.
        self::assertStringContainsString(
            "this.element.dispatchEvent(new CustomEvent('app:collection.compte'",
            $this->lire(self::JS_COLLECTION),
        );
    }

    /**
     * L'ONGLET SUIT LA VISIBILITÉ DE SA COLLECTION, DANS LES DEUX SENS.
     *
     * Une collection conditionnelle (le partage exceptionnel d'une piste) est masquée tant
     * qu'aucun partenaire n'est choisi. Son onglet doit disparaître avec elle — sinon il
     * s'ouvre sur du vide — et revenir avec elle.
     */
    public function testLOngletSuitLaVisibiliteDeSaCollection(): void
    {
        $js = $this->lire(self::JS_ONGLETS);

        self::assertStringContainsString('new MutationObserver(', $js);
        self::assertStringContainsString("attributeFilter: ['class']", $js);
        // `toggle` et non `add` : c'est ce qui fait la bidirectionnalité.
        self::assertStringContainsString("onglet.classList.toggle('d-none', masque);", $js);
        // Et une passe initiale, qui ne dépend d'aucune mutation.
        self::assertStringContainsString('requestAnimationFrame(() => {', $js);
        self::assertStringContainsString('this._synchroniserLaVisibilite();', $js);
    }

    /** Les flèches sautent les onglets masqués : le focus ne va pas sur ce qu'on ne voit pas. */
    public function testLesFlechesSautentLesOngletsMasques(): void
    {
        $js = $this->lire(self::JS_ONGLETS);
        $clavier = substr($js, (int) strpos($js, 'handleTabKeydown(event)'), 900);

        self::assertStringContainsString(
            "const visibles = this.ongletTargets.filter((o) => !o.classList.contains('d-none'));",
            $clavier,
        );
    }

    /**
     * L'ENVELOPPE DE PANNEAU NE S'INSÈRE PAS ENTRE UNE RANGÉE ET SES COLONNES.
     *
     * `dialog-instance#checkFormVisibility` compte `row.children` pour décider si une
     * rangée reste visible : un wrapper glissé là ferait compter une colonne unique, et
     * toute rangée resterait visible quoi qu'il arrive.
     */
    public function testLEnveloppeDePanneauNeSInserePasDansUneRangee(): void
    {
        // Les rangées ne sont écrites QUE dans le gabarit de rangée.
        self::assertStringNotContainsString(
            'data-dialog-instance-target="formRow',
            $this->lire(self::TWIG_DIALOGUE),
            'Une rangée est écrite hors du gabarit de rangée : les deux rendus vont diverger.',
        );
        self::assertStringContainsString(
            'data-dialog-instance-target="formRow',
            $this->lire(self::TWIG_RANGEE),
        );
    }

    /**
     * L'ACCORDÉON EST LEVÉ — PAR SURCHARGE, ET NON PAR SIMPLE SUPPRESSION.
     *
     * `.accordion-content` désigne AUSSI le panneau des attributs calculés du workspace
     * (interactive-menu.css), et cette règle-là n'est pas scopée : chargée après app.css,
     * elle retomberait sur la liste du dialogue et la replierait, sans plus aucun moyen de
     * l'ouvrir.
     */
    public function testLaListeEstOuverteSansRouvrirLAccordeonDuWorkspace(): void
    {
        $partage = $this->lire(self::CSS_PARTAGE);
        $workspace = $this->lire(self::CSS_WORKSPACE);

        self::assertStringContainsString(".collection-manager-accordion .accordion-content {\n    max-height: none;", $partage);
        self::assertStringNotContainsString("\n.accordion-content {", $partage);
        self::assertStringNotContainsString('.accordion-content.is-open', $partage);

        // Le workspace garde le sien, intact : c'est un autre composant.
        self::assertStringContainsString('.accordion-content.open', $workspace);

        // Et le widget n'a plus ni poignée ni disque.
        $widget = $this->lire(self::TWIG_COLLECTION);
        self::assertStringNotContainsString('toggleAccordion', $widget);
        self::assertStringNotContainsString('toggle-icon', $widget);
        self::assertStringNotContainsString('toggleAccordion', $this->lire(self::JS_COLLECTION));
    }

    /**
     * LE COMPTE D'UN ONGLET NE CHARGE PAS LA COLLECTION, et ne dit rien de ce que la liste
     * refuserait de montrer. Trois gardes, dont le contrôle d'accès de l'endpoint de liste.
     */
    public function testLeCompteDUnOngletCompteSansHydraterNiFuiter(): void
    {
        $trait = $this->lire(self::TRAIT_CONTROLEUR);

        self::assertStringContainsString('SELECT COUNT(c) FROM %s p JOIN p.%s c WHERE p = :parent', $trait);
        // On examine le CORPS de la méthode, et non son explication : le docbloc cite
        // justement `count()` pour dire pourquoi on ne s'en sert pas.
        $corps = substr($trait, (int) strpos($trait, 'private function renseignerLesComptesDOnglets'), 2500);
        self::assertStringNotContainsString('->count()', $corps);
        self::assertStringContainsString('hasAssociation($fieldName)', $trait);
        self::assertStringContainsString('mayAccessEntity($classeEnfant, Invite::ACCESS_LECTURE)', $trait);

        // Et la rangée de collection se déclare bien comme un onglet.
        self::assertStringContainsString(
            "\"onglet_titre\" => \$config['ongletTitre'] ?? \$config['formTitle'],",
            $this->lire(self::TRAIT_CANEVAS),
        );
    }

    /**
     * LE BANDEAU NE LAISSE AUCUNE BANDE DECOUVERTE AU-DESSUS DE LUI.
     *
     * A `top: 0`, il se calait 24 px sous le bord visible de `.form-column` — la hauteur
     * de sa gouttiere — et cette bande restait a decouvert : le contenu y defilait a cote
     * du bandeau (un titre de carte, la barre d'outils de l'editeur riche), ce qui se
     * lisait comme un chevauchement. Mesure au navigateur : bande de 24 px avant, 0 px
     * apres.
     *
     * Le nombre est donc COUPLE au padding de la colonne : ce test tient les deux
     * ensemble, pour que changer l'un sans l'autre se voie.
     */
    public function testLeBandeauNeLaissePasDeBandeDecouverte(): void
    {
        $css = $this->lire(self::CSS_PARTAGE);

        $bandeau = $this->corpsDeRegle($css, '.form-column .jsb-onglets-barre');
        self::assertStringContainsString('position: sticky', $bandeau);
        // La colonne n'a plus de gouttiere (grammaire du workspace) : le decalage vaut 0.
        // Le couplage, lui, demeure — si l'une revient, l'autre doit suivre.
        self::assertStringContainsString(
            'top: 0;',
            $bandeau,
            'Le decalage du bandeau doit valoir la gouttiere haute de la colonne, en negatif.',
        );

        $colonne = $this->corpsDeRegle($css, '.form-column');
        self::assertStringContainsString(
            'padding: 0;',
            $colonne,
            'Le decalage du bandeau vaut cette gouttiere : changer l\'une oblige a changer l\'autre.',
        );
    }

    /** Le corps d'une regle CSS, volontairement naif : aucune accolade imbriquee ici. */
    private function corpsDeRegle(string $css, string $selecteur): string
    {
        $debut = strpos($css, "\n" . $selecteur . ' {');
        self::assertNotFalse($debut, sprintf('Regle « %s » absente.', $selecteur));
        $ouvre = (int) strpos($css, '{', $debut);
        $ferme = (int) strpos($css, '}', $ouvre);

        return substr($css, $ouvre + 1, $ferme - $ouvre - 1);
    }
}
