import { grouperActions } from './actions-groupees.js';
import { positionnerMenu } from './menu-flottant.js';
import { CircuitIcones } from './circuit-icones.js';

/**
 * BARRE D'ACTIONS SPÉCIFIQUES — rendu PARTAGÉ par la barre d'outils du workspace et
 * par celle du dialogue d'entité.
 *
 * Le dialogue avait sa propre barre : boutons sombres maison, circuit d'icônes à part,
 * aucune famille regroupée. Deux barres qui proposent les MÊMES actions (le canevas
 * `attribute_actions`) se lisaient donc différemment selon l'écran. Ce module porte
 * désormais la seule présentation : bouton icône + infobulle, famille en bouton à
 * chevron qui déploie un menu libellé, menu posé par la géométrie partagée
 * (menu-flottant.js).
 *
 * Chaque surface garde ce qui lui est propre : QUELLES actions afficher (sélection,
 * conditions), le plafond en ligne, et ce que déclenche un clic (`declencher`).
 */

export class BarreActions {
    /**
     * @param {HTMLElement} conteneur Élément qui reçoit les boutons (déjà dans le DOM).
     * @param {{declencher: function(object): void, prefixe?: string, barre?: HTMLElement}} options
     *        `declencher(action)` est appelé au clic sur une action ; `prefixe` préfixe les
     *        identifiants de requête d'icône ; `barre` est l'élément `role="toolbar"` dont
     *        on pilote la navigation clavier (par défaut, le conteneur lui-même — dans le
     *        workspace, le conteneur n'est qu'un groupe d'une barre plus large) ;
     *        `libelles` écrit le libellé à côté de l'icône — pour une barre qui a la place
     *        de dire ce que fait chaque bouton au lieu de le faire deviner au survol.
     */
    constructor(conteneur, { declencher, prefixe = 'barre-actions', barre = conteneur, libelles = false }) {
        this.conteneur = conteneur;
        this.libelles = libelles;
        this.declencher = declencher;
        this.prefixe = prefixe;
        this.barre = barre;
        this.menuOuvert = null;
        this.desactivation = null;
        this.courant = null;

        // Le circuit d'icônes est PARTAGÉ avec le menu contextuel (circuit-icones.js).
        this.icones = new CircuitIcones(conteneur, {
            prefixe,
            habiller: (svg) => svg.classList.add('toolbar-icon'),
        });

        // ── UN SEUL ARRÊT DE TABULATION, PUIS LES FLÈCHES ─────────────────────────
        // C'est ce que promet `role="toolbar"` (WAI-ARIA APG) : Tab entre dans la barre
        // et en sort d'un seul coup ; ← → Début Fin parcourent ses boutons. Sans cela,
        // une barre de douze boutons coûtait douze Tab avant d'atteindre le formulaire.
        this.boundNavigation = this._surToucheBarre.bind(this);
        this.boundFocus = this._surFocusBarre.bind(this);
        this.barre.addEventListener('keydown', this.boundNavigation);
        this.barre.addEventListener('focusin', this.boundFocus);
    }

    /**
     * Remplace le contenu de la barre par les actions données.
     *
     * @param {object[]} actions Actions déjà filtrées par la surface (sélection, conditions).
     * @param {{maxInline?: number}} [options] Plafond d'entrées en ligne (0 = aucun).
     */
    afficher(actions, { maxInline = 0 } = {}) {
        this.fermerMenu();
        this.conteneur.innerHTML = '';

        // REGROUPEMENT PAR FAMILLE : une famille d'actions (les mouvements d'une
        // police, par exemple) devient UN bouton qui déploie ses membres.
        grouperActions(actions, { maxInline }).forEach((entree) => {
            this.conteneur.appendChild(
                entree.type === 'groupe' ? this._creerBoutonGroupe(entree) : this._creerBoutonAction(entree.action),
            );
        });

        this._appliquerDesactivation();
        this.rafraichirNavigation();
    }

    /**
     * Grise les actions SANS les masquer, et dit pourquoi par l'infobulle.
     *
     * `aria-disabled` et non `disabled` : le bouton reste focalisable et survolable, son
     * infobulle explique donc ce qu'il faut faire au lieu de laisser deviner. Le clic,
     * lui, est neutralisé (cf. `_activer`).
     *
     * @param {boolean} desactivee
     * @param {string} [raison] Infobulle affichée tant que la barre est grisée.
     */
    desactiver(desactivee, raison = '') {
        this.desactivation = desactivee ? raison : null;
        if (desactivee) this.fermerMenu();
        this._appliquerDesactivation();
    }

    /** @private */
    _appliquerDesactivation() {
        this._boutonsDeBarre(this.conteneur).forEach((bouton) => {
            const libelle = bouton.getAttribute('aria-label') || '';
            bouton.setAttribute('aria-disabled', this.desactivation !== null ? 'true' : 'false');
            bouton.setAttribute('title', this.desactivation ? `${libelle} — ${this.desactivation}` : libelle);
        });
    }

    /**
     * Le clic d'une action ou d'une famille passe ici : rien ne part tant que la barre
     * est grisée.
     * @private
     */
    _activer(geste) {
        if (this.desactivation !== null) return;
        geste();
    }

    /**
     * Les boutons de PREMIER NIVEAU d'un élément — jamais les entrées d'un menu de
     * famille, qui ont leur propre clavier.
     * @private
     */
    _boutonsDeBarre(racine) {
        return [...racine.querySelectorAll('button')].filter((b) => !b.closest('[role="menu"]'));
    }

    /**
     * Les boutons que les flèches parcourent : visibles et pas nativement désactivés
     * (un bouton `disabled` ne peut pas recevoir le focus).
     * @private
     */
    _arretsClavier() {
        return this._boutonsDeBarre(this.barre).filter((b) => !b.disabled && b.getClientRects().length > 0);
    }

    /**
     * Pose le tabindex itinérant : 0 sur le bouton courant, -1 sur tous les autres.
     *
     * À rappeler quand la surface montre ou cache des boutons (le workspace le fait à
     * chaque changement de sélection).
     */
    rafraichirNavigation() {
        // Barre encore masquée (dialogue qui se charge, onglet en arrière-plan) : aucun
        // bouton n'est mesurable, mais l'arrêt unique doit être posé dès maintenant —
        // sinon chacun garde son tabindex natif, et la barre redevient une suite de Tab.
        const visibles = this._arretsClavier();
        const arrets = visibles.length > 0 ? visibles : this._boutonsDeBarre(this.barre).filter((b) => !b.disabled);
        if (arrets.length === 0) return;
        if (!arrets.includes(this.courant)) this.courant = arrets[0];
        this._boutonsDeBarre(this.barre).forEach((b) => b.setAttribute('tabindex', b === this.courant ? '0' : '-1'));
    }

    /** @private */
    _surFocusBarre(event) {
        const bouton = event.target.closest?.('button');
        if (!bouton || bouton.closest('[role="menu"]') || !this._arretsClavier().includes(bouton)) return;
        this.courant = bouton;
        this.rafraichirNavigation();
    }

    /** @private */
    _surToucheBarre(event) {
        if (event.target.closest?.('[role="menu"]')) return;
        const arrets = this._arretsClavier();
        const index = arrets.indexOf(event.target.closest?.('button'));
        if (index === -1) return;

        const cible = {
            ArrowRight: arrets[(index + 1) % arrets.length],
            ArrowLeft: arrets[(index - 1 + arrets.length) % arrets.length],
            Home: arrets[0],
            End: arrets[arrets.length - 1],
        }[event.key];
        if (!cible) return;

        event.preventDefault();
        this.courant = cible;
        this.rafraichirNavigation();
        cible.focus();
    }

    /** Demande au cerveau, en avance, les icônes absentes du cache. */
    precharger(actions) {
        this.icones.precharger((actions || []).map((action) => action.icon));
    }

    /**
     * Retire les écouteurs : à appeler quand la surface se déconnecte. Un menu resté
     * déplié est refermé, sans quoi ses écouteurs de document lui survivraient.
     */
    detruire() {
        this.fermerMenu();
        this.icones.detruire();
        this.barre.removeEventListener('keydown', this.boundNavigation);
        this.barre.removeEventListener('focusin', this.boundFocus);
    }

    /**
     * Bouton d'une action simple : icône seule + infobulle (`title`, lu par dark-tooltip).
     * @private
     */
    _creerBoutonAction(action) {
        const button = document.createElement('button');
        button.className = 'btn btn-default';
        button.setAttribute('type', 'button');
        button.setAttribute('title', action.label);
        button.setAttribute('aria-label', action.label);
        button.setAttribute('data-controller', 'ripple');

        this._habiller(button, action.icon, action.label);

        button.addEventListener('click', () => this._activer(() => this.declencher(action)));

        return button;
    }

    /**
     * Pose l'icône d'un bouton de barre — et, si la surface le demande, son LIBELLÉ.
     *
     * Avec libellé, l'icône va dans un porteur dédié : l'arrivée de l'icône remplace le
     * contenu de sa cible, et effacerait sinon le texte posé à côté.
     * @private
     */
    _habiller(button, alias, libelle) {
        if (!this.libelles) {
            this.icones.poser(button, alias);
            return;
        }
        const porteur = document.createElement('span');
        porteur.className = 'toolbar-icone';
        porteur.setAttribute('aria-hidden', 'true');
        const texte = document.createElement('span');
        texte.className = 'toolbar-libelle';
        texte.textContent = libelle;
        button.classList.add('a-libelle');
        button.append(porteur, texte);
        this.icones.poser(porteur, alias);
    }

    /**
     * Bouton d'une FAMILLE : icône + chevron, ouvrant un menu déroulant qui liste
     * les actions membres (icône + libellé, cette fois explicite — un menu a la
     * place d'écrire, contrairement à la barre).
     * @private
     */
    _creerBoutonGroupe(groupe) {
        const wrapper = document.createElement('div');
        wrapper.className = 'toolbar-groupe';

        const button = document.createElement('button');
        button.className = 'btn btn-default toolbar-groupe-bouton';
        button.setAttribute('type', 'button');
        button.setAttribute('title', groupe.label);
        button.setAttribute('aria-label', groupe.label);
        button.setAttribute('aria-haspopup', 'menu');
        button.setAttribute('aria-expanded', 'false');
        // Le chevron « ce bouton déploie un menu » est un ::after CSS, PAS un élément :
        // l'arrivée de l'icône remplace l'innerHTML du bouton et effacerait un enfant
        // posé ici.
        this._habiller(button, groupe.icon, groupe.label);

        const menu = document.createElement('ul');
        menu.className = 'toolbar-groupe-menu';
        menu.setAttribute('role', 'menu');
        menu.setAttribute('aria-label', groupe.label);
        menu.hidden = true;

        groupe.actions.forEach((action) => {
            const item = document.createElement('li');
            item.setAttribute('role', 'menuitem');
            item.setAttribute('tabindex', '-1');

            const icone = document.createElement('span');
            icone.className = 'toolbar-groupe-menu-icone';
            icone.setAttribute('aria-hidden', 'true');
            this.icones.poser(icone, action.icon, 18);
            item.appendChild(icone);

            const libelle = document.createElement('span');
            libelle.textContent = action.label;
            item.appendChild(libelle);

            item.addEventListener('click', (event) => {
                event.stopPropagation();
                this.fermerMenu();
                this.declencher(action);
            });
            menu.appendChild(item);
        });

        button.addEventListener('click', (event) => {
            event.stopPropagation();
            this._activer(() => {
                const ouvert = !menu.hidden;
                this.fermerMenu();
                if (!ouvert) this._ouvrirMenu(button, menu);
            });
        });

        wrapper.append(button, menu);

        return wrapper;
    }

    /** Ouvre le menu d'une famille et arme la fermeture (clic extérieur / Échap). @private */
    _ouvrirMenu(button, menu) {
        menu.hidden = false;
        button.setAttribute('aria-expanded', 'true');
        this.menuOuvert = { button, menu };

        // ── OÙ LE MENU SE POSE ─────────────────────────────────────────────────────
        //
        // Il se posait en `absolute` dans la barre, avec une règle CSS qui le rabattait
        // à droite dès qu'il était le DERNIER bouton — au motif qu'un dernier bouton est
        // en fin de barre. C'est faux dès que la barre en porte peu : le bouton se
        // trouve alors à GAUCHE, le menu part vers l'arrière, et il sort du panneau, qui
        // le rogne. On lisait « …pteurs de congés ».
        //
        // On mesure donc, et l'on pose en coordonnées viewport avec la géométrie
        // PARTAGÉE (menu-flottant.js) : celle du menu de bulle, du chip-sélecteur et du
        // menu contextuel. Elle bascule au-dessus s'il n'y a pas la place dessous et
        // écrête aux bords — un menu ne peut plus sortir de l'écran, où qu'il s'ouvre.
        // `fixed` le sort au passage de tout ancêtre à `overflow: hidden`.
        this._positionnerMenu(button, menu);

        // La barre défile et se réagence (flex-wrap) : le menu doit suivre son bouton,
        // faute de quoi il resterait posé là où le bouton n'est plus.
        this.boundSuivreMenu = () => this._positionnerMenu(button, menu);
        window.addEventListener('resize', this.boundSuivreMenu);
        window.addEventListener('scroll', this.boundSuivreMenu, true);

        this.boundFermerMenu = (event) => {
            if (!menu.contains(event.target)) this.fermerMenu();
        };
        // ── LE CLAVIER DU MENU, ET ÉCHAP QUI NE FERME QUE LUI ──────────────────────
        // Écouté en phase de CAPTURE sur le document : il passe donc avant tout écouteur
        // posé plus bas — celui de la modale Bootstrap en particulier. Sans cela, Échap
        // refermait le menu ET parvenait à la boîte de dialogue qui le contient.
        this.boundEchapMenu = (event) => {
            const entrees = [...menu.querySelectorAll('[role="menuitem"]')];
            const index = entrees.indexOf(document.activeElement);

            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                this.fermerMenu();
                button.focus(); // restitution du focus au déclencheur (WCAG 2.4.3)
            } else if (event.key === 'Tab') {
                this.fermerMenu();
            } else if (index !== -1 && ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
                event.preventDefault();
                const suivante = {
                    ArrowDown: entrees[(index + 1) % entrees.length],
                    ArrowUp: entrees[(index - 1 + entrees.length) % entrees.length],
                    Home: entrees[0],
                    End: entrees[entrees.length - 1],
                }[event.key];
                suivante.focus();
            } else if (index !== -1 && (event.key === 'Enter' || event.key === ' ')) {
                event.preventDefault();
                entrees[index].click();
            }
        };
        document.addEventListener('click', this.boundFermerMenu);
        document.addEventListener('keydown', this.boundEchapMenu, true);

        menu.querySelector('[role="menuitem"]')?.focus();
    }

    /** Pose le menu d'une famille sous son bouton, en coordonnées viewport. @private */
    _positionnerMenu(button, menu) {
        const { left, top } = positionnerMenu({
            ancre: button.getBoundingClientRect(),
            menu: { largeur: menu.offsetWidth, hauteur: menu.offsetHeight },
            viewport: { largeur: window.innerWidth, hauteur: window.innerHeight },
            // À GAUCHE : le menu s'ouvre du côté où le bouton commence, dans le sens du
            // geste. Aligné à droite, il partait vers l'arrière.
            alignement: 'gauche',
        });
        menu.style.left = `${left}px`;
        menu.style.top = `${top}px`;
    }

    /** Referme le menu de famille ouvert, s'il y en a un. */
    fermerMenu() {
        if (this.boundSuivreMenu) {
            window.removeEventListener('resize', this.boundSuivreMenu);
            window.removeEventListener('scroll', this.boundSuivreMenu, true);
            this.boundSuivreMenu = null;
        }
        if (this.boundFermerMenu) {
            document.removeEventListener('click', this.boundFermerMenu);
            this.boundFermerMenu = null;
        }
        if (this.boundEchapMenu) {
            document.removeEventListener('keydown', this.boundEchapMenu, true);
            this.boundEchapMenu = null;
        }
        if (!this.menuOuvert) return;
        this.menuOuvert.menu.hidden = true;
        this.menuOuvert.button.setAttribute('aria-expanded', 'false');
        this.menuOuvert = null;
    }
}
