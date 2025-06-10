import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'texteprincipal',
        'textesecondaire',
        'details',
    ];

    connect() {
        this.detailsVisible = false;
    }


    /**
     * 
     * @param {MouseEvent} event 
     */
    afficherdetails = (event) => {
        event.preventDefault(); // Empêche la soumission classique du formulaire
        if (this.detailsVisible == true) {
            this.detailsTarget.style.display = "none";
            this.detailsVisible = false;
        } else {
            this.detailsTarget.style.display = "block";
            this.detailsVisible = true;
        }
    }
}