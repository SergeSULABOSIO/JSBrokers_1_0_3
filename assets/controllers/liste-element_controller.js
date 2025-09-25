import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement } from './base_controller.js';

export default class extends Controller {
    static targets = [
        'texteprincipal',
        'textesecondaire',
        'details', // Cible pour les détails de la ligne (doublon à nettoyer)
        'checkbox'  // NOUVEAU : Cible pour la case à cocher
    ];

    static values = {
        idobjet: Number,
        isShown: Boolean,
        objet: Object
    };

    connect() {
        this.nomControleur = "LISTE-ELEMENT";
        this.detailsVisible = false;
        // La cible pour le menu contextuel est l'élément du contrôleur lui-même (le <tr>)
        this.contextMenuTarget = document.getElementById("liste_row_" + this.idobjetValue);
        this.setEcouteurs();
    }

    setEcouteurs() {
        this.boundHandleContextMenu = this.handleContextMenu.bind(this);
        this.boundHandleCheckboxChange = this.handleCheckboxChange.bind(this);
        // Écoute le clic droit UNIQUEMENT sur sa propre ligne
        this.contextMenuTarget.addEventListener('contextmenu', this.boundHandleContextMenu);
        // NOUVEAU : Écoute le changement d'état de sa propre case à cocher
        this.checkboxTarget.addEventListener('change', this.boundHandleCheckboxChange);
    }

    disconnect() {
        // Nettoyage de l'écouteur
        this.contextMenuTarget.removeEventListener('contextmenu', this.boundHandleContextMenu);
        this.checkboxTarget.removeEventListener('change', this.boundHandleCheckboxChange);
    }

    /**
     * Gère le clic droit sur la ligne.
     * Empêche le menu par défaut et demande l'ouverture de notre menu personnalisé.
     */
    handleContextMenu(event) {
        event.preventDefault();
        event.stopPropagation();
        console.log(this.nomControleur + " - Clic droit détecté. Demande d'ouverture du menu contextuel.");

        // MODIFICATION : On envoie l'événement au cerveau
        buildCustomEventForElement(document, 'cerveau:event', true, true, {
            type: 'ui:context-menu.request',
            source: this.nomControleur,
            payload: {
                menuX: event.clientX, // Coordonnée X pour positionner le menu
                menuY: event.clientY, // Coordonnée Y pour positionner le menu
            },
            timestamp: Date.now()
        });
    }

    /**
     * NOUVEAU : Gère le changement d'état de la case à cocher et notifie le cerveau.
     */
    handleCheckboxChange(event) {
        const checkbox = this.checkboxTarget;
        console.log(`${this.nomControleur} - Case à cocher changée pour l'ID ${this.idobjetValue}. Nouvel état : ${checkbox.checked}. Notification du cerveau.`);
        
        // On envoie un événement au cerveau avec toutes les informations nécessaires
        // pour que les autres contrôleurs puissent reconstituer l'état de la sélection.
        buildCustomEventForElement(document, 'cerveau:event', true, true, {
            type: 'ui:list-item.selection-changed',
            source: this.nomControleur,
            payload: {
                id: this.idobjetValue,
                isChecked: checkbox.checked,
                entity: JSON.parse(checkbox.dataset.entity || '{}'),
                entityType: checkbox.dataset.entityType,
                canvas: JSON.parse(checkbox.dataset.canvas || '{}'),
            },
            timestamp: Date.now()
        });
    }

    /**
     * NOUVEAU : Gère le clic sur l'ensemble de la ligne pour (dé)sélectionner.
     * @param {MouseEvent} event 
     */
    toggleSelection(event) {
        // Cible de la case à cocher
        const checkbox = this.checkboxTarget;

        // Ne pas interférer si le clic était directement sur un lien, un bouton, ou la case elle-même.
        // L'événement 'change' sur la checkbox sera géré par `handleCheckboxChange`.
        const isInteractiveElement = event.target.closest('a, button, input, label');
        if (isInteractiveElement) {
            // Appliquer l'effet visuel même si on clique sur la checkbox
            if (event.target.type === 'checkbox') {
                this.element.classList.toggle('row-selected', event.target.checked);
            }
            return;
        }

        // Inverser l'état de la checkbox et déclencher manuellement l'événement "change"
        checkbox.checked = !checkbox.checked;
        checkbox.dispatchEvent(new Event('change', { bubbles: true }));
    }
}