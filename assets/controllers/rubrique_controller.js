// assets/controllers/sales-list_controller.js
import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement, EVEN_MOTEUR_RECHERCHE_CRITERES_DEFINED, EVEN_MOTEUR_RECHERCHE_CRITERES_REQUEST, EVEN_MOTEUR_RECHERCHE_SEARCH_REQUEST } from './base_controller.js';

export default class extends Controller {
    static targets = ["listBody", "rowCheckbox", "selectAllCheckbox"];

    static values = {
        nom: String,
    }

    connect() {
        this.nomControleur = "RUBRIQUE - " + this.nomValue;
        console.log(this.nomControleur);
        // Ecoute l'évènement de demande des critères
        document.addEventListener(EVEN_MOTEUR_RECHERCHE_CRITERES_REQUEST, this.provideCriteria.bind(this));
        // Écoute l'événement de recherche pour mettre à jour la liste des ventes
        document.addEventListener(EVEN_MOTEUR_RECHERCHE_SEARCH_REQUEST, this.handleSearch.bind(this));
    }

    disconnect(){
        document.removeEventListener(EVEN_MOTEUR_RECHERCHE_CRITERES_REQUEST, this.provideCriteria.bind(this));
        document.removeEventListener(EVEN_MOTEUR_RECHERCHE_SEARCH_REQUEST, this.handleSearch.bind(this));
    }


    /**
     * Fournit la structure des critères à la barre de recherche.
     */
    provideCriteria(event) {
        console.log(this.nomControleur + " - Request for criteria received. Providing data...", event.detail);
        const criteriaDefinition = [
            { Nom: 'Nom du matériel', Type: 'Text', Valeur: '', isDefault: true },
            { Nom: 'Nom du client', Type: 'Text', Valeur: '', isDefault: false },
            { Nom: 'Montant facturé', Type: 'Number', Valeur: 0, isDefault: false },
            { Nom: 'Montant payé', Type: 'Number', Valeur: 0, isDefault: false },
            { Nom: 'Solde restant', Type: 'Number', Valeur: 0, isDefault: false },
            // Exemple pour un type 'Options'
            { Nom: 'Statut', Type: 'Options', Valeur: { 'paid': 'Payé', 'unpaid': 'Impayé' }, isDefault: false }
        ];

        // Émet l'événement de réponse avec les données
        this.dispatch(EVEN_MOTEUR_RECHERCHE_CRITERES_DEFINED, criteriaDefinition);
    }

    /**
     * Gère une requête de recherche (simple ou avancée).
     */
    handleSearch(event) {
        const { criteria } = event.detail;
        console.log(this.nomControleur + " - New search requested with criteria:", criteria);

        // Ici, vous feriez votre appel AJAX (fetch/Turbo) vers le serveur
        // pour filtrer et rafraîchir la liste des ventes.
        // Exemple:
        // const query = new URLSearchParams(criteria).toString();
        // Turbo.visit(`/ventes?${query}`);
    }
    
    /**
     * Dispatche un événement customisé sur la fenêtre.
     * @param {string} name Le nom de l'événement (ex: 'initialized')
     */
    dispatch(name, detail = {}) {
        buildCustomEventForElement(document, name, true, true, detail);
    }
}