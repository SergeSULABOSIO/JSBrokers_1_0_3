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
        const backdrops = document.querySelectorAll('.modal-backdrop.show');
        if (backdrops.length <= 1) return;

        const modals = Array.from(document.querySelectorAll('.modal.show'));
        const maxZIndex = modals
            .filter(modal => modal !== this.element)
            .reduce((max, modal) => {
                const zIndex = parseInt(window.getComputedStyle(modal).zIndex, 10) || 0;
                return Math.max(max, zIndex);
            }, 1055); // 1055 est le z-index par défaut d'une modale Bootstrap

        const myBackdrop = backdrops[backdrops.length - 1];
        myBackdrop.style.zIndex = maxZIndex + 1;
        this.element.style.zIndex = maxZIndex + 2;
    }
}