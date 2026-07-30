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
        idMessage: Number,
    };

    connect() {
        super.connect();
        this._categorie = '';
        this._onChangement = () => this._majSelection();
        this._onClavier = (event) => this._surClavier(event);
        this.element.addEventListener('change', this._onChangement);
        this.element.addEventListener('input', this._onChangement);
        this.element.addEventListener('keydown', this._onClavier);
        this._majSelection();
    }

    disconnect() {
        this.element.removeEventListener('change', this._onChangement);
        this.element.removeEventListener('input', this._onChangement);
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
        // `is-active` : classe d'état du composant chip partagé avec les filtres
        // rapides des listes (.jsb-preset-chip, app.css) — même rendu partout.
        this.element.querySelectorAll('[data-picker-categorie]').forEach((autre) => {
            const actif = autre === puce;
            autre.setAttribute('aria-pressed', actif ? 'true' : 'false');
            autre.classList.toggle('is-active', actif);
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

    // ── Sélection multiple ────────────────────────────────────────────────────

    /** Adresses cochées dans le carnet, dans l'ordre d'affichage. */
    _cochees() {
        return Array.from(this.element.querySelectorAll('input[name="aimsg-destinataire"]:checked'))
            .map((c) => c.value.trim())
            .filter((v) => v !== '');
    }

    /** Adresses saisies à la main : séparateurs virgule, point-virgule ou espace. */
    _saisies() {
        const brut = this.element.querySelector('[data-picker-email-libre]')?.value || '';
        return brut.split(/[,;\s]+/).map((v) => v.trim()).filter((v) => v !== '');
    }

    /** Destinataires retenus : carnet + saisie, dédoublonnés (le serveur revalide). */
    _destinataires() {
        const vues = new Set();
        return [...this._cochees(), ...this._saisies()].filter((email) => {
            const cle = email.toLowerCase();
            if (vues.has(cle)) return false;
            vues.add(cle);
            return true;
        });
    }

    /** Compte rendu permanent de la sélection (Nielsen 1 : l'état est visible). */
    _majSelection() {
        const zone = this.element.querySelector('[data-picker-selection]');
        if (!zone) return;
        const nombre = this._destinataires().length;
        zone.textContent = nombre === 0
            ? 'Aucun destinataire sélectionné.'
            : `${nombre} destinataire${nombre > 1 ? 's' : ''} sélectionné${nombre > 1 ? 's' : ''}.`;
    }

    // ── Clavier ───────────────────────────────────────────────────────────────

    /** ↑/↓ parcourt les lignes VISIBLES, Entrée envoie. */
    _surClavier(event) {
        if (event.key === 'Enter' && event.target.matches('[data-picker-search], [data-picker-email-libre]')) {
            event.preventDefault();
            this.element.querySelector('[data-picker-send]')?.click();
            return;
        }
        if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
        if (!event.target.matches('[data-picker-search], input[name="aimsg-destinataire"]')) return;

        const cases = this._casesVisibles();
        const suivant = indexApresTouche(event.key, cases.indexOf(document.activeElement), cases.length);
        if (suivant === null) return;
        event.preventDefault();
        // On déplace le FOCUS sans cocher : avec des cases à cocher, parcourir
        // n'est plus choisir — l'Espace coche, comme partout ailleurs.
        cases[suivant].focus();
    }

    _casesVisibles() {
        return Array.from(this.element.querySelectorAll('input[name="aimsg-destinataire"]'))
            .filter((c) => c.closest('[data-picker-row]')?.style.display !== 'none');
    }

    // ── Capture de la bulle (pièce jointe image) ──────────────────────────────

    /**
     * Capture la bulle du message en PNG base64. Le picker vit au <body>, hors du
     * chat : la bulle est retrouvée par son id dans le document, et le thème lu
     * sur la racine du chat (le contrôleur du chat y écrit le thème RÉSOLU, donc
     * jamais « auto »).
     *
     * @returns {Promise<string>} base64 nu, sans préfixe data:
     */
    async _capturerBulle() {
        const bulle = document.querySelector(`.jsb-ai-chat .aic-msg[data-message-id="${this.idMessageValue}"]`);
        if (!bulle) {
            throw new Error('Bulle du message introuvable.');
        }
        const theme = document.querySelector('.jsb-ai-chat')?.dataset.aicTheme || 'light';

        const { capturerBulle } = await import('./assistant-message-image.js');
        const blob = await capturerBulle(bulle, { theme });
        if (!blob) {
            throw new Error('Capture vide.');
        }

        return await new Promise((resolve, reject) => {
            const lecteur = new FileReader();
            lecteur.onerror = () => reject(lecteur.error);
            lecteur.onload = () => {
                const resultat = String(lecteur.result || '');
                const virgule = resultat.indexOf(',');
                resolve(virgule === -1 ? resultat : resultat.slice(virgule + 1));
            };
            lecteur.readAsDataURL(blob);
        });
    }

    // ── Envoi ─────────────────────────────────────────────────────────────────

    async _send(button) {
        if (!this.sendUrlValue || this.sendRunning) return;

        const emails = this._destinataires();
        if (emails.length === 0) {
            this._showError('Cochez au moins un destinataire, ou saisissez une adresse.');
            return;
        }

        let format = this.element.querySelector('[data-picker-format]:checked')?.value || '';
        const message = this.element.querySelector('[data-picker-message]')?.value || '';

        this.sendRunning = true;
        button.disabled = true;
        this._progress(true);
        this._showError(null);

        // Pièce jointe image : seul le navigateur sait rasteriser la bulle (les
        // graphiques Chart.js vivent dans un <canvas>). Repli sur le PDF si la
        // capture échoue — la mise en forme est préservée, seule la fidélité du
        // graphique se perd, et l'envoi n'est pas perdu.
        let image = null;
        let repliPdf = false;
        if (format === 'image') {
            try {
                image = await this._capturerBulle();
            } catch (error) {
                console.error('AssistantMessagePicker - capture échouée :', error);
                format = 'pdf';
                repliPdf = true;
            }
        }

        try {
            const response = await fetch(this.sendUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ emails, format: format || null, image, message }),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.success === false) {
                throw new Error(data.message || `Erreur serveur: ${response.status}`);
            }

            // Le chat écoute déjà `cerveau:event` : aucune plomberie nouvelle.
            const confirmation = data.message || 'Message envoyé.';
            this._notifyCerveau('assistant:message.envoye', {
                message: repliPdf
                    ? `${confirmation} L'image n'a pas pu être produite : le PDF a été joint à la place.`
                    : confirmation,
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
