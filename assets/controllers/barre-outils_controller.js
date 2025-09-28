import { Controller } from '@hotwired/stimulus';

/**
 * @class BarreOutilsController
 * @extends Controller
 * @description Gère la barre d'outils principale de l'application.
 * Ce contrôleur écoute les changements de contexte (sélection) et ajuste l'état de ses boutons.
 * Il notifie le Cerveau des actions initiées par l'utilisateur.
 */
export default class extends Controller {
    /**
     * @property {HTMLElement[]} btquitterTargets - Le bouton pour quitter la rubrique.
     * @property {HTMLElement[]} btparametresTargets - Le bouton pour les paramètres.
     * @property {HTMLElement[]} btrechargerTargets - Le bouton pour recharger la liste.
     * @property {HTMLElement[]} btajouterTargets - Le bouton pour ajouter un élément.
     * @property {HTMLElement[]} btmodifierTargets - Le bouton pour modifier un élément.
     * @property {HTMLElement[]} btsupprimerTargets - Le bouton pour supprimer un ou plusieurs éléments.
     * @property {HTMLElement[]} bttoutcocherTargets - Le bouton pour tout cocher/décocher.
     * @property {HTMLElement[]} btouvrirTargets - Le bouton pour ouvrir un ou plusieurs éléments.
     */
    static targets = [
        'btquitter',
        'btparametres',
        'btrecharger',
        'btajouter',
        'btmodifier',
        'btsupprimer',
        'bttoutcocher',
        'btouvrir',
    ];

    /**
     * Méthode du cycle de vie de Stimulus.
     * S'exécute lorsque le contrôleur est connecté au DOM.
     */
    connect() {
        this.nomControleur = "BARRE-OUTILS";
        console.log(this.nomControleur + " - Connecté");
        this.init();
    }

    /**
     * Initialise les propriétés et les écouteurs d'événements.
     * @private
     */
    init() {
        this.tabSelectedCheckBoxs = [];
        this.tabSelectedEntities = [];
        this.formCanvas = null;

        this.boundHandleContextUpdate = this.handleContextUpdate.bind(this);

        this.initialiserBarreDoutils();
        this.ecouteurs();
    }

    /**
     * Met en place les écouteurs d'événements globaux.
     * La barre d'outils écoute uniquement le Cerveau pour ajuster son état.
     * @private
     */
    ecouteurs() {
        console.log(this.nomControleur + " - Activation des écouteurs d'évènements");
        document.addEventListener('ui:selection.changed', this.boundHandleContextUpdate);
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * Nettoie les écouteurs pour éviter les fuites de mémoire.
     */
    disconnect() {
        console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs.");
        document.removeEventListener('ui:selection.changed', this.boundHandleContextUpdate);
    }

    /**
     * Gère la mise à jour du contexte reçue du Cerveau (sélection, onglet actif, etc.).
     * @param {CustomEvent} event - L'événement `ui:selection.changed`.
     */
    handleContextUpdate(event) {
        console.log(this.nomControleur + " - Mise à jour du contexte reçue du Cerveau", event.detail);

        const { selection, entities, entityFormCanvas } = event.detail;

        this.tabSelectedCheckBoxs = selection || [];
        this.tabSelectedEntities = entities || [];
        this.formCanvas = entityFormCanvas;

        this.organizeButtons(selection || []);
    }

    /**
     * Affiche ou masque les boutons en fonction de la sélection actuelle.
     * @param {Array<string>} selection - Le tableau des IDs sélectionnés.
     * @private
     */
    organizeButtons(selection) {
        const hasSelection = selection.length > 0;
        const isSingleSelection = selection.length === 1;

        if (this.hasBtmodifierTarget) this.btmodifierTarget.style.display = isSingleSelection ? "block" : "none";
        if (this.hasBtouvrirTarget) this.btouvrirTarget.style.display = hasSelection ? "block" : "none";
        if (this.hasBtsupprimerTarget) this.btsupprimerTarget.style.display = hasSelection ? "block" : "none";
    }

    /**
     * Initialise l'état visible des boutons de la barre d'outils.
     * @private
     */
    initialiserBarreDoutils() {
        if (this.hasBtquitterTarget) this.btquitterTarget.style.display = "block";
        if (this.hasBtparametresTarget) this.btparametresTarget.style.display = "block";
        if (this.hasBtajouterTarget) this.btajouterTarget.style.display = "block";
        if (this.hasBtrechargerTarget) this.btrechargerTarget.style.display = "block";
        if (this.hasBttoutcocherTarget) this.bttoutcocherTarget.style.display = "block";
    }

    /**
     * Méthode générique pour notifier le Cerveau d'une action de la barre d'outils.
     * L'événement à envoyer est défini dans l'attribut `data-barre-outils-event-name-param` du bouton.
     * @param {MouseEvent} event - L'événement de clic.
     * @fires cerveau:event
     */
    notify(event) {
        const button = event.currentTarget;
        const eventName = button.dataset.barreOutilsEventNameParam;

        if (!eventName) {
            console.error("Le bouton n'a pas de 'data-barre-outils-event-name-param' défini.", button);
            return;
        }

        let payload = {};
        // Enrichit le payload en fonction de l'action demandée
        if (eventName === 'ui:toolbar.delete-request') {
            payload = { selection: this.tabSelectedCheckBoxs };
        } else if (eventName === 'ui:toolbar.add-request') {
            payload = { entityFormCanvas: this.formCanvas };
        } else if (eventName === 'ui:toolbar.edit-request' || eventName === 'ui:toolbar.open-request') {
            // --- CORRECTION : Ajouter le payload pour l'édition et l'ouverture ---
            payload = { entities: this.tabSelectedEntities };
        }

        this.notifyCerveau(eventName, payload);
    }

    /**
     * Méthode centralisée pour envoyer un événement au cerveau.
     * @param {string} type Le type d'événement pour le cerveau (ex: 'ui:toolbar.add-request').
     * @param {object} [payload={}] - Données additionnelles à envoyer.
     * @private
     */
    notifyCerveau(type, payload = {}) {
        console.log(`${this.nomControleur} - Notification du cerveau: ${type}`, payload);
        // --- CORRECTION : Utilisation de l'API native au lieu de la fonction importée ---
        this.dispatch('cerveau:event', {
            type: type,
            source: this.nomControleur,
            payload: payload,
            timestamp: Date.now()
        });
    }

    /**
     * Dispatche un événement personnalisé sur le document.
     * @param {string} name - Le nom de l'événement.
     * @param {object} [detail={}] - Les données à attacher à l'événement.
     * @private
     */
    dispatch(name, detail = {}) {
        document.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
    }
}