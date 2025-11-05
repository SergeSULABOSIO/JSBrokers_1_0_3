import { Controller } from '@hotwired/stimulus';

/**
 * @class AccordionController
 * @extends Controller
 * @description Gère un composant accordéon, y compris le filtrage et le basculement des sections.
 */
export default class extends Controller {
    static targets = ["item", "searchInput", "noResultsMessage"];

    connect() {
        this.nomControleur = "Accordion";
        console.log(this.nomControleur + " - Code: 1986 - Connecté avec ID:", this.element.id);
    }

    
}