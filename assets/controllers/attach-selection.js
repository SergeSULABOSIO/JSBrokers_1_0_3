/**
 * CHOISIR DES FICHIERS AVANT DE LES ENVOYER — le socle partagé des zones de dépôt.
 *
 * Deux écrans ont besoin du même geste : la boîte « Attacher des pièces » d'une fiche, et
 * la carte « Pièce justificative » du picker de reversement, où le bordereau de virement
 * se dépose au moment même du versement. Le tri et la validation vivaient déjà dans
 * `documents-attach-lot.js` ; le CÂBLAGE et le RENDU, eux, étaient prisonniers d'un
 * contrôleur. Les recopier aurait garanti la divergence — deux listes du même lot
 * finissent par refuser deux choses différentes.
 *
 * ── CE QUI RESTE À L'HÔTE ───────────────────────────────────────────────────────────
 * L'ENVOI. La boîte de la fiche poste tout de suite sur une cible qui existe ; le picker
 * de reversement, lui, ne connaît sa cible qu'APRÈS avoir créé le versement. Un socle qui
 * imposerait le moment de l'envoi ne servirait donc qu'à l'un des deux.
 *
 * ── POURQUOI UNE LISTE, ET PAS UN ENVOI À CHAQUE DÉPÔT ──────────────────────────────
 * Le geste naturel est d'en déposer plusieurs, parfois en deux fois, et de se raviser sur
 * l'un d'eux. Envoyer à chaque dépôt priverait de ce repentir.
 */
import { trierLot, tailleLisible, extensionDe } from './documents-attach-lot.js';

export class SelectionDeFichiers {
    /**
     * @param {object} config
     * @param {HTMLElement} config.racine - l'élément qui contient la zone et la liste.
     * @param {object} [config.limites] - extensions et taille maximale (du serveur).
     * @param {object} [config.familles] - extension => famille de format (du serveur).
     * @param {(nombre: number, refuses: Array) => void} [config.onChange] - appelé après
     *        chaque changement : c'est là que l'hôte règle son bouton et son compteur.
     */
    constructor({ racine, limites = {}, familles = {}, onChange = null }) {
        this.racine = racine;
        this.limites = limites || {};
        this.familles = familles || {};
        this.onChange = onChange;
        this.fichiers = [];

        this.champ = racine.querySelector('[data-attach-input]');
        this.zone = racine.querySelector('[data-attach-drop]');
        this.liste = racine.querySelector('[data-attach-liste]');
        this.vide = racine.querySelector('[data-attach-vide]');

        this._brancher();
        this.rendre();
    }

    /** Le lot retenu, dans l'ordre où il a été choisi. */
    lot() {
        return [...this.fichiers];
    }

    /** Y a-t-il de quoi envoyer ? La question que pose tout hôte avant de valider. */
    estVide() {
        return this.fichiers.length === 0;
    }

    /** Un FormData prêt pour la route générique d'attachement (champ `fichiers[]`). */
    versFormData() {
        const formData = new FormData();
        this.fichiers.forEach((f) => formData.append('fichiers[]', f, f.name));

        return formData;
    }

    /**
     * Traite un clic dans la zone. Rend true si le clic a été consommé, pour que l'hôte
     * n'ait pas à connaître nos sélecteurs.
     */
    onClick(event) {
        const bouton = event.target.closest('[data-attach-retirer]');
        if (bouton && this.racine.contains(bouton)) {
            this.fichiers.splice(Number(bouton.dataset.attachRetirer), 1);
            this.rendre();

            return true;
        }

        return false;
    }

    ajouter(nouveaux) {
        const { retenus, refuses } = trierLot(nouveaux, this.fichiers, this.limites);
        this.fichiers = [...this.fichiers, ...retenus];
        this.rendre(refuses);
    }

    /**
     * @param {Array<{nom: string, motif: string}>} refuses fichiers écartés à l'instant,
     *        montrés à côté des retenus : un fichier qui disparaît sans un mot laisse
     *        croire qu'il est parti.
     */
    rendre(refuses = []) {
        if (this.liste) {
            this.liste.innerHTML = '';
            this.fichiers.forEach((fichier, index) => {
                this.liste.appendChild(this._ligne(fichier.name, tailleLisible(fichier.size), null, index));
            });
            refuses.forEach((refus) => {
                this.liste.appendChild(this._ligne(refus.nom, null, refus.motif, null));
            });
        }

        if (this.vide) {
            this.vide.hidden = this.fichiers.length > 0 || refuses.length > 0;
        }
        this.onChange?.(this.fichiers.length, refuses);
    }

    /** @private */
    _brancher() {
        this.zone?.addEventListener('click', () => this.champ?.click());
        this.zone?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                this.champ?.click();
            }
        });
        this.champ?.addEventListener('change', () => {
            this.ajouter([...(this.champ.files || [])]);
            // Remis à zéro : sans cela, redéposer le MÊME fichier après l'avoir retiré
            // n'émettrait aucun « change » et paraîtrait sans effet.
            this.champ.value = '';
        });

        ['dragenter', 'dragover'].forEach((type) => this.zone?.addEventListener(type, (e) => {
            e.preventDefault();
            this.zone.classList.add('is-over');
        }));
        ['dragleave', 'drop'].forEach((type) => this.zone?.addEventListener(type, (e) => {
            e.preventDefault();
            this.zone.classList.remove('is-over');
        }));
        this.zone?.addEventListener('drop', (e) => this.ajouter([...(e.dataTransfer?.files || [])]));
    }

    /** Une ligne : icône de format, nom, puis taille (retenu) ou motif (refusé). @private */
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
     *
     * @private
     */
    _famille(nom) {
        return this.familles[extensionDe(nom)] || 'autre';
    }

    /**
     * L'icône, CLONÉE d'un gabarit rendu par le serveur.
     *
     * Les icônes du projet sont résolues par IconCanvasProvider, côté PHP : les écrire
     * ici en SVG ferait un second jeu d'icônes, qui cesserait de suivre le premier. Le
     * gabarit est déjà dans la page — aucun aller-retour, et aucune ligne sans icône.
     *
     * @private
     */
    _icone(famille, selecteur = null) {
        const gabarit = this.racine.querySelector(selecteur || `[data-attach-icone="${famille}"]`);

        return gabarit ? gabarit.content.cloneNode(true) : document.createDocumentFragment();
    }
}
