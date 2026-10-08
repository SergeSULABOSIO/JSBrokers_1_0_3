/**
 * LE CIRCUIT D'ICÔNES, CÔTÉ DEMANDEUR — écrit une fois.
 *
 * Une icône d'action s'obtient du cerveau : on émet `ui:icon.request` (alias, taille,
 * identifiant de requête), il répond `app:icon.loaded` avec le SVG. La barre d'actions
 * (workspace et dialogue) et le menu contextuel le faisaient chacun avec son cache, son
 * écouteur et son injection — trois copies qui avaient commencé à diverger (identifiants
 * en double, réponses perdues quand deux boutons portaient la même icône).
 *
 * ── LES DEUX RÈGLES DU CIRCUIT ──────────────────────────────────────────────────────
 *  - l'écoute est posée DÈS la construction : la réponse du cerveau est différée, mais
 *    elle doit trouver l'écouteur en place ;
 *  - l'identifiant de requête est UNIQUE (préfixe + compteur) et valide comme sélecteur :
 *    deux porteurs de la même icône reçoivent chacun la leur.
 *
 * Le cache est au niveau du MODULE : toutes les surfaces demandent les mêmes alias.
 */

/** @type {Map<string, string>} alias => HTML du SVG */
const cacheIcones = new Map();

/** Compteur des identifiants de requête. */
let compteurRequetes = 0;

export class CircuitIcones {
    /**
     * @param {HTMLElement} emetteur Élément depuis lequel la requête remonte au cerveau.
     * @param {{prefixe?: string, habiller?: function(SVGElement): void}} [options]
     *        `habiller(svg)` adapte le SVG reçu à la surface (classe, couleur…).
     */
    constructor(emetteur, { prefixe = 'icone', habiller = () => {} } = {}) {
        this.emetteur = emetteur;
        this.prefixe = prefixe;
        this.habiller = habiller;
        this.boundIconeChargee = this._surIconeChargee.bind(this);
        document.addEventListener('app:icon.loaded', this.boundIconeChargee);
    }

    /**
     * Pose l'icône `alias` dans `cible` : tout de suite si elle est en cache, sinon à
     * l'arrivée de la réponse du cerveau.
     */
    poser(cible, alias, taille = 31) {
        if (!alias) return;
        if (cacheIcones.has(alias)) {
            this._injecter(cible, cacheIcones.get(alias));
            return;
        }
        cible.id = `${this.prefixe}-${++compteurRequetes}`;
        this._demander(alias, cible.id, taille);
    }

    /** Demande en avance les icônes absentes du cache. */
    precharger(alias) {
        (alias || []).forEach((nom) => {
            if (nom && !cacheIcones.has(nom)) this._demander(nom, `${this.prefixe}-precharge-${++compteurRequetes}`);
        });
    }

    /** Retire l'écoute : à appeler quand la surface se déconnecte. */
    detruire() {
        document.removeEventListener('app:icon.loaded', this.boundIconeChargee);
    }

    /** @private */
    _demander(alias, requesterId, taille = 31) {
        this.emetteur.dispatchEvent(new CustomEvent('cerveau:event', {
            bubbles: true,
            detail: {
                type: 'ui:icon.request',
                source: 'CircuitIcones',
                payload: { iconName: alias, iconSize: taille, requesterId },
                timestamp: Date.now(),
            },
        }));
    }

    /**
     * Réponse du cerveau : mise en cache dans tous les cas, et pose si la requête est
     * l'une des nôtres (le préfixe le dit, l'identifiant est unique dans le document).
     * @private
     */
    _surIconeChargee(event) {
        const { html, requesterId, iconName } = event.detail || {};
        if (!html || html.trim().startsWith('<!--')) return;
        if (iconName) cacheIcones.set(iconName, html);
        if (!requesterId || !requesterId.startsWith(`${this.prefixe}-`)) return;

        const cible = document.getElementById(requesterId);
        if (cible) this._injecter(cible, html);
    }

    /** Remplace le contenu de la cible par le SVG reçu, habillé pour la surface. @private */
    _injecter(cible, html) {
        const modele = document.createElement('template');
        modele.innerHTML = html.trim();
        const svg = modele.content.querySelector('svg');
        if (!svg) return;
        svg.setAttribute('aria-hidden', 'true');
        this.habiller(svg);
        cible.innerHTML = '';
        cible.appendChild(svg);
    }
}
