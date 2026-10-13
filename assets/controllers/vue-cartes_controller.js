import { Controller } from '@hotwired/stimulus';
import {
    clicDeSelection, cleDeLaVue, cookieDeVue, doitDistribuer, messageDEtape, partsDuPipe, vueARestaurer,
} from './vue-cartes-regles.js';

/**
 * @class VueCartesController
 * @description LA VUE CARTES D'UNE LISTE : le pipe commercial des Pistes, en colonnes.
 *
 * Posé sur le MÊME élément que `list-manager`, et seulement quand le canevas de liste déclare
 * `vue_cartes`. Il ne remplace rien de la liste : la recherche, les chips, la pagination, la
 * sélection, la barre d'outils et le rafraîchissement restent ceux du `list-manager`. Il
 * présente autrement les lignes de la page.
 *
 * ── LE NAVIGATEUR NE FABRIQUE AUCUNE CARTE ──────────────────────────────────────────
 * Le serveur rend les cartes dans un `<template data-vue-cartes-source>` placé à la fin du
 * `<tbody>` (au premier rendu comme à chaque rafraîchissement). Ce contrôleur le CLONE dans
 * les colonnes. Un gabarit, une source ; aucune chaîne HTML ici.
 *
 * ── LA SÉLECTION RESTE CELLE DES LIGNES ─────────────────────────────────────────────
 * Cocher une carte coche la case de SA ligne (cachée en vue Cartes) et lui envoie `change` :
 * c'est list-manager qui notifie le cerveau, nourrit la barre d'outils, les totaux et les
 * onglets de collection. Il n'existe pas un second état de sélection à réconcilier.
 */
export default class extends Controller {
    static targets = ['tableau', 'bascule'];

    static values = {
        // Les étapes du pipe (libellés, raisons) — pour les messages après une action.
        etapes: Object,
    };

    connect() {
        this._surRendu = () => this.distribuer();
        this._surChargement = (e) => this._chargement(e);
        this._surEvenementCerveau = (e) => this._suivreUnEnregistrement(e);
        this._surOuvertureDeDialogue = (e) => this._suivreUneOuverture(e);
        this._surFermetureDeModale = (e) => this._quandLaFicheSeFerme(e);
        this._surChangement = (e) => this._refleterLaSelection(e);

        document.addEventListener('app:list.rendered', this._surRendu);
        document.addEventListener('app:loading.start', this._surChargement);
        // LE GRIS DU CHARGEMENT FINIT AVEC LE CHARGEMENT — pas avec la distribution d'une
        // source. Il ne s'éteignait qu'à l'arrivée de nouvelles cartes : un chargement qui
        // finissait sans elles (réponse en vue Liste, réponses croisées d'une bascule rapide,
        // échec réseau) laissait le pipe grisé, comme désactivé, au retour en Cartes.
        this._surFinDeChargement = () => this.hasTableauTarget && this.tableauTarget.removeAttribute('aria-busy');
        document.addEventListener('app:loading.stop', this._surFinDeChargement);
        // LA SÉLECTION POSÉE PAR PROGRAMME — restauration après F5, « Tout cocher » — coche
        // les cases des lignes sans `change` : la carte l'apprend par l'annonce du cerveau.
        // À SENS UNIQUE : on relit les cases, on n'émet rien ; aucune boucle possible.
        this._surContexte = () => this._refleterLaSelection();
        document.addEventListener('app:context.changed', this._surContexte);
        document.addEventListener('cerveau:event', this._surEvenementCerveau);
        document.addEventListener('app:boite-dialogue:init-request', this._surOuvertureDeDialogue);
        document.addEventListener('hidden.bs.modal', this._surFermetureDeModale);
        this.element.addEventListener('change', this._surChangement);

        this.suivi = null;
        this._appliquerLaVue(this._vueCourante());
        this.distribuer();
    }

    disconnect() {
        document.removeEventListener('app:list.rendered', this._surRendu);
        document.removeEventListener('app:loading.start', this._surChargement);
        document.removeEventListener('app:loading.stop', this._surFinDeChargement);
        document.removeEventListener('app:context.changed', this._surContexte);
        document.removeEventListener('cerveau:event', this._surEvenementCerveau);
        document.removeEventListener('app:boite-dialogue:init-request', this._surOuvertureDeDialogue);
        document.removeEventListener('hidden.bs.modal', this._surFermetureDeModale);
        this.element.removeEventListener('change', this._surChangement);
    }

    // ── LA VUE ─────────────────────────────────────────────────────────────────────

    _cle() {
        return cleDeLaVue(this.element.dataset.listManagerIdEntrepriseValue, this.element.dataset.listManagerEntiteValue);
    }

    _vueCourante() {
        return this.element.classList.contains('is-vue-liste') ? 'liste' : 'cartes';
    }

    _appliquerLaVue(vue) {
        const v = vueARestaurer(vue);
        this.element.classList.toggle('is-vue-cartes', v === 'cartes');
        this.element.classList.toggle('is-vue-liste', v === 'liste');
        this.basculeTargets.forEach((b) => b.setAttribute('aria-pressed', b.dataset.vue === v ? 'true' : 'false'));
        this.basculeTargets.forEach((b) => b.classList.toggle('is-active', b.dataset.vue === v));
        if (v === 'cartes') {
            this._montrerLeTableau();
        }
    }

    _memoriser(vue) {
        try {
            document.cookie = cookieDeVue(this._cle(), vue);
        } catch {
            // Sans cookie, la vue vaut pour la session de la page : rien d'autre ne casse.
        }
    }

    /**
     * Bascule Cartes ↔ Liste.
     *
     * Les cartes montrent le pipe ACTIF : passer en Cartes applique « En cours » par le chemin
     * normal du chip (cerveau, badge, persistance). Revenir en Liste rafraîchit : les pistes
     * clôturées, absentes des colonnes, y reparaissent avec leur marqueur.
     */
    basculer(event) {
        const vue = vueARestaurer(event.currentTarget.dataset.vue);
        if (vue === this._vueCourante()) {
            return;
        }
        this._memoriser(vue);
        this._appliquerLaVue(vue);
        if (vue === 'cartes') {
            this._appliquerLeStatut('en_cours');
        } else {
            this._rafraichir();
        }
    }

    /** « Voir les transformées » : la vue Liste, filtrée sur les Transformées. */
    voirLesTransformees() {
        this._memoriser('liste');
        this._appliquerLaVue('liste');
        this._appliquerLeStatut('transformees');
    }

    /** « Voir dans la liste » (pistes clôturées) : la vue Liste, même filtre. */
    voirDansLaListe() {
        this._memoriser('liste');
        this._appliquerLaVue('liste');
        this._rafraichir();
    }

    _appliquerLeStatut(valeur) {
        const chip = this.element.querySelector(`.jsb-preset-chip[data-criterion-value="${valeur}"]`);
        // Un chip déjà actif se DÉSACTIVERAIT au clic : on rafraîchit plutôt.
        if (!chip || chip.getAttribute('aria-pressed') === 'true') {
            this._rafraichir();

            return;
        }
        chip.click();
    }

    _rafraichir() {
        this._notifier('ui:toolbar.refresh-request', {});
    }

    // ── LA DISTRIBUTION ────────────────────────────────────────────────────────────

    _source() {
        return this.element.querySelector('template[data-vue-cartes-source]');
    }

    /**
     * Clone les cartes de la source dans leurs colonnes, et pose les compteurs.
     * Une source n'est distribuée qu'UNE fois (cf. doitDistribuer).
     */
    distribuer() {
        const source = this._source();
        if (!doitDistribuer(source) || !this.hasTableauTarget) {
            return;
        }
        source.dataset.distribue = 'oui';
        this.tableauTarget.removeAttribute('aria-busy');

        // LE SERVEUR A PU BASCULER LA VUE : un statut autre que « En cours » a été demandé
        // (recherche avancée, badge retiré, Ket). Il l'a rendue en Liste ; on s'y aligne.
        if (source.dataset.vueEffective) {
            this._appliquerLaVue(source.dataset.vueEffective);
        }

        this.tableauTarget.querySelectorAll('[data-cartes]').forEach((ul) => { ul.innerHTML = ''; });
        source.content.querySelectorAll('[data-carte]').forEach((carte) => {
            const colonne = this.tableauTarget.querySelector(`[data-colonne="${carte.dataset.etape}"] [data-cartes]`);
            colonne?.appendChild(carte.cloneNode(true));
        });

        source.content.querySelectorAll('data[data-etape]').forEach((d) => {
            const colonne = this.tableauTarget.querySelector(`[data-colonne="${d.dataset.etape}"]`);
            if (!colonne) return;
            const affichees = colonne.querySelectorAll('[data-carte]').length;
            const total = Number(d.value);
            colonne.querySelector('[data-total]').textContent = String(total);
            const somme = colonne.querySelector('[data-somme]');
            if (somme) somme.textContent = d.dataset.somme || '';
            const vide = colonne.querySelector('[data-vide]');
            if (vide) vide.hidden = affichees > 0;
            const pied = colonne.querySelector('[data-pied]');
            if (pied) {
                pied.hidden = total <= affichees;
                pied.textContent = `${affichees} affichée${affichees > 1 ? 's' : ''} sur ${total}`;
            }
        });

        // LA PART DE CHAQUE ÉTAPE, recalculée à chaque rendu sur les compteurs GLOBAUX.
        const nombres = Object.fromEntries([...source.content.querySelectorAll('data[data-etape]')]
            .map((d) => [d.dataset.etape, Number(d.value) || 0]));
        const parts = partsDuPipe(nombres);
        this.tableauTarget.querySelectorAll('[data-colonne]').forEach((colonne) => {
            const part = parts?.[colonne.dataset.colonne];
            const texte = colonne.querySelector('[data-part]');
            const jauge = colonne.querySelector('[data-jauge]');
            if (texte) {
                texte.hidden = !part;
                texte.textContent = part ? part.libelle : '';
                if (part) {
                    const precision = document.createElement('span');
                    precision.className = 'visually-hidden';
                    precision.textContent = ' des pistes du filtre';
                    texte.appendChild(precision);
                }
            }
            if (jauge) jauge.style.width = `${part ? part.pourcent : 0}%`;
        });

        const clotures = source.content.querySelector('data[data-cloturees]');
        const pied = this.tableauTarget.querySelector('[data-clotures]');
        if (pied) {
            const n = Number(clotures?.value || 0);
            pied.hidden = n === 0;
            pied.querySelector('[data-texte]').textContent = `${n} piste${n > 1 ? 's' : ''} clôturée${n > 1 ? 's' : ''} dans cette recherche — absente${n > 1 ? 's' : ''} du pipe.`;
        }

        this._refleterLaSelection();
        if (this._vueCourante() === 'cartes') {
            this._montrerLeTableau();
        }
    }

    /**
     * En vue Cartes, le tableau reste à l'écran même quand la page n'a aucune carte : les
     * compteurs globaux et la colonne « Transformées » ont encore quelque chose à dire.
     * list-manager, lui, cache le conteneur dès qu'il n'y a plus de ligne.
     */
    _montrerLeTableau() {
        const conteneur = this.element.querySelector('[data-list-manager-target="listContainer"]');
        const vide = this.element.querySelector('[data-list-manager-target="emptyStateContainer"]');
        conteneur?.classList.remove('d-none');
        vide?.classList.add('d-none');
    }

    _chargement(event) {
        if (event.detail?.originatorId === this.element.id && this.hasTableauTarget) {
            this.tableauTarget.setAttribute('aria-busy', 'true');
        }
    }

    // ── LA SÉLECTION : celle des lignes, reflétée ──────────────────────────────────

    _caseDeLaLigne(id) {
        return this.element.querySelector(`tr[data-item-id="${id}"] input[data-list-manager-target="rowCheckbox"]`);
    }

    _ligne(id) {
        return this.element.querySelector(`tbody [data-item-id="${id}"]`);
    }

    /** Un clic sur la carte (hors bouton) bascule sa sélection. */
    cliquer(event) {
        if (event.target.closest('button, a, input, label') || !clicDeSelection(event.detail)) {
            return;
        }
        const carte = event.currentTarget;
        const caseCarte = carte.querySelector('[data-case]');
        caseCarte.checked = !caseCarte.checked;
        this._selectionner(carte.dataset.id, caseCarte.checked);
    }

    /** La case de la carte (clavier : Espace) — elle pilote celle de la ligne. */
    cocher(event) {
        const carte = event.target.closest('[data-carte]');
        this._selectionner(carte.dataset.id, event.target.checked);
        event.stopPropagation();
    }

    _selectionner(id, coche) {
        const caseLigne = this._caseDeLaLigne(id);
        if (!caseLigne || caseLigne.checked === coche) {
            return;
        }
        caseLigne.checked = coche;
        caseLigne.dispatchEvent(new Event('change', { bubbles: true }));
    }

    _refleterLaSelection(event) {
        // Les changements de NOS cases sont déjà relayés ; on ne reflète que ceux des lignes.
        if (event && !event.target.matches?.('input[data-list-manager-target="rowCheckbox"]')) {
            return;
        }
        if (!this.hasTableauTarget) return;
        this.tableauTarget.querySelectorAll('[data-carte]').forEach((carte) => {
            const coche = this._caseDeLaLigne(carte.dataset.id)?.checked === true;
            carte.classList.toggle('is-selected', coche);
            const c = carte.querySelector('[data-case]');
            if (c) c.checked = coche;
        });
    }

    /** Entrée sur la carte : on l'ouvre (Espace est laissé à la case). */
    clavier(event) {
        if (event.key === 'Enter' && event.target === event.currentTarget) {
            event.preventDefault();
            this.ouvrir(event);
        }
    }

    /** Double-clic ou Entrée : la fiche de la piste, en édition — ou en consultation. */
    ouvrir(event) {
        if (event.target.closest?.('button, a, input')) {
            return;
        }
        const ligne = this._ligne(event.currentTarget.dataset.id);
        const controleurDeLigne = ligne && this.application.getControllerForElementAndIdentifier(ligne, 'list-row');
        const selecto = controleurDeLigne?.buildSelectoPayload();
        if (!selecto) return;

        let formCanvas = {};
        try {
            formCanvas = JSON.parse(this.element.dataset.listManagerEntityFormCanvasValue || '{}');
        } catch { /* canevas illisible : la consultation reste possible */ }
        const peutModifier = Boolean(formCanvas?.parametres?.endpoint_submit_url);

        this._notifier('ui:toolbar.edit-request', {
            selection: [selecto],
            formCanvas,
            context: peutModifier ? {} : { consultation: true },
        });
    }

    /** Clic droit : le menu contextuel de la LIGNE, à l'endroit du clic. */
    menu(event) {
        const ligne = this._ligne(event.currentTarget.dataset.id);
        if (!ligne) return;
        event.preventDefault();
        ligne.dispatchEvent(new MouseEvent('contextmenu', {
            bubbles: true, cancelable: true, clientX: event.clientX, clientY: event.clientY,
        }));
    }

    // ── L'ACTION D'UNE CARTE, ET CE QUI SUIT ───────────────────────────────────────

    /**
     * L'action primaire : le serveur décide, sur des données FRAÎCHES, quelle fiche ouvrir
     * et sur quel onglet (PisteController::actionPipe). La carte ne bouge pas : son étape se
     * lit sur les cotations, et c'est le rafraîchissement qui la rangera.
     */
    agir(event) {
        event.stopPropagation();
        const bouton = event.currentTarget;
        const carte = bouton.closest('[data-carte]');
        this.suivi = {
            piste: carte.dataset.id,
            avant: carte.dataset.etape,
            dialogues: new Set(),
            racine: null,
            enregistre: false,
            listeRafraichie: false,
        };
        this._notifier(bouton.dataset.evenement, { pisteId: Number(carte.dataset.id) });
    }

    _suivreUneOuverture(event) {
        const detail = event.detail || {};
        if (!this.suivi) return;
        const deLaCarte = String(detail.context?._vueCartes ?? '') === String(this.suivi.piste);
        const origine = detail.context?.originatorId ? document.getElementById(detail.context.originatorId) : null;
        const dansUnDialogueSuivi = Boolean(origine) && [...this.suivi.dialogues]
            .some((id) => document.getElementById(`modal-container-${id}`)?.contains(origine));
        if (!deLaCarte && !dansUnDialogueSuivi) return;

        // `dialogId` est posé par dialog-manager en traitant CE MÊME événement : on le lit
        // une fois la propagation terminée.
        queueMicrotask(() => {
            if (!this.suivi || !detail.dialogId) return;
            this.suivi.dialogues.add(detail.dialogId);
            if (deLaCarte && !this.suivi.racine) this.suivi.racine = detail.dialogId;
        });
    }

    _suivreUnEnregistrement(event) {
        const { type, payload } = event.detail || {};
        if (type !== 'app:entity.saved' || !this.suivi || !payload?.dialogId) return;
        if (!this.suivi.dialogues.has(payload.dialogId)) return;
        this.suivi.enregistre = true;
        // La fiche RACINE enregistrée rafraîchit déjà la liste (origine = la liste).
        if (payload.dialogId === this.suivi.racine && !String(payload.originatorId || '').startsWith('collection-')) {
            this.suivi.listeRafraichie = true;
        }
    }

    async _quandLaFicheSeFerme(event) {
        if (!this.suivi?.racine || event.target?.id !== `modal-container-${this.suivi.racine}`) return;
        const geste = this.suivi;
        this.suivi = null;
        if (!geste.enregistre) {
            return; // fermée sans rien écrire : ni rafraîchissement, ni commentaire.
        }
        if (!geste.listeRafraichie) {
            this._rafraichir();
        }
        let apres = null;
        try {
            const reponse = await fetch(`/admin/piste/api/etape/${geste.piste}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (reponse.ok) apres = (await reponse.json()).etape ?? null;
        } catch { /* sans réponse, pas de message plutôt qu'un message faux */ }
        const message = messageDEtape({ avant: geste.avant, apres, enregistre: true }, this.etapesValue);
        if (message) {
            document.dispatchEvent(new CustomEvent('app:notification.show', { detail: { text: message.texte, type: message.type } }));
        }
    }

    _notifier(type, payload) {
        this.element.dispatchEvent(new CustomEvent('cerveau:event', {
            bubbles: true,
            detail: { type, source: 'vue-cartes', payload, timestamp: Date.now() },
        }));
    }
}
