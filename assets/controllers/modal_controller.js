import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

/**
 * @file Ce fichier contient le contrôleur Stimulus 'modal'.
 * @description Ce contrôleur générique gère le cycle de vie d'une modale Bootstrap.
 * Il s'occupe de l'affichage, de la fermeture, de l'auto-destruction de l'élément du DOM
 * et de l'ajustement du z-index pour les modales imbriquées.
 */

/**
 * @class ModalController
 * @extends Controller
 * @description Gère le cadre d'une boîte de dialogue (affichage, fermeture, z-index).
 */
export default class extends Controller {
    connect() {
        this.modal = new Modal(this.element);
        this.boundAdjustZIndex = this.adjustZIndex.bind(this);

        this.element.addEventListener('hidden.bs.modal', () => this.element.remove());
        this.element.addEventListener('shown.bs.modal', this.boundAdjustZIndex);

        this.show();
    }

    disconnect() {
        this.element.removeEventListener('shown.bs.modal', this.boundAdjustZIndex);
        // Pas besoin de cacher ou de détruire la modale ici, car 'hidden.bs.modal'
        // déclenche déjà sa suppression du DOM.
    }

    show() {
        this.modal.show();
    }

    hide() {
        this.modal.hide();
    }

    adjustZIndex() {
        // Tous les backdrops présents (avec ou sans .show) — le nouveau backdrop
        // peut ne pas avoir .show au moment où shown.bs.modal est émis.
        const backdrops = document.querySelectorAll('.modal-backdrop');
        if (backdrops.length === 0) return;

        // Z-index maximum parmi les autres modales déjà ouvertes.
        // On part de 1055 (défaut Bootstrap) pour le premier dialogue.
        const maxZIndex = Array.from(document.querySelectorAll('.modal.show'))
            .filter(modal => modal !== this.element)
            .reduce((max, modal) => {
                const z = parseInt(window.getComputedStyle(modal).zIndex, 10) || 0;
                return Math.max(max, z);
            }, 1055);

        // Appliquer en style inline pour écraser la règle CSS des backdrops imbriqués.
        const myBackdrop = backdrops[backdrops.length - 1];
        myBackdrop.style.zIndex = maxZIndex + 1;
        this.element.style.zIndex = maxZIndex + 2;
    }
}