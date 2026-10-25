import { ancreAuPoint, contextmenuAuClavier, estToucheDeMenu, positionnerMenu, toucheDuMenu } from './menu-flottant.js';
import { fermerInfobulle } from './infobulle-moteur.js';

/**
 * LA COQUILLE COMMUNE DES MENUS CONTEXTUELS (chantier 3, commit 4).
 *
 * Un module ordinaire, sans Stimulus, comme le moteur de l'infobulle : chaque écran garde SON
 * menu (rendu par le serveur) et SES actions ; la coquille fait le reste, une seule fois —
 *   - ouvrir au curseur (clic droit) ou sous un élément (bouton ⋮, ligne au clavier), mesuré
 *     puis borné à l'écran (menu-flottant.js) ;
 *   - au clavier : flèches, Début/Fin, Entrée/Espace, Échap (le focus revient à l'élément
 *     d'origine), Tab (le menu se ferme, le focus suit) ;
 *   - fermer au clic dehors, au défilement, au redimensionnement ;
 *   - UN SEUL MENU OUVERT dans la page : en ouvrir un ferme le précédent (et l'infobulle).
 *
 * Les entrées sont les `[role="menuitem"]` VISIBLES du menu (une entrée masquée, un sous-menu
 * replié, ne comptent pas).
 *
 * Les touches s'écoutent sur `window` en CAPTURE : avant le gestionnaire des dialogues (sur
 * `document`) — l'Échap qui ferme un menu ne ferme pas aussi le dialogue dessous.
 */

/** Le menu ouvert, s'il y en a un (une instance de MenuContextuel). */
let ouvert = null;

/** Touches qui feraient défiler la page sous un menu ouvert (et donc le fermeraient). */
const TOUCHES_DE_DEFILEMENT = [' ', 'PageUp', 'PageDown'];

/** L'instant de la dernière touche Menu ou Maj+F10 (Firefox ne marque pas son `contextmenu`). */
let instantToucheDeMenu = -Infinity;
// Au relâchement aussi : sous Windows, le `contextmenu` de Firefox part au relâchement — une
// touche tenue plus d'une seconde ne fait pas passer le geste pour un clic.
const noterLaToucheDeMenu = (e) => {
    if (estToucheDeMenu(e.key, e.shiftKey)) instantToucheDeMenu = Date.now();
};
window.addEventListener('keydown', noterLaToucheDeMenu, true);
window.addEventListener('keyup', noterLaToucheDeMenu, true);

/**
 * Le geste d'ouverture d'un `contextmenu`, prêt pour `MenuContextuel.ouvrir()` :
 *   - au CLAVIER (touche Menu, Maj+F10 — WCAG 2.1.1) : sous la ligne (son <summary> quand c'est
 *     une ligne dépliable : dépliée, la ligne entière descendrait loin), le focus sur la
 *     première entrée ; il reviendra à l'élément focalisé à la fermeture ;
 *   - à la SOURIS : au curseur.
 *
 * @param {MouseEvent} event le `contextmenu`
 * @param {HTMLElement} ligne l'élément qui porte le menu
 */
export function gesteDOuverture(event, ligne) {
    if (!contextmenuAuClavier(event.button, Date.now() - instantToucheDeMenu)) {
        return { point: { x: event.clientX, y: event.clientY } };
    }
    const sommaire = ligne.tagName === 'DETAILS' ? ligne.querySelector(':scope > summary') : null;
    let ancre = sommaire || ligne;
    // Une ligne CACHÉE relayée par ce qui est à l'écran (Vue Cartes : la carte focalisée renvoie
    // le geste à sa <tr> masquée) : sous l'élément focalisé, à défaut au point de l'événement.
    if (ancre.getClientRects().length === 0) {
        const actif = document.activeElement;
        if (!(actif instanceof HTMLElement) || actif === document.body || actif.getClientRects().length === 0) {
            return { point: { x: event.clientX, y: event.clientY }, viaClavier: true };
        }
        ancre = actif;
    }

    return { ancre: ancre.getBoundingClientRect(), alignement: 'gauche', viaClavier: true };
}

export class MenuContextuel {
    /**
     * @param {HTMLElement} menu l'élément du menu (rendu par le serveur, ou rempli par l'écran)
     * @param {{
     *   affichage?: 'display'|'hidden',
     *   surFermeture?: function(): void,
     * }} [options] `affichage` : comment le menu se montre — `style.display` (défaut, les menus
     *   `.context-menu`) ou l'attribut `hidden` (menu du chat) ; `surFermeture` : appelé à
     *   chaque fermeture, quelle qu'en soit la cause.
     */
    constructor(menu, options) {
        const o = options || {};
        this.menu = menu;
        this.affichage = o.affichage || 'display';
        this.surFermeture = o.surFermeture || null;
        this.retour = null;
        this.declencheur = null;

        this._surTouche = (e) => this._touche(e);
        this._surPointeur = (e) => {
            if (this.menu.contains(e.target)) return;
            // Le déclencheur (⋮) gère lui-même la bascule : son clic referme.
            if (this.declencheur && this.declencheur.contains(e.target)) return;
            // Le navigateur pose lui-même le focus là où l'on clique : on ne le reprend pas.
            this.fermer({ laisserLeFocus: true });
        };
        this._surDefilement = (e) => {
            // Un menu long défile sur lui-même sans se fermer.
            if (e.target instanceof Node && this.menu.contains(e.target)) return;
            this.fermer({ rendreLeFocus: false });
        };
        this._surRedimensionnement = () => this.fermer({ rendreLeFocus: false });
        // La touche Menu (ou un clic droit) DANS le menu ouvert : pas de menu natif par-dessus.
        // (Le menu vit au <body> ou dans son écran, hors des lignes : l'événement s'arrête là.)
        menu.addEventListener('contextmenu', (e) => {
            e.preventDefault();
            e.stopPropagation();
        });
    }

    estOuvert() {
        return ouvert === this;
    }

    /**
     * @param {{
     *   point?: {x: number, y: number},
     *   ancre?: {left: number, right: number, top: number, bottom: number},
     *   alignement?: 'gauche'|'droite',
     *   viaClavier?: boolean,
     *   retour?: HTMLElement|null,
     *   declencheur?: HTMLElement|null,
     * }} geste `point` : un clic droit (le menu s'ouvre à droite et sous le curseur) ; `ancre` :
     *   le rectangle d'un élément (dessous, aligné sur son bord `alignement`, droite par défaut).
     *   `viaClavier` : le focus va à la première entrée (sinon au menu : aucune entrée
     *   présélectionnée sous la souris, les flèches y entrent). `retour` : l'élément qui
     *   retrouve le focus à la fermeture (défaut : celui qui l'avait). `declencheur` : le
     *   bouton qui ouvre le menu (aria-expanded).
     */
    ouvrir(geste) {
        const g = geste || {};
        if (ouvert && ouvert !== this) ouvert.fermer({ rendreLeFocus: false });
        const dejaOuvert = ouvert === this;
        if (dejaOuvert) this._retirerLesEcouteurs();
        fermerInfobulle(); // deux surfaces flottantes ne se recouvrent pas

        // Rouvert ailleurs sans avoir été fermé : le focus est DANS le menu, l'origine reste
        // celle de la première ouverture (sinon il se perdrait dans le menu masqué).
        const candidat = g.retour || (document.activeElement instanceof HTMLElement ? document.activeElement : null);
        this.retour = dejaOuvert && candidat && this.menu.contains(candidat) ? this.retour : candidat;
        if (dejaOuvert && this.declencheur && this.declencheur !== g.declencheur) {
            this.declencheur.setAttribute('aria-expanded', 'false');
        }
        this.declencheur = g.declencheur || null;

        const menu = this.menu;
        this._montrer(true);
        menu.style.visibility = 'hidden'; // mesurable sans être visible
        const { left, top } = positionnerMenu({
            ancre: g.point ? ancreAuPoint(g.point) : g.ancre,
            menu: { largeur: menu.offsetWidth, hauteur: menu.offsetHeight },
            viewport: { largeur: window.innerWidth, hauteur: window.innerHeight },
            alignement: g.point ? 'gauche' : (g.alignement || 'droite'),
        });
        menu.style.left = `${left}px`;
        menu.style.top = `${top}px`;
        menu.style.visibility = '';

        if (this.declencheur) this.declencheur.setAttribute('aria-expanded', 'true');
        ouvert = this;

        const premiere = g.viaClavier ? this.entrees()[0] : null;
        if (premiere) {
            premiere.focus({ preventScroll: true });
        } else {
            if (!menu.hasAttribute('tabindex')) menu.setAttribute('tabindex', '-1');
            menu.focus({ preventScroll: true });
        }

        window.addEventListener('keydown', this._surTouche, true);
        document.addEventListener('pointerdown', this._surPointeur, true);
        window.addEventListener('scroll', this._surDefilement, true);
        window.addEventListener('resize', this._surRedimensionnement);
    }

    /**
     * @param {{rendreLeFocus?: boolean, laisserLeFocus?: boolean}} [options] le focus revient à
     *   l'élément d'origine s'il était resté dans le menu (Échap, entrée activée, défilement) ;
     *   `laisserLeFocus` : jamais — un clic ailleurs, où le navigateur pose lui-même le focus.
     */
    fermer(options) {
        if (ouvert !== this) return;
        const o = options || {};
        const rendre = o.rendreLeFocus !== false;
        const focusDansLeMenu = this.menu.contains(document.activeElement);
        ouvert = null;
        this._retirerLesEcouteurs();
        this._montrer(false);
        // Une infobulle posée sur une entrée (son `title`) part avec le menu (WCAG 1.4.13).
        fermerInfobulle(this.menu);
        if (this.declencheur) this.declencheur.setAttribute('aria-expanded', 'false');
        if (!o.laisserLeFocus && (rendre || focusDansLeMenu) && this.retour && this.retour.isConnected
            && (focusDansLeMenu || document.activeElement === document.body)) {
            this.retour.focus({ preventScroll: true });
        }
        this.retour = null;
        this.declencheur = null;
        if (this.surFermeture) this.surFermeture();
    }

    /** Les entrées navigables : `[role="menuitem"]` visibles. */
    entrees() {
        return Array.from(this.menu.querySelectorAll('[role="menuitem"]'))
            .filter((e) => e.getClientRects().length > 0 && !e.hidden);
    }

    _montrer(visible) {
        if (this.affichage === 'hidden') {
            this.menu.hidden = !visible;
        } else {
            this.menu.style.display = visible ? 'block' : 'none';
        }
    }

    _retirerLesEcouteurs() {
        window.removeEventListener('keydown', this._surTouche, true);
        document.removeEventListener('pointerdown', this._surPointeur, true);
        window.removeEventListener('scroll', this._surDefilement, true);
        window.removeEventListener('resize', this._surRedimensionnement);
    }

    _touche(event) {
        const entrees = this.entrees();
        const action = toucheDuMenu(event.key, entrees.indexOf(document.activeElement), entrees.length);
        if (!action) {
            // Espace ou Page↓ sur le menu (ouvert à la souris, aucune entrée focalisée) feraient
            // défiler la page — et le défilement ferme le menu.
            if (TOUCHES_DE_DEFILEMENT.includes(event.key) && this.menu.contains(document.activeElement)) event.preventDefault();
            return;
        }
        switch (action.type) {
            case 'aller':
                event.preventDefault();
                entrees[action.index].focus({ preventScroll: true });
                break;
            case 'activer':
                event.preventDefault();
                event.stopPropagation();
                // L'Espace d'un <button> joue au RELÂCHEMENT : l'entrée jouée ici, le focus rendu au
                // déclencheur (⋮), ce relâchement le rouvrirait. On l'avale.
                if (event.key === ' ') {
                    window.addEventListener('keyup', (e) => { if (e.key === ' ') e.preventDefault(); }, { capture: true, once: true });
                }
                // Une entrée inactive se lit (le focus s'y pose) mais ne se joue pas.
                if (entrees[action.index].getAttribute('aria-disabled') !== 'true') entrees[action.index].click();
                break;
            case 'fermer':
                event.preventDefault();
                event.stopPropagation();
                this.fermer();
                break;
            case 'sortir':
                // Le focus revient à l'origine, puis Tab l'emmène à l'élément suivant.
                this.fermer();
                break;
        }
    }
}
