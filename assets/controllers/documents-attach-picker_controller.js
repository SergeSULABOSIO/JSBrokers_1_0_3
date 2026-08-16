import PickerBaseController from './picker-base_controller.js';
import { trierLot, tailleLisible, extensionDe } from './documents-attach-lot.js';

/**
 * « ATTACHER DES PIÈCES » — la boîte ouverte depuis la barre d'outils ou le clic droit,
 * sur la ligne que l'utilisateur a sélectionnée.
 *
 * Hérite du socle des pickers (Échap, clic sur l'arrière-plan, restitution du focus,
 * zone d'erreur) : il ne reste ici que ce qui lui est propre — choisir des fichiers,
 * les montrer, les envoyer.
 *
 * POURQUOI UNE LISTE AVANT L'ENVOI. Le geste naturel est d'en déposer plusieurs, parfois
 * en deux fois, et de se raviser sur l'un d'eux. Envoyer à chaque dépôt priverait de ce
 * repentir ; montrer la liste puis n'envoyer qu'au clic laisse la main jusqu'au bout.
 */
export default class extends PickerBaseController {
    static pickerName = 'DOCUMENTS-ATTACH-PICKER';
    static values = { url: String, limites: Object, familles: Object };

    connect() {
        super.connect();
        this.fichiers = [];

        this.champ = this.element.querySelector('[data-attach-input]');
        this.zone = this.element.querySelector('[data-attach-drop]');
        this.liste = this.element.querySelector('[data-attach-liste]');
        this.vide = this.element.querySelector('[data-attach-vide]');
        this.bouton = this.element.querySelector('[data-attach-envoyer]');
        this.libelleBouton = this.element.querySelector('[data-attach-envoyer-libelle]');
        this.compte = this.element.querySelector('[data-attach-compte]');
        this.progression = this.element.querySelector('[data-picker-progress]');

        this.zone?.addEventListener('click', () => this.champ?.click());
        this.zone?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.champ?.click(); }
        });
        this.champ?.addEventListener('change', () => {
            this._ajouter([...(this.champ.files || [])]);
            // Remis à zéro : sans cela, redéposer le MÊME fichier après l'avoir retiré
            // n'émettrait aucun « change » et paraîtrait sans effet.
            this.champ.value = '';
        });

        ['dragenter', 'dragover'].forEach((type) => this.zone?.addEventListener(type, (e) => {
            e.preventDefault(); this.zone.classList.add('is-over');
        }));
        ['dragleave', 'drop'].forEach((type) => this.zone?.addEventListener(type, (e) => {
            e.preventDefault(); this.zone.classList.remove('is-over');
        }));
        this.zone?.addEventListener('drop', (e) => this._ajouter([...(e.dataTransfer?.files || [])]));

        this._rendre();
    }

    /** Retrait d'un fichier de la liste (le socle délègue ici les clics métier). */
    _onActionClick(event) {
        const bouton = event.target.closest('[data-attach-retirer]');
        if (bouton) {
            this.fichiers.splice(Number(bouton.dataset.attachRetirer), 1);
            this._rendre();
            return;
        }
        if (event.target.closest('[data-attach-envoyer]')) {
            this._envoyer();
        }
    }

    _ajouter(nouveaux) {
        const { retenus, refuses } = trierLot(nouveaux, this.fichiers, this.limitesValue || {});
        this.fichiers = [...this.fichiers, ...retenus];
        this._rendre(refuses);
    }

    /**
     * @param {Array<{nom: string, motif: string}>} refuses fichiers écartés à l'instant,
     *        montrés à côté des retenus : un fichier qui disparaît sans un mot laisse
     *        croire qu'il est parti.
     */
    _rendre(refuses = []) {
        if (!this.liste) return;

        this.liste.innerHTML = '';
        this.fichiers.forEach((fichier, index) => {
            this.liste.appendChild(this._ligne(fichier.name, tailleLisible(fichier.size), null, index));
        });
        refuses.forEach((refus) => {
            this.liste.appendChild(this._ligne(refus.nom, null, refus.motif, null));
        });

        const n = this.fichiers.length;
        if (this.vide) this.vide.hidden = n > 0 || refuses.length > 0;
        if (this.compte) this.compte.textContent = String(n);
        if (this.bouton) this.bouton.disabled = n === 0;
        if (this.libelleBouton) {
            this.libelleBouton.textContent = n > 1 ? `Attacher ${n} fichiers` : 'Attacher';
        }
    }

    /** Une ligne : icône de format, nom, puis taille (retenu) ou motif (refusé). */
    _ligne(nom, taille, motif, index) {
        const li = document.createElement('li');
        if (motif) li.classList.add('is-refuse');

        const icone = document.createElement('span');
        icone.className = 'jsb-attach-icone';
        icone.setAttribute('aria-hidden', 'true');
        icone.appendChild(this._icone(this._famille(nom)));
        li.appendChild(icone);

        const span = document.createElement('span');
        span.className = 'jsb-attach-nom';
        span.textContent = nom;                       // textContent : jamais d'HTML
        li.appendChild(span);

        const detail = document.createElement('span');
        detail.className = motif ? 'jsb-attach-motif' : 'jsb-attach-taille';
        detail.textContent = motif || taille || '';
        li.appendChild(detail);

        if (index !== null) {
            const retirer = document.createElement('button');
            retirer.type = 'button';
            retirer.className = 'jsb-attach-retirer';
            retirer.dataset.attachRetirer = String(index);
            retirer.setAttribute('aria-label', `Retirer ${nom}`);
            retirer.appendChild(this._icone(null, '[data-attach-icone-retirer]'));
            li.appendChild(retirer);
        }

        return li;
    }

    /**
     * La famille de format d'un nom de fichier, selon la table du SERVEUR
     * (SoaPoliceDocumentsCollector::familles()) : le fichier est classé pareil dans la
     * boîte de dépôt et sur la fiche une fois enregistré.
     */
    _famille(nom) {
        return (this.famillesValue || {})[extensionDe(nom)] || 'autre';
    }

    /**
     * L'icône, CLONÉE d'un gabarit rendu par le serveur.
     *
     * Les icônes du projet sont résolues par IconCanvasProvider, côté PHP : les écrire
     * ici en SVG ferait un second jeu d'icônes, qui cesserait de suivre le premier. Le
     * gabarit est déjà dans la page — aucun aller-retour, et aucune ligne sans icône.
     */
    _icone(famille, selecteur = null) {
        const gabarit = this.element.querySelector(selecteur || `[data-attach-icone="${famille}"]`);

        return gabarit ? gabarit.content.cloneNode(true) : document.createDocumentFragment();
    }

    async _envoyer() {
        if (this.fichiers.length === 0) return;

        this._showError(null);
        this.bouton.disabled = true;
        if (this.libelleBouton) this.libelleBouton.textContent = 'Envoi…';
        // Même retour visuel que les autres pickers pendant une opération réseau.
        this.progression?.classList.add('is-active');

        const formData = new FormData();
        this.fichiers.forEach((f) => formData.append('fichiers[]', f, f.name));

        try {
            const reponse = await fetch(this.urlValue, {
                method: 'POST',
                body: formData,
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
        this.bouton.disabled = this.fichiers.length === 0;
        this._rendre();
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
