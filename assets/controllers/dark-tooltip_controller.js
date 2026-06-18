import { Controller } from '@hotwired/stimulus';

/**
 * @class DarkTooltipController
 * @description Infobulle sombre déléguée, reprenant le pattern de celles du tableau
 * de bord du workspace (fond #212529, texte blanc, élément flottant ajouté au
 * <body> — cf. owner-tip_controller.js / ent-owner-floating-tip).
 *
 * Posé sur un conteneur (ex. la barre d'outils), il prend en charge TOUT élément
 * descendant porteur d'un attribut `title` — y compris les boutons injectés
 * dynamiquement (actions spécifiques de rubrique) — sans avoir à déclarer un
 * contrôleur par bouton.
 *
 * Le `title` natif est retiré au survol (puis restauré au départ) pour éviter le
 * double affichage avec l'infobulle native du navigateur. L'attribut reste donc
 * présent dans le HTML au repos : il sert de libellé source ET de repli accessible.
 */
export default class extends Controller {
    connect() {
        this._tip = null;
        this._current = null;

        this._onOver = this._handleOver.bind(this);
        this._onOut = this._handleOut.bind(this);
        this._onFocusIn = this._handleFocusIn.bind(this);
        this._onFocusOut = this._handleOut.bind(this);

        this.element.addEventListener('mouseover', this._onOver);
        this.element.addEventListener('mouseout', this._onOut);
        this.element.addEventListener('focusin', this._onFocusIn);
        this.element.addEventListener('focusout', this._onFocusOut);
    }

    disconnect() {
        this._restoreTitle(this._current);
        this._remove();
        this.element.removeEventListener('mouseover', this._onOver);
        this.element.removeEventListener('mouseout', this._onOut);
        this.element.removeEventListener('focusin', this._onFocusIn);
        this.element.removeEventListener('focusout', this._onFocusOut);
    }

    // ── Délégation : retrouve l'élément porteur d'un libellé ────────────────────
    _resolveTarget(node) {
        if (!node || typeof node.closest !== 'function') return null;
        // `[data-tip-stash]` couvre le cas où le title a déjà été consommé (réentrée).
        const el = node.closest('[title], [data-tip-stash]');
        return el && this.element.contains(el) ? el : null;
    }

    _handleOver(event) {
        const el = this._resolveTarget(event.target);
        if (!el || el === this._current) return;
        this._show(el);
    }

    _handleFocusIn(event) {
        const el = this._resolveTarget(event.target);
        if (!el || el === this._current) return;
        this._show(el);
    }

    _handleOut(event) {
        if (!this._current) return;
        // Ne masque pas si l'on entre dans un enfant de l'élément courant.
        const related = event.relatedTarget;
        if (related && this._current.contains(related)) return;
        this._hide();
    }

    // ── Affichage ───────────────────────────────────────────────────────────────
    _show(el) {
        // Restaure d'abord un éventuel élément précédent dont le title était consommé.
        if (this._current && this._current !== el) {
            this._restoreTitle(this._current);
        }

        const label = this._extractLabel(el);
        if (!label) {
            this._current = null;
            return;
        }

        this._current = el;
        const tip = this._create();
        tip.textContent = label;
        tip.style.display = 'block';

        // Mesure le tip une fois rendu, puis le centre sous l'élément (la barre
        // d'outils est en haut de page ; bascule au-dessus s'il manque de place).
        const r = el.getBoundingClientRect();
        const tipRect = tip.getBoundingClientRect();
        const margin = 8;

        let left = r.left + (r.width / 2) - (tipRect.width / 2);
        let top = r.bottom + margin;

        if (left < margin) left = margin;
        if (left + tipRect.width > window.innerWidth - margin) {
            left = window.innerWidth - tipRect.width - margin;
        }
        if (top + tipRect.height > window.innerHeight - margin) {
            top = r.top - tipRect.height - margin;
        }

        tip.style.left = `${left}px`;
        tip.style.top = `${top}px`;
        requestAnimationFrame(() => tip.classList.add('is-visible'));
    }

    _hide() {
        this._restoreTitle(this._current);
        this._current = null;
        if (this._tip) {
            this._tip.classList.remove('is-visible');
            this._tip.style.display = 'none';
        }
    }

    // ── Libellé : on consomme le `title` natif pour éviter le double tooltip ─────
    _extractLabel(el) {
        if (el.dataset.tipStash !== undefined) {
            return el.dataset.tipStash;
        }
        const title = el.getAttribute('title');
        if (title) {
            el.dataset.tipStash = title;
            el.removeAttribute('title');
            return title;
        }
        return el.getAttribute('aria-label') || '';
    }

    _restoreTitle(el) {
        if (el && el.dataset && el.dataset.tipStash !== undefined) {
            el.setAttribute('title', el.dataset.tipStash);
            delete el.dataset.tipStash;
        }
    }

    _create() {
        if (this._tip) return this._tip;
        const tip = document.createElement('div');
        tip.className = 'toolbar-dark-tip';
        tip.setAttribute('role', 'tooltip');
        document.body.appendChild(tip);
        this._tip = tip;
        return tip;
    }

    _remove() {
        if (this._tip) {
            this._tip.remove();
            this._tip = null;
        }
    }
}
