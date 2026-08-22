import PickerBaseController from './picker-base_controller.js';
import { SelectionDeFichiers } from './attach-selection.js';

/**
 * « ATTACHER DES PIÈCES » — la boîte ouverte depuis la barre d'outils ou le clic droit,
 * sur la ligne que l'utilisateur a sélectionnée.
 *
 * Hérite du socle des pickers (Échap, clic sur l'arrière-plan, restitution du focus,
 * zone d'erreur) et délègue le CHOIX des fichiers à `SelectionDeFichiers`, partagé avec la
 * carte « Pièce justificative » du picker de reversement. Il ne reste donc ici que ce qui
 * lui est propre : l'ENVOI, immédiat, vers une cible qui existe déjà.
 */
export default class extends PickerBaseController {
    static pickerName = 'DOCUMENTS-ATTACH-PICKER';
    static values = { url: String, limites: Object, familles: Object };

    connect() {
        super.connect();

        this.bouton = this.element.querySelector('[data-attach-envoyer]');
        this.libelleBouton = this.element.querySelector('[data-attach-envoyer-libelle]');
        this.compte = this.element.querySelector('[data-attach-compte]');
        this.progression = this.element.querySelector('[data-picker-progress]');

        this.selection = new SelectionDeFichiers({
            racine: this.element,
            limites: this.limitesValue || {},
            familles: this.famillesValue || {},
            onChange: (n) => this._refleter(n),
        });
    }

    /** Ce que le nombre de fichiers change ICI : le compteur et le bouton d'envoi. */
    _refleter(n) {
        if (this.compte) this.compte.textContent = String(n);
        if (this.bouton) this.bouton.disabled = n === 0;
        if (this.libelleBouton) {
            this.libelleBouton.textContent = n > 1 ? `Attacher ${n} fichiers` : 'Attacher';
        }
    }

    /** Le socle délègue ici les clics métier : retrait d'une ligne, ou envoi. */
    _onActionClick(event) {
        if (this.selection.onClick(event)) {
            return;
        }
        if (event.target.closest('[data-attach-envoyer]')) {
            this._envoyer();
        }
    }

    async _envoyer() {
        if (this.selection.estVide()) return;

        this._showError(null);
        this.bouton.disabled = true;
        if (this.libelleBouton) this.libelleBouton.textContent = 'Envoi…';
        // Même retour visuel que les autres pickers pendant une opération réseau.
        this.progression?.classList.add('is-active');

        try {
            const reponse = await fetch(this.urlValue, {
                method: 'POST',
                body: this.selection.versFormData(),
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await reponse.json().catch(() => ({}));

            if (!reponse.ok) {
                // Le 402 (jetons épuisés) porte souvent des pièces DÉJÀ enregistrées :
                // les taire ferait recommencer un envoi partiellement payé.
                this._showError(data.error || "L'attachement a échoué.");
                if ((data.crees || []).length > 0) this._conclure(data);
                this._rendreLaMain();
                return;
            }

            this._conclure(data);
            this.close();
        } catch (e) {
            this._showError("L'attachement a échoué. Vérifiez votre connexion, puis réessayez.");
            this._rendreLaMain();
        }
    }

    /** Sortie d'échec : la boîte reste ouverte, et redevient utilisable. */
    _rendreLaMain() {
        this.progression?.classList.remove('is-active');
        this.selection.rendre();
    }

    /** Prévient le cerveau : notification à l'utilisateur + rafraîchissement de la liste. */
    _conclure(data) {
        this._notifyCerveau('documents:attaches', {
            crees: data.crees || [],
            refuses: data.refuses || [],
            cible: data.cible || '',
        });
    }
}
