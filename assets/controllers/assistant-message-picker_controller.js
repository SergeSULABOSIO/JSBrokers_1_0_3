import PickerBaseController from './picker-base_controller.js';
import { indexApresTouche } from './menu-flottant.js';

/**
 * Choix du destinataire pour l'envoi par e-mail d'UN message du chat Ket.
 *
 * Le HTML (_message_destinataire_picker.html.twig) est chargé et inséré par
 * `picker-open.js`, appelé depuis le chat ; ce contrôleur s'auto-connecte à
 * l'insertion. Le socle picker-base fournit le comportement de coque (focus,
 * fermeture, progression, zone d'erreur) ET la recherche plein texte
 * insensible aux accents ; ne restent ici que les trois spécificités de ce
 * carnet, qui peut compter des centaines de lignes :
 *
 *   - les puces de CATÉGORIE, greffées sur le point d'extension
 *     `_rowMatchesFilters()` (aucune duplication du filtrage) ;
 *   - la navigation ↑/↓ dans les lignes visibles, qui réutilise
 *     `indexApresTouche()` du menu de bulle ;
 *   - l'adresse HORS CARNET, saisie à la main.
 */
export default class extends PickerBaseController {
    static pickerName = 'ASSISTANT-MESSAGE-PICKER';

    static values = {
        sendUrl: String,
    };

    connect() {
        super.connect();
        this._categorie = '';
        this._onChangement = (event) => this._surChangement(event);
        this._onClavier = (event) => this._surClavier(event);
        this.element.addEventListener('change', this._onChangement);
        this.element.addEventListener('keydown', this._onClavier);
    }

    disconnect() {
        this.element.removeEventListener('change', this._onChangement);
        this.element.removeEventListener('keydown', this._onClavier);
        super.disconnect();
    }

    _onActionClick(event) {
        const puce = event.target.closest('[data-picker-categorie]');
        if (puce) {
            this._choisirCategorie(puce);
            return;
        }
        const boutonEnvoi = event.target.closest('[data-picker-send]');
        if (boutonEnvoi) {
            this._send(boutonEnvoi);
        }
    }

    // ── Filtrage par catégorie ────────────────────────────────────────────────

    _choisirCategorie(puce) {
        this._categorie = puce.dataset.pickerCategorie || '';
        this.element.querySelectorAll('[data-picker-categorie]').forEach((autre) => {
            const actif = autre === puce;
            autre.setAttribute('aria-pressed', actif ? 'true' : 'false');
            autre.classList.toggle('btn-primary', actif);
            autre.classList.toggle('btn-outline-secondary', !actif);
        });
        this._refilter();
    }

    /**
     * Critère non textuel combiné à la recherche par le socle. Les en-têtes de
     * groupe suivent leur propre catégorie : filtrer sur « Assureurs » ne doit
     * pas laisser flotter les intertitres des autres groupes.
     */
    _rowMatchesFilters(row) {
        return this._categorie === '' || row.dataset.categorie === this._categorie;
    }

    // ── Clavier et saisie libre ───────────────────────────────────────────────

    /** ↑/↓ parcourt les lignes VISIBLES, Entrée valide la sélection courante. */
    _surClavier(event) {
        if (event.key === 'Enter' && event.target.matches('[data-picker-search], [data-picker-email-libre]')) {
            event.preventDefault();
            this.element.querySelector('[data-picker-send]')?.click();
            return;
        }
        if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
        if (!event.target.matches('[data-picker-search], input[name="aimsg-destinataire"]')) return;

        const radios = this._radiosVisibles();
        const suivant = indexApresTouche(event.key, radios.indexOf(document.activeElement), radios.length);
        if (suivant === null) return;
        event.preventDefault();
        radios[suivant].focus();
        radios[suivant].checked = true;
        radios[suivant].dispatchEvent(new Event('change', { bubbles: true }));
    }

    _radiosVisibles() {
        return Array.from(this.element.querySelectorAll('input[name="aimsg-destinataire"]'))
            .filter((radio) => radio.closest('[data-picker-row], .form-check')?.style.display !== 'none');
    }

    /** Le champ d'adresse libre ne s'active qu'avec sa propre option. */
    _surChangement(event) {
        if (event.target.name !== 'aimsg-destinataire') return;
        const libre = this.element.querySelector('[data-picker-email-libre]');
        if (!libre) return;
        const modeLibre = this.element.querySelector('[data-picker-libre-radio]')?.checked === true;
        libre.disabled = !modeLibre;
        if (modeLibre) libre.focus();
    }

    // ── Envoi ─────────────────────────────────────────────────────────────────

    async _send(button) {
        if (!this.sendUrlValue || this.sendRunning) return;

        const choisi = this.element.querySelector('input[name="aimsg-destinataire"]:checked');
        if (!choisi) {
            this._showError("Choisissez d'abord un destinataire.");
            return;
        }
        const modeLibre = choisi.hasAttribute('data-picker-libre-radio');
        const email = modeLibre
            ? (this.element.querySelector('[data-picker-email-libre]')?.value || '').trim()
            : choisi.value;
        if (email === '') {
            this._showError('Saisissez une adresse e-mail.');
            return;
        }

        const format = this.element.querySelector('[data-picker-format]:checked')?.value || '';
        const message = this.element.querySelector('[data-picker-message]')?.value || '';

        this.sendRunning = true;
        button.disabled = true;
        this._progress(true);
        this._showError(null);
        try {
            const response = await fetch(this.sendUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ email, format: format || null, message }),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.success === false) {
                throw new Error(data.message || `Erreur serveur: ${response.status}`);
            }

            // Le chat écoute déjà `cerveau:event` : aucune plomberie nouvelle.
            this._notifyCerveau('assistant:message.envoye', {
                message: data.message || 'Message envoyé.',
            });
            this.close();
        } catch (error) {
            button.disabled = false;
            this._showError(error.message || "Envoi impossible. Réessayez ou vérifiez l'adresse du destinataire.");
        } finally {
            this.sendRunning = false;
            this._progress(false);
        }
    }
}
