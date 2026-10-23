import { Controller } from '@hotwired/stimulus';
import { positionAuCurseur, positionCentreeSous, positionSousLElement } from './infobulle-position.js';
import { lireSections, rangees } from './infobulle-contenu.js';

/**
 * @class InfobulleController
 * @description L'INFOBULLE UNIQUE DE L'APPLICATION (chantier 3, décisions de Serge du 2026-10-23).
 *
 * Posé sur un conteneur, il sert tout descendant porteur d'un contenu — y compris ceux qui
 * arrivent après coup (cartes clonées, lignes rafraîchies, boutons injectés) :
 *   - `source: donnees` (défaut) : `[data-infobulle]`, contenu DÉCLARÉ par le serveur en JSON
 *     (infobulle-contenu.js) ;
 *   - `source: titre` : `[title]` (barres d'actions) — le `title` natif est mis de côté pendant
 *     l'affichage, pour ne pas doubler la bulle, puis rendu.
 * Le contenu est posé par `textContent` : une donnée n'est jamais du HTML.
 *
 * Placement (`placement`) : `curseur` (défaut, le contenu) — POSÉE au point d'entrée du pointeur
 * puis IMMOBILE tant qu'on reste sur l'élément (arbitrage du 2026-10-23 : une bulle qui suit le
 * curseur recule quand on va vers elle) ; `bouton` — centrée sous l'élément (barres d'actions).
 * Au clavier, toujours sous l'élément.
 *
 * WCAG 1.4.13 :
 *   - SURVOLABLE : quitter l'élément laisse un court délai pour entrer dans la bulle ; elle reste
 *     tant que le pointeur est sur l'élément OU sur elle ;
 *   - REJETABLE : Échap la ferme sans bouger ni pointeur ni focus ;
 *   - PERSISTANTE : aucune minuterie ne la ferme pendant le survol ou le focus ;
 *   - reliée à son élément par `aria-describedby`.
 *
 * UNE SEULE BULLE À L'ÉCRAN : l'élément flottant (au <body>, hors des `overflow: hidden`) est
 * partagé par toutes les instances ; en montrer une cache la précédente.
 */

/** Délai pour passer de l'élément à la bulle (ms). */
const GRACE = 300;

const etat = {
    bulle: null,
    cible: null,
    proprietaire: null,
    minuterie: null,
    parLeClavier: false,
};

/**
 * Une infobulle est-elle affichée ? Lu par le gestionnaire des dialogues : le premier Échap
 * ferme la bulle, le second le dialogue (echap-dialogue.js).
 */
export function infobulleOuverte() {
    return !!(etat.bulle && !etat.bulle.hidden && etat.cible);
}

function bulle() {
    if (etat.bulle && etat.bulle.isConnected) return etat.bulle;
    const b = document.createElement('div');
    b.className = 'jsb-ctx-tip est-survolable';
    b.setAttribute('role', 'tooltip');
    b.id = 'jsb-infobulle';
    b.hidden = true;
    b.addEventListener('mouseenter', () => clearTimeout(etat.minuterie));
    b.addEventListener('mouseleave', (e) => {
        if (etat.cible && e.relatedTarget && etat.cible.contains(e.relatedTarget)) return;
        differerLaFermeture();
    });
    document.body.appendChild(b);
    etat.bulle = b;

    return b;
}

function differerLaFermeture() {
    clearTimeout(etat.minuterie);
    etat.minuterie = setTimeout(fermer, GRACE);
}

function fermer() {
    clearTimeout(etat.minuterie);
    const cible = etat.cible;
    if (cible) {
        cible.removeAttribute('aria-describedby');
        if (cible.dataset.infobulleTitre !== undefined) {
            cible.setAttribute('title', cible.dataset.infobulleTitre);
            delete cible.dataset.infobulleTitre;
        }
    }
    etat.cible = null;
    etat.proprietaire = null;
    etat.parLeClavier = false;
    if (etat.bulle) etat.bulle.hidden = true;
}

function construire(b, sections) {
    b.textContent = '';
    const table = document.createElement('table');
    for (const { cellules } of rangees(sections)) {
        const tr = document.createElement('tr');
        for (const { texte, classe, colspan } of cellules) {
            const td = document.createElement('td');
            td.textContent = texte;
            if (classe) td.className = classe;
            if (colspan > 1) td.colSpan = colspan;
            tr.appendChild(td);
        }
        table.appendChild(tr);
    }
    b.appendChild(table);
}

export default class extends Controller {
    static values = {
        placement: { type: String, default: 'curseur' },
        source: { type: String, default: 'donnees' },
    };

    connect() {
        this._surEntree = (e) => this._entrer(e);
        this._surSortie = (e) => this._sortir(e);
        this._surFocus = (e) => this._focus(e);
        this._surPerteDeFocus = (e) => this._perteDeFocus(e);
        this._surTouche = (e) => {
            if (etat.proprietaire !== this) return;
            // L'élément a disparu sous un utilisateur au clavier (liste rafraîchie) : aucun
            // `focusout` fiable, aucune souris pour le voir — la touche suivante ferme la bulle.
            if (etat.cible && !etat.cible.isConnected) {
                fermer();
                return;
            }
            if (e.key === 'Escape' || e.key === 'Esc') {
                // Rejetable (WCAG 1.4.13) — et cet Échap s'arrête là : il n'a fermé QUE la bulle.
                fermer();
                e.preventDefault();
                e.stopPropagation();
            }
        };
        this._surMouvement = () => {
            // La liste a pu se rafraîchir sous le pointeur : l'élément n'existe plus, aucun
            // `mouseout` ne viendra.
            if (etat.proprietaire === this && etat.cible && !etat.cible.isConnected) fermer();
        };

        this.element.addEventListener('mouseover', this._surEntree);
        this.element.addEventListener('mouseout', this._surSortie);
        this.element.addEventListener('focusin', this._surFocus);
        this.element.addEventListener('focusout', this._surPerteDeFocus);
        document.addEventListener('keydown', this._surTouche);
        document.addEventListener('mousemove', this._surMouvement, { passive: true });
    }

    disconnect() {
        this.element.removeEventListener('mouseover', this._surEntree);
        this.element.removeEventListener('mouseout', this._surSortie);
        this.element.removeEventListener('focusin', this._surFocus);
        this.element.removeEventListener('focusout', this._surPerteDeFocus);
        document.removeEventListener('keydown', this._surTouche);
        document.removeEventListener('mousemove', this._surMouvement);
        if (etat.proprietaire === this) fermer();
    }

    // ── Qui porte une infobulle ───────────────────────────────────────────────

    _selecteur() {
        return this.sourceValue === 'titre' ? '[title], [data-infobulle-titre]' : '[data-infobulle]';
    }

    _cibleDe(noeud) {
        const cible = noeud?.closest ? noeud.closest(this._selecteur()) : null;
        return cible && this.element.contains(cible) ? cible : null;
    }

    _sections(cible) {
        if (this.sourceValue !== 'titre') return lireSections(cible.dataset.infobulle);
        const libelle = cible.dataset.infobulleTitre ?? cible.getAttribute('title') ?? '';
        return libelle.trim() === '' ? [] : [{ titre: null, lignes: [], texte: libelle, classe: null }];
    }

    // ── Gestes ────────────────────────────────────────────────────────────────

    _entrer(event) {
        // Zones imbriquées (une barre d'actions dans une carte) : la plus proche de l'élément
        // survolé l'emporte — le `mouseover` remonte d'abord par elle.
        if (event.infobulleTraitee) return;
        const cible = this._cibleDe(event.target);
        if (!cible) return;
        event.infobulleTraitee = true;
        if (cible === etat.cible) {
            clearTimeout(etat.minuterie); // retour sur l'élément pendant la grâce
            return;
        }
        if (!this._montrer(cible)) return;
        this._placer(this.placementValue === 'bouton'
            ? positionCentreeSous(cible.getBoundingClientRect(), this._taille(), this._ecran())
            : positionAuCurseur({ x: event.clientX, y: event.clientY }, this._taille(), this._ecran()));
    }

    _sortir(event) {
        if (etat.proprietaire !== this || !etat.cible) return;
        // Seule la sortie de l'ÉLÉMENT compte : la souris qui bouge ailleurs dans le conteneur
        // ne ferme pas une bulle ouverte par le clavier (persistante, WCAG 1.4.13).
        if (!etat.cible.contains(event.target)) return;
        const vers = event.relatedTarget;
        if (vers && (etat.cible.contains(vers) || etat.bulle?.contains(vers))) return;
        // Tant que l'élément garde le focus clavier, la bulle reste.
        if (etat.parLeClavier && document.activeElement === etat.cible) return;
        differerLaFermeture();
    }

    _focus(event) {
        if (event.infobulleTraitee) return;
        const cible = this._cibleDe(event.target);
        // Seulement l'élément LUI-MÊME : le focus d'un bouton DANS une carte n'appelle pas la fiche.
        if (!cible || cible !== event.target) return;
        event.infobulleTraitee = true;
        // Un focus du CLAVIER seulement : un clic sur une carte focalisable, ou le focus rendu à
        // la fermeture d'un dialogue, ne fait pas sauter la bulle sous l'élément.
        if (!this._focusClavier(cible)) return;
        if (cible === etat.cible) {
            etat.parLeClavier = true; // ouverte à la souris, désormais tenue par le focus
            return;
        }
        if (this._montrer(cible)) {
            etat.parLeClavier = true;
            const cadre = cible.getBoundingClientRect();
            this._placer(this.placementValue === 'bouton'
                ? positionCentreeSous(cadre, this._taille(), this._ecran())
                : positionSousLElement(cadre, this._taille(), this._ecran()));
        }
    }

    _focusClavier(cible) {
        try {
            return cible.matches(':focus-visible');
        } catch {
            return true; // navigateur sans :focus-visible — on montre plutôt que de cacher
        }
    }

    _perteDeFocus(event) {
        if (etat.proprietaire !== this || event.target !== etat.cible) return;
        fermer();
    }

    // ── Affichage ─────────────────────────────────────────────────────────────

    /** @returns {boolean} vrai si une infobulle est affichée */
    _montrer(cible) {
        const sections = this._sections(cible);
        if (sections.length === 0) return false;

        fermer();
        const b = bulle();
        construire(b, sections);
        if (this.sourceValue === 'titre' && cible.hasAttribute('title')) {
            cible.dataset.infobulleTitre = cible.getAttribute('title');
            cible.removeAttribute('title');
        }
        b.hidden = false;
        etat.cible = cible;
        etat.proprietaire = this;
        cible.setAttribute('aria-describedby', b.id);

        return true;
    }

    _taille() {
        return { largeur: etat.bulle.offsetWidth, hauteur: etat.bulle.offsetHeight };
    }

    _ecran() {
        return { largeur: window.innerWidth, hauteur: window.innerHeight };
    }

    _placer({ left, top }) {
        etat.bulle.style.left = `${left}px`;
        etat.bulle.style.top = `${top}px`;
    }
}
