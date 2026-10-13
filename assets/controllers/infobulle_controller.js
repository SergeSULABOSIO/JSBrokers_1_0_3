import { Controller } from '@hotwired/stimulus';
import { positionAuCurseur, positionSousLElement } from './infobulle-position.js';

/**
 * @class InfobulleController
 * @description L'INFOBULLE SOMBRE DU TABLEAU DE BORD, pour n'importe quel conteneur.
 *
 * Même rendu (la classe partagée `.jsb-ctx-tip`, cf. app.css — celle de #db-piste-tip et de
 * commit-tip) et même mouvement : un élément flottant au <body>, qui SUIT LE CURSEUR et
 * change de côté au bord de l'écran (infobulle-position.js).
 *
 * Posé sur un conteneur, il sert tout descendant `[data-infobulle]` — y compris ceux qui
 * arrivent après coup, comme les cartes clonées de la vue Cartes. Le contenu est DÉCLARÉ
 * par le serveur, en JSON :
 *   [{ "titre": "Piste", "lignes": [["Nom", "…"], ["Risque", "…", "tip-risque"]], "texte": "…" }]
 * et construit ici par `textContent` : un nom de piste n'est jamais du HTML. (Les
 * infobulles écrites à la main dans app.js passaient leurs valeurs par innerHTML.)
 *
 * Au clavier aussi (WCAG 1.4.13) : elle paraît au focus, sous l'élément, et Échap la ferme.
 */
export default class extends Controller {
    connect() {
        this._bulle = null;
        this._cible = null;

        this._surEntree = (e) => this._montrerAuPointeur(e);
        this._surSortie = (e) => this._quitter(e);
        this._surFocus = (e) => this._montrerAuClavier(e);
        this._surPerteDeFocus = (e) => this._quitter(e);
        this._surMouvement = (e) => this._suivre(e);
        this._surTouche = (e) => { if (e.key === 'Escape') this._cacher(); };

        this.element.addEventListener('mouseover', this._surEntree);
        this.element.addEventListener('mouseout', this._surSortie);
        this.element.addEventListener('focusin', this._surFocus);
        this.element.addEventListener('focusout', this._surPerteDeFocus);
        document.addEventListener('mousemove', this._surMouvement);
        document.addEventListener('keydown', this._surTouche);
    }

    disconnect() {
        this.element.removeEventListener('mouseover', this._surEntree);
        this.element.removeEventListener('mouseout', this._surSortie);
        this.element.removeEventListener('focusin', this._surFocus);
        this.element.removeEventListener('focusout', this._surPerteDeFocus);
        document.removeEventListener('mousemove', this._surMouvement);
        document.removeEventListener('keydown', this._surTouche);
        this._cacher();
        this._bulle?.remove();
        this._bulle = null;
    }

    _cibleDe(noeud) {
        const cible = noeud?.closest ? noeud.closest('[data-infobulle]') : null;
        return cible && this.element.contains(cible) ? cible : null;
    }

    _montrerAuPointeur(event) {
        const cible = this._cibleDe(event.target);
        if (!cible || cible === this._cible) return;
        if (this._montrer(cible)) this._placer(positionAuCurseur({ x: event.clientX, y: event.clientY }, this._taille(), this._ecran()));
    }

    _montrerAuClavier(event) {
        const cible = this._cibleDe(event.target);
        // Seulement l'élément LUI-MÊME : le focus d'un bouton de la carte n'appelle pas sa fiche.
        if (!cible || cible !== event.target) return;
        if (this._montrer(cible)) {
            this._placer(positionSousLElement(cible.getBoundingClientRect(), this._taille(), this._ecran()));
        }
    }

    _quitter(event) {
        if (!this._cible) return;
        if (event.relatedTarget && this._cible.contains(event.relatedTarget)) return;
        this._cacher();
    }

    _suivre(event) {
        if (!this._cible) return;
        // La liste a pu se rafraîchir sous le pointeur : la carte n'existe plus, aucun
        // `mouseout` ne viendra.
        if (!this._cible.isConnected) {
            this._cacher();
            return;
        }
        this._placer(positionAuCurseur({ x: event.clientX, y: event.clientY }, this._taille(), this._ecran()));
    }

    /** @returns {boolean} vrai si une infobulle est affichée */
    _montrer(cible) {
        let contenu;
        try {
            contenu = JSON.parse(cible.dataset.infobulle || '[]');
        } catch {
            return false;
        }
        if (!Array.isArray(contenu) || contenu.length === 0) return false;

        this._cacher();
        const bulle = this._creer();
        this._construire(bulle, contenu);
        bulle.style.display = 'block';
        this._cible = cible;
        cible.setAttribute('aria-describedby', bulle.id);

        return true;
    }

    _cacher() {
        this._cible?.removeAttribute('aria-describedby');
        this._cible = null;
        if (this._bulle) this._bulle.style.display = 'none';
    }

    _taille() {
        return { largeur: this._bulle.offsetWidth, hauteur: this._bulle.offsetHeight };
    }

    _ecran() {
        return { largeur: window.innerWidth, hauteur: window.innerHeight };
    }

    _placer({ left, top }) {
        this._bulle.style.left = `${left}px`;
        this._bulle.style.top = `${top}px`;
    }

    _creer() {
        if (this._bulle) return this._bulle;
        const bulle = document.createElement('div');
        bulle.className = 'jsb-ctx-tip';
        bulle.setAttribute('role', 'tooltip');
        bulle.id = `jsb-infobulle-${Math.random().toString(36).slice(2, 9)}`;
        document.body.appendChild(bulle);
        this._bulle = bulle;

        return bulle;
    }

    /** Sections → table `.tip-section` / lignes libellé-valeur / `.tip-para`, par textContent. */
    _construire(bulle, sections) {
        bulle.textContent = '';
        const table = document.createElement('table');
        const rangee = (cellules) => {
            const tr = document.createElement('tr');
            cellules.forEach(([texte, classe, colspan]) => {
                const td = document.createElement('td');
                td.textContent = texte;
                if (classe) td.className = classe;
                if (colspan) td.colSpan = colspan;
                tr.appendChild(td);
            });
            table.appendChild(tr);
        };

        sections.forEach((section) => {
            if (section?.titre) rangee([[String(section.titre), 'tip-section', 2]]);
            (section?.lignes || []).forEach(([libelle, valeur, classe]) => {
                rangee([[String(libelle ?? ''), null], [String(valeur ?? '—'), classe || null]]);
            });
            if (section?.texte) rangee([[String(section.texte), 'tip-para', 2]]);
        });
        bulle.appendChild(table);
    }
}
