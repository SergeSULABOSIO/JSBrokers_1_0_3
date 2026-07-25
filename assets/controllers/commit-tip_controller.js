import { Controller } from '@hotwired/stimulus';

/**
 * @class CommitTipController
 * @description Infobulle sombre au survol de la version / du nom « JS Brokers » :
 * annonce le DERNIER commit effectué (transparence sur les mises à jour).
 *
 * Reprend À L'IDENTIQUE le pattern des infobulles du tableau de bord (bloc Pistes
 * `#db-piste-tip`) et des puces du chat IA (`.jsb-ctx-tip`) : élément flottant
 * ajouté au <body>, fond sombre, table `.tip-section` (titre) + `.tip-libelle`
 * (corps), qui SUIT LE CURSEUR. On réutilise la classe partagée `jsb-ctx-tip`
 * pour hériter exactement du même style (cf. app.css).
 *
 * Posé sur un conteneur, il déclenche l'infobulle pour tout descendant marqué
 * `[data-commit-tip]` (délégation, DRY). Les données du commit sont lues sur
 * l'élément du contrôleur (valeurs Stimulus posées par le serveur).
 */
export default class extends Controller {
    static values = {
        ref: String,
        date: String,
        subject: String,
    };

    connect() {
        this._tip = null;
        this._active = false;

        this._onOver = this._handleOver.bind(this);
        this._onOut = this._handleOut.bind(this);
        this._onMove = this._handleMove.bind(this);

        this.element.addEventListener('mouseover', this._onOver);
        this.element.addEventListener('mouseout', this._onOut);
        document.addEventListener('mousemove', this._onMove);
    }

    disconnect() {
        this.element.removeEventListener('mouseover', this._onOver);
        this.element.removeEventListener('mouseout', this._onOut);
        document.removeEventListener('mousemove', this._onMove);
        if (this._tip) {
            this._tip.remove();
            this._tip = null;
        }
    }

    _handleOver(event) {
        const cible = event.target.closest ? event.target.closest('[data-commit-tip]') : null;
        if (!cible || !this.element.contains(cible)) return;
        const tip = this._create();
        this._build(tip);
        tip.style.display = 'block';
        this._active = true;
    }

    _handleOut(event) {
        const cible = event.target.closest ? event.target.closest('[data-commit-tip]') : null;
        if (cible && !cible.contains(event.relatedTarget)) {
            if (this._tip) this._tip.style.display = 'none';
            this._active = false;
        }
    }

    _handleMove(event) {
        if (!this._active || !this._tip) return;
        const t = this._tip;
        const offset = 10;
        let left = event.clientX - t.offsetWidth - offset;
        let top = event.clientY - t.offsetHeight - offset;
        if (left < 8) left = event.clientX + offset;
        if (top < 8) top = event.clientY + offset;
        t.style.left = `${left}px`;
        t.style.top = `${top}px`;
    }

    _create() {
        if (this._tip) return this._tip;
        const tip = document.createElement('div');
        // `jsb-ctx-tip` = style partagé ; `commit-tip` = crochet local (titre en blanc).
        tip.className = 'jsb-ctx-tip commit-tip';
        tip.setAttribute('role', 'tooltip');
        document.body.appendChild(tip);
        this._tip = tip;
        return tip;
    }

    /** Titre = « réf — date » (tip-section) ; corps = sujet du commit (tip-libelle). */
    _build(tip) {
        tip.textContent = '';
        const table = document.createElement('table');

        const titre = document.createElement('tr');
        const tdTitre = document.createElement('td');
        tdTitre.setAttribute('colspan', '2');
        tdTitre.className = 'tip-section';
        tdTitre.textContent = `${this.refValue} — ${this.dateValue}`;
        titre.appendChild(tdTitre);
        table.appendChild(titre);

        if (this.subjectValue) {
            const corps = document.createElement('tr');
            const tdCorps = document.createElement('td');
            tdCorps.setAttribute('colspan', '2');
            tdCorps.className = 'tip-libelle';
            tdCorps.textContent = this.subjectValue;
            corps.appendChild(tdCorps);
            table.appendChild(corps);
        }

        tip.appendChild(table);
    }
}
