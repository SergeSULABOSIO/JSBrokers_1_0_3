import { Controller } from '@hotwired/stimulus';

/**
 * @class ListRowController
 * @extends Controller
 * @description Gère les interactions d'une seule ligne (<tr>) dans une liste.
 * Il est responsable de la sélection de la ligne et de l'initiation du menu contextuel.
 */
export default class extends Controller {
    /**
     * @property {HTMLElement[]} texteprincipalTargets - La cible pour le texte principal de la ligne.
     * @property {HTMLElement[]} textesecondaireTargets - La cible pour le texte secondaire de la ligne.
     * @property {HTMLInputElement[]} checkboxTargets - La cible pour la case à cocher de la ligne.
     */
    static targets = [
        'texteprincipal',
        'textesecondaire',
        'checkbox'
    ];

    /**
     * @property {NumberValue} idobjetValue - L'ID unique de l'entité représentée par la ligne.
     */
    static values = {
        idobjet: Number,
    };

    /**
     * Méthode du cycle de vie de Stimulus.
     * Met en place les écouteurs d'événements pour le menu contextuel et la sélection.
     */
    connect() {
        this.nomControleur = "LIST-ROW";
        this.boundHandleContextMenu = this.handleContextMenu.bind(this);
        this.boundHandleCheckboxChange = this.handleCheckboxChange.bind(this);

        // Écoute le clic droit UNIQUEMENT sur sa propre ligne (this.element est le <tr>)
        this.element.addEventListener('contextmenu', this.boundHandleContextMenu);
        // Écoute le changement d'état de sa propre case à cocher
        this.checkboxTarget.addEventListener('change', this.boundHandleCheckboxChange);
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * Nettoie les écouteurs pour éviter les fuites de mémoire.
     */
    disconnect() {
        this.element.removeEventListener('contextmenu', this.boundHandleContextMenu);
        this.checkboxTarget.removeEventListener('change', this.boundHandleCheckboxChange);
    }

    /**
     * Gère le clic droit sur la ligne.
     * Empêche le menu par défaut et notifie le Cerveau pour ouvrir notre menu personnalisé.
     * @param {MouseEvent} event - L'événement de clic droit.
     * @fires cerveau:event
     */
    handleContextMenu(event) {
        event.preventDefault();
        event.stopPropagation();

        // S'assure que la ligne est cochée.
        if (!this.checkboxTarget.checked) {
            this.checkboxTarget.checked = true;
            // On notifie le changement de sélection pour que le Cerveau mette à jour son état.
            this.handleCheckboxChange(event);
        }

        // On demande l'ouverture du menu contextuel.
        // Le Cerveau a maintenant le bon état de sélection.
        this.notifyCerveau('ui:context-menu.request', {
            type: 'ui:context-menu.request',
            source: this.nomControleur,
            payload: { menuX: event.clientX, menuY: event.clientY },
            timestamp: Date.now()
        });
    }

    /**
     * Construit et retourne le payload "selecto" pour cette ligne.
     * Centralise la création de l'objet pour être réutilisé.
     * @returns {object|null} Le payload "selecto" ou null en cas d'erreur de validation.
     */
    buildSelectoPayload() {
        const numericDataRaw = this.element.closest('[data-list-manager-numeric-attributes-value]')?.dataset.listManagerNumericAttributesValue;
        const numericData = numericDataRaw ? JSON.parse(numericDataRaw) : {};

        const payload = {
            id: this.idobjetValue,
            isChecked: this.checkboxTarget.checked,
            entity: JSON.parse(this.checkboxTarget.dataset.entity || 'null'),
            entityType: this.checkboxTarget.dataset.entityType,
            entityCanvas: JSON.parse(this.checkboxTarget.dataset.canvas || 'null'),
            entityFormCanvas: JSON.parse(this.element.closest('[data-list-manager-entity-form-canvas-value]')?.dataset.listManagerEntityFormCanvasValue || 'null'),
            numericAttributes: numericData[this.idobjetValue] || {},
        };

        const invalidKeys = Object.entries(payload).filter(([key, value]) => value === null || value === undefined);
        if (invalidKeys.length > 0) {
            console.error(`[${this.nomControleur}] Erreur de validation: Le payload contient des valeurs nulles ou non définies.`, { clesInvalides: invalidKeys.map(([key]) => key), payloadComplet: payload });
            return null;
        }
        return payload;
    }

    /**
     * Gère le changement d'état de la case à cocher et notifie le Cerveau.
     * @param {Event} event - L'événement de changement.
     * @fires cerveau:event
     */
    handleCheckboxChange(event) {
        const checkbox = this.checkboxTarget;
        this.element.classList.toggle('row-selected', checkbox.checked);

        const payload = this.buildSelectoPayload();

        if (!payload) {
            return; // On arrête l'exécution ici.
        }

        this.dispatch('cerveau:event', {
            type: 'ui:list-row.selection-changed',
            source: this.nomControleur,
            payload: payload,
            timestamp: Date.now()
        });
    }

    /**
     * Gère le clic sur l'ensemble de la ligne pour (dé)sélectionner.
     * @param {MouseEvent} event
     */
    toggleSelection(event) {
        // Ne pas interférer si le clic était sur un élément interactif.
        if (event.target.closest('a, button, input, label')) {
            return;
        }
        // CORRECTION : Inverse l'état de la checkbox pour un feedback visuel immédiat.
        this.checkboxTarget.checked = !this.checkboxTarget.checked;
        this.checkboxTarget.dispatchEvent(new Event('change', { bubbles: true }));
    }

    /**
     * Dispatche un événement personnalisé sur le document.
     * @param {string} name - Le nom de l'événement.
     * @param {object} [detail={}] - Les données à attacher à l'événement.
     * @private
     */
    dispatch(name, detail = {}) {
        // L'événement est distribué depuis l'élément lui-même pour qu'il puisse remonter au Cerveau.
        this.element.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
    }

    /**
     * Méthode centralisée pour envoyer un événement au Cerveau.
     * @param {string} type Le type d'événement pour le Cerveau.
     * @param {object} [payload={}] - Données additionnelles à envoyer.
     * @private
     */
    notifyCerveau(type, payload = {}) {
        const event = new CustomEvent('cerveau:event', {
            bubbles: true,
            detail: { type, source: this.nomControleur, payload, timestamp: Date.now() }
        });
        this.element.dispatchEvent(event);
    }
}