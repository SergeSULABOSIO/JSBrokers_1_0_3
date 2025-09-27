// assets/controllers/sales-list_controller.js
import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement } from './base_controller.js';

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
            {
                Nom: 'descriptionDeFait',
                Display: "Description des faits",
                Type: 'Text',
                Valeur: '',
                isDefault: true
            },
            {
                Nom: 'notifiedAt', // Le nom de l'attribut sur lequel on filtre la plage
                Display: "Date de notification", // Libellé principal affiché à l'utilisateur
                Type: 'DateTimeRange', // <-- NOUVEAU TYPE DE CRITÈRE : 'DateTimeRange'
                Valeur: { from: '', to: '' }, // Valeurs par défaut pour la plage
                isDefault: false
            },
            {
                Nom: 'referenceSinistre',
                Display: "Référence du sinistre",
                Type: 'Text',
                Valeur: '',
                isDefault: false
            },
            {
                Nom: 'referencePolice',
                Display: "Référence de la police",
                Type: 'Text',
                Valeur: '',
                isDefault: false
            },
            {
                Nom: 'dommage',
                Display: "Dommage",
                Type: 'Number',
                Valeur: 0,
                isDefault: false
            },
            {
                Nom: 'assure.nom',
                Display: "Client (assuré)",
                Type: 'Text',
                Valeur: '',
                isDefault: false
            },
            {
                Nom: 'assureur.nom',
                Display: "Assureur",
                Type: 'Text',
                Valeur: "",
                isDefault: false
            },
            {
                Nom: 'risque.code',
                Display: "Risque (Couverture)",
                Type: 'Options',
                Valeur: {
                    "imr": "INCENDIE ET RISQUES DIVERS",
                    "motor": "RC AUTOMOBILE",
                    "vie": "VIE ET EPARGNE",
                    "git": "TRANSPORT DES FACULTES",
                },
                isDefault: false
            },
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
        this.dispatch(EVEN_DATA_BASE_SELECTION_REQUEST, { criteria: criteria });
    }

    /**
     * Dispatche un événement customisé sur la fenêtre.
     * @param {string} name Le nom de l'événement (ex: 'initialized')
     */
    dispatch(name, detail = {}) {
        buildCustomEventForElement(document, name, true, true, detail);
    }
}