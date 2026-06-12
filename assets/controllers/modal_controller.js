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
        this.boundPreAdjust = this.preAdjustZIndex.bind(this);
        this.boundAdjustZIndex = this.adjustZIndex.bind(this);

        this.element.addEventListener('hidden.bs.modal', () => {
            this._restoreRemainingBackdrops();
            this.element.remove();
        });
        this.element.addEventListener('show.bs.modal', this.boundPreAdjust);
        this.element.addEventListener('shown.bs.modal', this.boundAdjustZIndex);

        this.show();
    }

    disconnect() {
        this.element.removeEventListener('show.bs.modal', this.boundPreAdjust);
        this.element.removeEventListener('shown.bs.modal', this.boundAdjustZIndex);
    }

    show() {
        this.modal.show();
    }

    hide() {
        this.modal.hide();
    }

    // Appelé sur 'show.bs.modal' (avant l'animation) : positionne la modale au-dessus
    // des modales déjà ouvertes avant même que Bootstrap crée le backdrop.
    preAdjustZIndex() {
        const openCount = document.querySelectorAll('.modal.show').length;
        this.element.style.zIndex = 1055 + openCount * 20;
    }

    // Appelé sur 'shown.bs.modal' (après l'animation) : réattribue les z-index à TOUTES
    // les modales et backdrops ouverts dans l'ordre du DOM, et masque les backdrops
    // inférieurs pour éviter l'effet "double opacité" qui rend le fond trop sombre.
    adjustZIndex() {
        // La modale de confirmation gère son propre z-index (toujours au sommet) :
        // on l'exclut du ré-empilement basé sur l'ordre du DOM, car elle est dans
        // base.html.twig AVANT les modales ajoutées dynamiquement au body.
        const allModals = Array.from(document.querySelectorAll('.modal.show'))
            .filter(modal => modal.id !== 'confirmation-dialog-modal');
        const allBackdrops = Array.from(document.querySelectorAll('.modal-backdrop'));

        allModals.forEach((modal, i) => {
            modal.style.zIndex = 1055 + i * 20;
        });
        allBackdrops.forEach((backdrop, i) => {
            backdrop.style.zIndex = 1050 + i * 20;
            // Seul le backdrop le plus haut est visible : les autres sont masqués
            // pour ne pas cumuler les opacités et assombrir excessivement le fond.
            backdrop.style.opacity = i === allBackdrops.length - 1 ? '' : '0';
        });

        // Si la confirmation est ouverte, elle reste au-dessus de la nouvelle pile.
        const confirmation = document.querySelector('#confirmation-dialog-modal.show');
        if (confirmation && allModals.length > 0) {
            confirmation.style.zIndex = 1055 + (allModals.length - 1) * 20 + 20;
        }
    }

    // Appelé à la fermeture : restaure l'opacité du backdrop inférieur (si existant).
    // À ce stade, Bootstrap a déjà supprimé le backdrop de CETTE modale du DOM.
    _restoreRemainingBackdrops() {
        document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
            backdrop.style.opacity = '';
        });
    }
}