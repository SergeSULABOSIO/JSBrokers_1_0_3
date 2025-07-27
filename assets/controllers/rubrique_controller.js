// assets/controllers/sales-list_controller.js
import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement, EVEN_DATA_BASE_SELECTION_REQUEST, EVEN_LISTE_PRINCIPALE_REFRESH_REQUEST, EVEN_MOTEUR_RECHERCHE_CRITERES_DEFINED, EVEN_MOTEUR_RECHERCHE_CRITERES_REQUEST, EVEN_MOTEUR_RECHERCHE_SEARCH_REQUEST, EVEN_SHOW_TOAST } from './base_controller.js';

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

    disconnect() {
        document.removeEventListener(EVEN_MOTEUR_RECHERCHE_CRITERES_REQUEST, this.provideCriteria.bind(this));
        document.removeEventListener(EVEN_MOTEUR_RECHERCHE_SEARCH_REQUEST, this.handleSearch.bind(this));
    }


    /**
     * Fournit la structure des critères à la barre de recherche.
     */
    provideCriteria(event) {
        console.log(this.nomControleur + " - Request for criteria received. Providing data...", event.detail);
        const criteriaDefinition = [
            { Nom: 'descriptionDeFait', Type: 'Text', Valeur: '', isDefault: true },
            { Nom: 'referenceSinistre', Type: 'Text', Valeur: '', isDefault: false },
            { Nom: 'referencePolice', Type: 'Text', Valeur: '', isDefault: false },
            { Nom: 'dommage', Type: 'Number', Valeur: 0, isDefault: false },
            // { Nom: 'Risque', Type: 'Options', Valeur: { 'fap': 'INCENDIE ET RISQUES DIVERS', 'mtpl': 'RC AUTOMOBILE' }, isDefault: false },
            // { Nom: 'Status', Type: 'Options', Valeur: { 'closed': 'Clôturé', 'ongoing': 'En cours' }, isDefault: false },
            // { Nom: 'Assureur', Type: 'Options', Valeur: { 'Activa': 'ACTIVA', 'sfa': 'SFA', 'rawsursa': 'RAWSUR SA', 'sunu': 'SUNU' }, isDefault: false },
            // { Nom: 'Compensation à verser', Type: 'Number', Valeur: 0, isDefault: false },
        ];

        // Émet l'événement de réponse avec les données
        this.dispatch(EVEN_MOTEUR_RECHERCHE_CRITERES_DEFINED, criteriaDefinition);
    }

    /**
     * Gère une requête de recherche (simple ou avancée).
     */
    handleSearch(event) {
        const { criteria } = event.detail;
        console.log(this.nomControleur + " - handleSearch with criteria:", criteria);

        // Ici, vous feriez votre appel AJAX (fetch/Turbo) vers le serveur
        // pour filtrer et rafraîchir la liste des ventes.
        // Exemple:
        // const query = new URLSearchParams(criteria).toString();
        // Turbo.visit(`/ventes?${query}`);

        // const event = new CustomEvent(EVEN_DATA_BASE_SELECTION_REQUEST, {
        //     bubbles: true,
        //     detail: {
        //         entityName: 'NotificationSinistre', // Le nom de l'entité à interroger
        //         criteria: criteria
        //     }
        // });
        this.dispatch(EVEN_DATA_BASE_SELECTION_REQUEST, {
            // entityName: 'NotificationSinistre', // Le nom de l'entité à interroger
            criteria: criteria
        });
        // this.dispatch(EVEN_LISTE_PRINCIPALE_REFRESH_REQUEST);
    }

    /**
     * Dispatche un événement customisé sur la fenêtre.
     * @param {string} name Le nom de l'événement (ex: 'initialized')
     */
    dispatch(name, detail = {}) {
        buildCustomEventForElement(document, name, true, true, detail);
    }
}