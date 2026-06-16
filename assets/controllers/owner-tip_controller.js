import { Controller } from '@hotwired/stimulus';

/**
 * @class OwnerTipController
 * @description Infobulle sombre qui suit le curseur, sur le même principe que les
 * infobulles du tableau de bord du workspace (élément flottant ajouté au <body>,
 * repositionné à chaque mousemove). Posée sur l'indicateur de propriété d'une carte
 * d'entreprise. L'élément flottant est ajouté au <body> pour échapper au
 * `overflow: hidden` de la carte.
 *
 * Valeurs :
 *   • data-owner-tip-title-value : titre (blanc, gras)
 *   • data-owner-tip-body-value  : contenu (gris clair, non gras)
 */
export default class extends Controller {
    static values = { title: String, body: String };

    connect() {
        this._tip = null;

        this._onEnter = this._showFollow.bind(this);
        this._onMove  = this._follow.bind(this);
        this._onLeave = this._hide.bind(this);
        this._onFocus = this._showAtElement.bind(this);
        this._onBlur  = this._hide.bind(this);

        this.element.addEventListener('mouseenter', this._onEnter);
        this.element.addEventListener('mousemove', this._onMove);
        this.element.addEventListener('mouseleave', this._onLeave);
        this.element.addEventListener('focus', this._onFocus);
        this.element.addEventListener('blur', this._onBlur);
    }

    disconnect() {
        this._remove();
        this.element.removeEventListener('mouseenter', this._onEnter);
        this.element.removeEventListener('mousemove', this._onMove);
        this.element.removeEventListener('mouseleave', this._onLeave);
        this.element.removeEventListener('focus', this._onFocus);
        this.element.removeEventListener('blur', this._onBlur);
    }

    // ── Construction de l'élément flottant ──────────────────────────────────────
    _create() {
        if (this._tip) return this._tip;
        const tip = document.createElement('div');
        tip.className = 'ent-owner-floating-tip';
        tip.setAttribute('role', 'tooltip');

        const title = document.createElement('span');
        title.className = 'ent-owner-tip-title';
        title.textContent = this.titleValue;

        const body = document.createElement('span');
        body.className = 'ent-owner-tip-body';
        body.textContent = this.bodyValue;

        tip.appendChild(title);
        tip.appendChild(body);
        document.body.appendChild(tip);
        this._tip = tip;
        return tip;
    }

    // ── Survol souris : l'infobulle suit le curseur ─────────────────────────────
    _showFollow(event) {
        const tip = this._create();
        tip.style.display = 'block';
        this._follow(event);
    }

    _follow(event) {
        const tip = this._tip;
        if (!tip || tip.style.display === 'none') return;
        const offset = 12;
        let left = event.clientX + offset;
        let top  = event.clientY + offset;
        // On borne au viewport : bascule à gauche / au-dessus du curseur si nécessaire.
        if (left + tip.offsetWidth > window.innerWidth - 8) left = event.clientX - tip.offsetWidth - offset;
        if (top + tip.offsetHeight > window.innerHeight - 8) top = event.clientY - tip.offsetHeight - offset;
        if (left < 8) left = 8;
        if (top < 8) top = 8;
        tip.style.left = `${left}px`;
        tip.style.top  = `${top}px`;
    }

    // ── Focus clavier : l'infobulle s'ancre à l'élément (pas de curseur) ─────────
    _showAtElement() {
        const tip = this._create();
        tip.style.display = 'block';
        const r = this.element.getBoundingClientRect();
        let top = r.top - tip.offsetHeight - 8;
        if (top < 8) top = r.bottom + 8;
        let left = r.left;
        if (left + tip.offsetWidth > window.innerWidth - 8) left = window.innerWidth - tip.offsetWidth - 8;
        tip.style.left = `${Math.max(8, left)}px`;
        tip.style.top  = `${top}px`;
    }

    _hide() {
        if (this._tip) this._tip.style.display = 'none';
    }

    _remove() {
        if (this._tip) {
            this._tip.remove();
            this._tip = null;
        }
    }
}
