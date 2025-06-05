// assets/controllers/dialogue_controller.js
import { Controller } from '@hotwired/stimulus';
// Assurez-vous que Bootstrap est bien importé ici
// import * as bootstrap from '../bootstrap'; // ou import { Modal } from 'bootstrap'; si vous voulez seulement Modal
import * as bootstrap from 'bootstrap'; // ou import { Modal } from 'bootstrap'; si vous voulez seulement Modal


export default class extends Controller {
    static targets = ['boite'];

    connect() {
        // Vérifiez que this.boiteTarget existe bien et est un élément DOM
        if (this.boiteTarget) {
            this.boite = new bootstrap.Modal(this.boiteTarget);
            console.log("Modal target: ", this.boite);
        } else {
            console.error("Modal target not found!");
        }
    }

    open() {
        if (this.boite) {
            this.boite.show();
        }
    }

    close() {
        if (this.boite) {
            this.boite.hide();
        }
    }
}