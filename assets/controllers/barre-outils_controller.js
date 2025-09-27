import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement } from './base_controller.js';

export default class extends Controller {
    static targets = [
        'btquitter', // Optionnel
        'btparametres', // Optionnel
        'btrecharger', // Optionnel
        'btajouter', // Optionnel
        'btmodifier', // Optionnel
        'btsupprimer', // Optionnel
        'bttoutcocher', // Optionnel
        'btouvrir', // Optionnel
    ];

    // NOUVEAU : Déclaration des paramètres pour la méthode notify()
    static params = ["eventName", "payload"];

    connect() {
        this.nomControleur = "BARRE-OUTILS";
        this.tabSelectedEntities = [];
        this.selectedEntitiesType = null;
        this.selectedEntitiesCanvas = null;
        console.log(this.nomControleur + " - Connecté");

        this.init();
    }

    init() {
        this.tabSelectedCheckBoxs = [];
        this.boundHandleContextUpdate = this.handleContextUpdate.bind(this);

        this.initialiserBarreDoutils();
        this.initToolTips();
        this.ecouteurs();
    }

    /**
     * La barre d'outils écoute uniquement le cerveau pour ajuster son état.
     */
    ecouteurs() {
        console.log(this.nomControleur + " - Activation des écouteurs d'évènements");
        document.addEventListener('ui:selection.changed', this.boundHandleContextUpdate);
    }

    disconnect() {
        console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs.");
        document.removeEventListener('ui:selection.changed', this.boundHandleContextUpdate);
    }

    /**
     * Gère la mise à jour du contexte reçue du cerveau (sélection, onglet actif, etc.).
     * @param {CustomEvent} event
     */
    handleContextUpdate(event) {
        console.log(this.nomControleur + " - Mise à jour du contexte reçue du Cerveau", event.detail);

        const { selection, entities, canvas, entityType, entityFormCanvas } = event.detail; // Récupère les données de l'événement
        // S'assurer que la sélection est toujours un tableau
        this.tabSelectedCheckBoxs = selection || [];
        this.tabSelectedEntities = entities;
        this.selectedEntitiesType = entityType;
        this.selectedEntitiesCanvas = canvas;
        this.formCanvas = entityFormCanvas; // CORRECTION : Stocker le canvas du formulaire

        //On réorganise les boutons en fonction de la selection actuelle
        this.organizeButtons(selection || []);

    }

    organizeButtons(selection) {
        // --- CORRECTION : S'assurer que selection est toujours un tableau ---
        if (!Array.isArray(selection)) {
            selection = [];
        }
        const hasSelection = selection.length > 0;
        const isSingleSelection = selection.length === 1;

        // On vérifie si la cible existe avant de manipuler son style
        if (this.hasBtmodifierTarget) this.btmodifierTarget.style.display = isSingleSelection ? "block" : "none";
        if (this.hasBtouvrirTarget) this.btouvrirTarget.style.display = hasSelection ? "block" : "none";
        if (this.hasBtsupprimerTarget) this.btsupprimerTarget.style.display = hasSelection ? "block" : "none";
    }

    initToolTips() {
        //On initialise le tooltips
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
    }

    initialiserBarreDoutils() {
        // On vérifie si la cible existe avant de manipuler son style
        if (this.hasBtquitterTarget) this.btquitterTarget.style.display = "block";
        if (this.hasBtparametresTarget) this.btparametresTarget.style.display = "block";
        if (this.hasBtajouterTarget) this.btajouterTarget.style.display = "block";
        if (this.hasBtrechargerTarget) this.btrechargerTarget.style.display = "block";
        if (this.hasBttoutcocherTarget) this.bttoutcocherTarget.style.display = "block";
    }

    /**
     * OPTIMISATION : Méthode unique pour gérer le clic sur tous les boutons.
     * Le nom de l'événement à notifier au cerveau est passé via un data-param.
     * Exemple dans le template :
     * data-action="click->barre-outils#notify"
     * data-barre-outils-event-name-param="ui:toolbar.add-request"
     */
    notify(event) {
        const button = event.currentTarget;
        const eventName = button.dataset.barreOutilsEventNameParam;

        if (!eventName) {
            console.error("Le bouton n'a pas de 'data-barre-outils-event-name-param' défini.", button);
            return;
        }

        let payload = {};
        // Cas spécial pour la suppression qui nécessite la sélection
        if (eventName === 'ui:toolbar.delete-request') {
            payload = { selection: this.tabSelectedCheckBoxs };
        }
        // Cas spécial pour l'ajout qui nécessite le canvas du formulaire
        else if (eventName === 'ui:toolbar.add-request') {
            payload = { entityFormCanvas: this.formCanvas };
        }
        // NOUVEAU : Cas spécial pour l'actualisation
        else if (eventName === 'ui:toolbar.refresh-request') {
            // Pas de payload spécifique nécessaire, l'événement lui-même est l'action.
            payload = {};
        }

        this.notifyCerveau(eventName, payload);
    }

    /**
     * Méthode centralisée pour envoyer un événement au cerveau.
     * @param {string} type Le type d'événement pour le cerveau (ex: 'ui:toolbar.add-request').
     * @param {object} payload Données additionnelles à envoyer.
     */
    notifyCerveau(type, payload = {}) {
        console.log(`${this.nomControleur} - Notification du cerveau: ${type}`, payload);
        buildCustomEventForElement(document, 'cerveau:event', true, true, {
            type: type,
            source: this.nomControleur,
            payload: payload,
            timestamp: Date.now()
        });
    }
}