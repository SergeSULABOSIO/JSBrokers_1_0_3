import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement } from './base_controller.js';

export default class extends Controller {
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
        document.addEventListener('ui:outils-dependants:ajuster', this.boundHandleContextUpdate);
    }

    disconnect() {
        console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs.");
        document.removeEventListener('ui:outils-dependants:ajuster', this.boundHandleContextUpdate);
    }

    /**
     * Gère la mise à jour du contexte reçue du cerveau (sélection, onglet actif, etc.).
     * @param {CustomEvent} event
     */
    handleContextUpdate(event) {
        console.log(this.nomControleur + " - Mise à jour du contexte reçue du Cerveau", event.detail);

        const { selection, entities, canvas, entityType } = event.detail; // Récupère les données de l'événement
        // S'assurer que la sélection est toujours un tableau
        this.tabSelectedCheckBoxs = selection || [];
        this.tabSelectedEntities = entities;
        this.selectedEntitiesType = entityType;
        this.selectedEntitiesCanvas = canvas;

        //On réorganise les boutons en fonction de la selection actuelle
        this.organizeButtons(selection || []);

    }

    organizeButtons(selection) {
        // --- CORRECTION : S'assurer que selection est toujours un tableau ---
        if (!Array.isArray(selection)) {
            selection = [];
        }
        if (selection.length >= 1) {
            if (selection.length == 1) {
                this.btmodifierTarget.style.display = "block";
            } else {
                this.btmodifierTarget.style.display = "none";
            }
            this.btouvrirTarget.style.display = "block";
            this.btsupprimerTarget.style.display = "block";
        } else {
            this.btouvrirTarget.style.display = "none";
            this.btmodifierTarget.style.display = "none";
            this.btsupprimerTarget.style.display = "none";
        }
    }

    initToolTips() {
        //On initialise le tooltips
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
    }

    initialiserBarreDoutils() {
        this.btquitterTarget.style.display = "block";
        this.btparametresTarget.style.display = "block";
        this.btajouterTarget.style.display = "block";
        this.btrechargerTarget.style.display = "block";
        this.bttoutcocherTarget.style.display = "block";
    }

    /**
     * LES ACTIONS
     */
    action_quitter() {
        this.notifyCerveau('ui:toolbar.close-request');
    }

    action_parametrer() {
        this.notifyCerveau('ui:toolbar.settings-request');
    }

    action_tout_cocher() {
        this.notifyCerveau('ui:toolbar.select-all-request');
    }

    action_ouvrir() {
        this.notifyCerveau('ui:toolbar.open-request');
    }

    action_recharger() {
        this.notifyCerveau('ui:toolbar.refresh-request');
    }

    action_ajouter() {
        this.notifyCerveau('ui:toolbar.add-request');
    }

    action_modifier() {
        this.notifyCerveau('ui:toolbar.modify-request');
    }

    action_supprimer() {
        this.notifyCerveau('ui:toolbar.delete-request', {
            selection: this.tabSelectedCheckBoxs
        });
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